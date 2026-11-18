<?php
// Usage: php patch_ci_system.php <system-dir> [--index=<path/to/index.php>] [--check] [--force]
//
// Applies the PHP 8.2-8.5 compatibility patch to a stock CodeIgniter 3.1.13 system/ folder.
// Idempotent: running it twice changes nothing the second time.
//   --check   report what is missing, change nothing (exit 1 if anything is missing)
//   --index   also remove E_STRICT from the front controller's error_reporting() calls
//   --force   allow a CI version other than 3.1.13 (not recommended: upgrade system/ first)
// Every edit is a no-op on PHP 7.x: #[...] is a comment there, ?array works from 7.1.

$opts = array('check' => false, 'force' => false, 'index' => null);
$dir = null;
foreach (array_slice($argv, 1) as $a) {
    if ($a === '--check') $opts['check'] = true;
    elseif ($a === '--force') $opts['force'] = true;
    elseif (strpos($a, '--index=') === 0) $opts['index'] = substr($a, 8);
    elseif ($dir === null) $dir = $a;
}
if (!$dir || !is_file("$dir/core/CodeIgniter.php")) {
    fwrite(STDERR, "usage: php patch_ci_system.php <system-dir> [--index=index.php] [--check] [--force]\n");
    exit(2);
}
$dir = rtrim(str_replace('\\', '/', $dir), '/');

preg_match('/CI_VERSION\s*=\s*[\'"]([^\'"]+)/', file_get_contents("$dir/core/CodeIgniter.php"), $m);
$ver = $m[1] ?? '?';
echo "CI_VERSION $ver\n";
if ($ver !== '3.1.13' && !$opts['force']) {
    fwrite(STDERR, "Expected 3.1.13. Replace system/ with stock 3.1.13 first (see the apply skill), or pass --force.\n");
    exit(2);
}

// [file, search regex, replacement, "already applied" regex, description]
$edits = array();
$attrClasses = array(
    'core/Controller.php'     => 'class CI_Controller',
    'core/Loader.php'         => 'class CI_Loader',
    'core/Model.php'          => 'class CI_Model',
    'core/Router.php'         => 'class CI_Router',
    'core/URI.php'            => 'class CI_URI',
    'database/DB_driver.php'  => 'abstract class CI_DB_driver',
    'libraries/Table.php'     => 'class CI_Table',
    'libraries/Driver.php'    => 'class CI_Driver_Library',  // Cache and other driver libraries add children as properties
    'libraries/Profiler.php'  => 'class CI_Profiler',        // sets undeclared $_compile_* flags
);
foreach ($attrClasses as $file => $decl) {
    $q = preg_quote($decl, '/');
    $edits[] = array($file, '/^(' . $q . '\b)/m', "#[AllowDynamicProperties]\n$1", '/#\[AllowDynamicProperties\]\s*\n' . $q . '\b/', "#[AllowDynamicProperties] on $decl (8.2 dynamic properties)");
}
$edits[] = array('core/Exceptions.php', '/\bE_STRICT(\s*=>\s*[\'"]Runtime Notice)/', '2048$1', '/\b2048\s*=>\s*[\'"]Runtime Notice/', 'E_STRICT key -> 2048 (8.4 deprecated constant)');
$edits[] = array('libraries/Session/Session.php',
    '/^([ \t]*)if \(\(\$bits = \$sid_length \* \$bits_per_character\) < 160\)/m',
    "$1// PHP 8.4 deprecated changing session.sid_length; keep its default (128 bits) there\n$1if ((\$bits = \$sid_length * \$bits_per_character) < 160 && ! is_php('8.4'))",
    "/< 160 && ! is_php\('8\.4'\)\)/",
    'skip ini_set(session.sid_length) on 8.4+ (deprecated ini)');
$edits[] = array('libraries/Encryption.php', '/function (encrypt|decrypt)\(\$data, array \$params = NULL\)/', 'function $1($data, ?array $params = NULL)', '/function decrypt\(\$data, \?array \$params = NULL\)/', '?array $params in encrypt()/decrypt() (8.4 implicit nullable)');
$edits[] = array('libraries/Xmlrpcs.php', '/"multicall_\$\{err\}"/', '"multicall_{$err}"', '/"multicall_\{\$err\}"/', '${err} -> {$err} (8.2 interpolation)');

$missing = 0; $changed = 0;
foreach ($edits as list($file, $search, $replace, $done, $desc)) {
    $path = "$dir/$file";
    if (!is_file($path)) { echo "SKIP  $file not found\n"; continue; }
    $src = file_get_contents($path);
    if (preg_match($done, $src)) { echo "ok    $desc\n"; continue; }
    if (!preg_match($search, $src)) { echo "WARN  $desc: anchor not found in $file (modified system/? check by hand)\n"; $missing++; continue; }
    if ($opts['check']) { echo "TODO  $desc\n"; $missing++; continue; }
    $new = preg_replace($search, $replace, $src);
    file_put_contents($path, $new);
    echo "done  $desc\n";
    $changed++;
}

if ($opts['index']) {
    $ip = $opts['index'];
    if (!is_file($ip)) { echo "WARN  index file $ip not found\n"; $missing++; }
    else {
        $src = file_get_contents($ip);
        if (strpos($src, 'E_STRICT') === false) echo "ok    index.php has no E_STRICT\n";
        elseif ($opts['check']) { echo "TODO  remove E_STRICT from $ip\n"; $missing++; }
        else { file_put_contents($ip, preg_replace('/\s*&\s*~E_STRICT\b/', '', $src)); echo "done  removed E_STRICT from $ip\n"; $changed++; }
    }
}

echo $opts['check'] ? ($missing ? "patch INCOMPLETE ($missing)\n" : "patch complete\n") : "changed $changed, problems $missing\n";
exit($missing ? 1 : 0);
