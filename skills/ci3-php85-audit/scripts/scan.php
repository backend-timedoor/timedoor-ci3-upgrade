<?php
// Usage: php scan.php <project-root> [--max=40]
// Read-only PHP 8.x risk scan for a CodeIgniter 3 project. Run it with the PHP 8.5 binary.
// Every hit is a candidate: read the line before deciding. Prints plain text sections.

$root = null; $max = 40;
foreach (array_slice($argv, 1) as $a) {
    if (strpos($a, '--max=') === 0) $max = (int) substr($a, 6);
    elseif ($root === null) $root = $a;
}
if (!$root || !is_dir($root)) { fwrite(STDERR, "usage: php scan.php <project-root> [--max=40]\n"); exit(2); }
$root = rtrim(str_replace('\\', '/', realpath($root)), '/');

function rel($p) { global $root; $p = str_replace('\\', '/', $p); return strpos($p, $root . '/') === 0 ? substr($p, strlen($root) + 1) : $p; }
function section($t) { echo "\n== $t\n"; }
function php_files($dir, $skipRe) {
    $out = array();
    if (!is_dir($dir)) return $out;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        $p = str_replace('\\', '/', $f->getPathname());
        if (substr($p, -4) === '.php' && !preg_match($skipRe, $p)) $out[] = $p;
    }
    sort($out);
    return $out;
}

// ---------------------------------------------------------------- layout
section('Layout (from index.php)');
$index = $root . '/index.php';
$system = $root . '/system'; $app = $root . '/application'; $env = '?';
if (is_file($index)) {
    $src = file_get_contents($index);
    if (preg_match('/\$system_path\s*=\s*[\'"]([^\'"]*)[\'"]/', $src, $m)) $system = $root . '/' . trim($m[1], '/');
    if (preg_match('/\$application_folder\s*=\s*[\'"]([^\'"]*)[\'"]/', $src, $m)) $app = $root . '/' . trim($m[1], '/');
    if (preg_match('/^\s*define\(\s*[\'"]ENVIRONMENT[\'"]\s*,\s*(.+?)\);/m', $src, $m)) $env = trim($m[1]);
    $estrict = preg_match_all('/E_STRICT/', $src);
    echo "index.php: system=", rel($system), " application=", rel($app), "\n";
    echo "ENVIRONMENT define: $env\n";
    echo "E_STRICT in index.php: ", $estrict ? "$estrict (remove, deprecated in 8.4)" : 'none', "\n";
} else {
    echo "index.php not found at project root. Find the front controller and pass that folder.\n";
}
$systemReal = realpath($system); $appReal = realpath($app);
$system = $systemReal ? str_replace('\\', '/', $systemReal) : $system;
$app = $appReal ? str_replace('\\', '/', $appReal) : $app;

$ciVersion = 'not found';
if (is_file("$system/core/CodeIgniter.php") && preg_match('/CI_VERSION[\'"]?\s*(?:=|,)\s*[\'"]([^\'"]+)/', file_get_contents("$system/core/CodeIgniter.php"), $m)) $ciVersion = $m[1];
echo "CI_VERSION: $ciVersion\n";
if (strpos($ciVersion, '2.') === 0) echo "!! CodeIgniter 2: not covered by this skill (needs a CI 2 -> 3 migration first).\n";
if (strpos($ciVersion, '4.') === 0) echo "!! CodeIgniter 4: not covered by this skill.\n";
$patched = is_dir($system) ? count(array_filter(php_files($system, '#^$#'), function ($f) { return strpos(file_get_contents($f), 'AllowDynamicProperties') !== false; })) : 0;
echo "system/ files with #[AllowDynamicProperties]: $patched (0 = PHP 8.2 patch not applied yet)\n";
foreach (array('libraries/Session/PHP8SessionWrapper.php' => 'PHP 8.1 session wrapper (3.1.12+)') as $f => $label) {
    echo "$label: ", is_file("$system/$f") ? 'present' : 'MISSING (upgrade system/ to 3.1.13)', "\n";
}
// Stock CI ships "Deny from all" .htaccess files; projects copied without dotfiles lose them
foreach (array($system, $app) as $d) {
    if (is_dir($d)) echo rel($d), "/.htaccess: ", is_file("$d/.htaccess") ? 'present' : 'MISSING (pre-existing exposure unless the web server blocks this folder; report it)', "\n";
}

// ---------------------------------------------------------------- config
section('Config (values that matter for the upgrade; secrets are not printed)');
$cfg = "$app/config/config.php";
if (is_file($cfg)) {
    $c = file_get_contents($cfg);
    foreach (array('sess_driver', 'sess_save_path', 'sess_cookie_name', 'sess_match_ip', 'log_threshold', 'log_path', 'composer_autoload', 'csrf_protection', 'enable_hooks', 'subclass_prefix', 'base_url') as $k) {
        if (preg_match('/\$config\[[\'"]' . $k . '[\'"]\]\s*=\s*(.+?);/', $c, $m)) echo str_pad($k, 18), trim($m[1]), "\n";
    }
    echo "encryption_key set: ", preg_match('/\$config\[[\'"]encryption_key[\'"]\]\s*=\s*[\'"][^\'"]+[\'"]/', $c) ? 'yes' : 'no/empty', "\n";
}
foreach (glob("$app/config/{,*/}database.php", GLOB_BRACE) ?: array() as $db) {
    $d = file_get_contents($db);
    preg_match_all('/[\'"]dbdriver[\'"]\s*=>\s*[\'"]([^\'"]+)/', $d, $m);
    echo rel($db), ": dbdriver=", implode(',', array_unique($m[1])) ?: '?';
    if (in_array('mysql', $m[1], true)) echo "  !! 'mysql' driver does not exist on PHP 7+, switch to mysqli";
    if (preg_match('/[\'"]pconnect[\'"]\s*=>\s*TRUE/i', $d)) echo "  (pconnect TRUE)";
    echo "\n";
}

// ---------------------------------------------------------------- libraries
section('Bundled libraries and Composer packages');
$skipLib = '#/(\.git|node_modules)/#';
$libMarkers = array(
    'Dompdf'       => array('#/dompdf/(VERSION|src/Dompdf\.php|dompdf_config\.inc\.php)$#i', '0.x/1.x fatal or broken on PHP 8.1+; upgrade to 3.x'),
    'PHPMailer'    => array('#/(class\.phpmailer\.php|PHPMailer\.php)$#', 'below 6.x: __autoload fatal and old CVEs'),
    'mPDF'         => array('#/mpdf(\d*)?/(mpdf\.php|src/Mpdf\.php)$#i', 'below 8.x does not run on PHP 8'),
    'TCPDF'        => array('#/tcpdf\.php$#i', 'needs 6.4+ on PHP 8'),
    'PHPExcel'     => array('#/PHPExcel\.php$#', 'abandoned, broken on PHP 8; needs PhpSpreadsheet (big change, plan separately)'),
    'phpqrcode'    => array('#/(qrlib|phpqrcode)\.php$#i', 'old versions use curly string offsets (fatal on 8.0)'),
    'Smarty'       => array('#/Smarty\.class\.php$#', 'needs 3.1.39+ / 4.x on PHP 8'),
    'HTMLPurifier' => array('#/HTMLPurifier\.php$#', 'needs 4.14+ on PHP 8.1'),
    'Spout'        => array('#/Spout/Autoloader/autoload\.php$#', 'box/spout 2.x is PHP 8 incompatible'),
    'Adminer'      => array('#/[^/]*adminer[^/]*\.php$#i', 'SECURITY: database admin tool inside the web root; old builds also fatal on PHP 8. Tell the user, propose removing it'),
    'Responsive Filemanager' => array('#/filemanager/(dialog|UploadHandler)\.php$#', 'old builds use implode()/each() removed in PHP 8; upgrade or patch, and check it is login-protected'),
);
$found = array();
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    $p = str_replace('\\', '/', $f->getPathname());
    if (preg_match($skipLib, $p)) continue;
    foreach ($libMarkers as $name => $spec) {
        if (preg_match($spec[0], $p)) {
            $ver = '';
            $txt = @file_get_contents($p, false, null, 0, 200000);
            if (preg_match('#/VERSION$#', $p)) $ver = trim($txt);
            elseif (preg_match('/(?:\$Version|const VERSION|define\([\'"]\w*VERSION[\'"],|@version)\s*=?\s*[\'"]?v?([0-9][0-9.]+)/i', (string) $txt, $m)) $ver = $m[1];
            $found[] = "$name " . ($ver ?: '?') . " at " . rel($p) . "  -- " . $spec[1];
        }
    }
}
echo $found ? implode("\n", array_unique($found)) . "\n" : "none of the known libraries found\n";
$composer = array();
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    $p = str_replace('\\', '/', $f->getPathname());
    if (basename($p) === 'composer.json' && !preg_match('#/(vendor|node_modules|\.git)/#', $p)) $composer[] = $p;
}
foreach ($composer as $cj) {
    $j = json_decode(file_get_contents($cj), true) ?: array();
    echo rel($cj), ": require=", json_encode($j['require'] ?? new stdClass), " platform.php=", $j['config']['platform']['php'] ?? '-', "\n";
    $lock = dirname($cj) . '/composer.lock';
    if (is_file($lock)) {
        $l = json_decode(file_get_contents($lock), true) ?: array();
        $pk = array();
        foreach (array_merge($l['packages'] ?? array(), $l['packages-dev'] ?? array()) as $p) $pk[] = $p['name'] . ' ' . $p['version'];
        echo "   lock: ", implode(', ', $pk), "\n";
    }
}

// ---------------------------------------------------------------- patterns
$skip = '#/(\.git|node_modules|vendor|cache|logs|uploads?)/#';
$appFiles = array();
foreach (php_files($root, $skip) as $f) {
    if ($systemReal && strpos($f, $system . '/') === 0) continue; // system/ is replaced wholesale, not scanned
    $appFiles[] = $f;
}
echo "\nScanned files outside system/ and vendor/: ", count($appFiles), "\n";

$checks = array(
    'FATAL removed functions (8.0)'      => '/(?<![\w>:$.\\\\])(each|create_function|money_format|get_magic_quotes_(gpc|runtime)|ereg|eregi|ereg_replace|split|mysql_[a-z_]+|convert_cyr_string|hebrevc|restore_include_path|fgetss|image2wbmp)\s*\(/i',
    'FATAL __autoload (8.0)'             => '/function\s+__autoload\s*\(/',
    'implode legacy arg order (8.0 TypeError)' => '/\bimplode\(\s*\$[\w\[\]\'"->]+\s*,\s*[\'"]/',
    'nested ternary without parens (8.0 fatal)' => '/\?[^:;?()]*:[^;?()]*\?[^:;()]*:/',
    'division by a variable (8.0 DivisionByZeroError)' => '#[^/*]\s/\s*\(?\$\w+#',
    'modulo by a variable (8.0 DivisionByZeroError)' => '/[^%]\s%\s*\(?\$\w+/',
    'count() on a variable (8.0 TypeError if null/scalar)' => '/\bcount\(\s*\$[\w\->\[\]\'"]+\s*\)/',
    'catch (Exception) misses Error/TypeError' => '/catch\s*\(\s*\\\\?Exception\s+\$/',
    'E_STRICT (8.4)'                     => '/\bE_STRICT\b/',
    'trigger_error E_USER_ERROR (8.4)'   => '/E_USER_ERROR/',
    'implicit nullable parameter (8.4)'  => '/function\s*\w*\s*\([^)]*\b(array|string|int|float|bool|callable|iterable|object|self|[A-Z]\w+)\s+&?\$\w+\s*=\s*null/i',
    'optional param before required (8.0)' => '/function\s*\w*\s*\([^)]*\$\w+\s*=\s*[^,)]+,\s*(\w+\s+)?\$\w+\s*[,)]/',
    '${var} string interpolation (8.2)'  => '/"[^"]*\$\{\w/',
    'utf8_encode/decode (8.2)'           => '/\butf8_(en|de)code\s*\(/',
    'FILTER_SANITIZE_STRING (8.1)'       => '/FILTER_SANITIZE_STRING/',
    'strftime/gmstrftime (8.1)'          => '/\bg?m?strftime\s*\(/',
    'date_sunrise/sunset, mhash, odbc_result_all (8.1)' => '/\b(date_sun(rise|set)|mhash\w*|odbc_result_all)\s*\(/',
    'libxml_disable_entity_loader (8.0)' => '/libxml_disable_entity_loader/',
    'get_class()/get_parent_class() without args (8.3)' => '/\bget_(parent_)?class\(\s*\)/',
    'imagedestroy (8.5)'                 => '/\bimagedestroy\s*\(/',
    'mb_/str functions on possible null (8.1)' => '/\b(strlen|trim|strtolower|strtoupper|htmlspecialchars|htmlentities|str_replace|explode|strpos|substr|json_decode|base64_decode|urlencode)\(\s*\$this->input->(post|get|cookie)\(/',
    'serialize/unserialize of objects (check __wakeup, 8.1 Serializable)' => '/implements\s+[^{]*\bSerializable\b/',
    'old PHPMailer usage'                => '/PHPMailerAutoload|class\.phpmailer|new\s+PHPMailer\s*\(/',
    'custom result object class (needs declared props)' => '/->(custom_result_object|custom_row_object)\(|->result\(\s*[\'"][A-Z]\w*[\'"]\s*\)|->row\(\s*\d*\s*,\s*[\'"][A-Z]\w*[\'"]\s*\)/',
    'mysqli result used without false check' => '/mysqli_(fetch_\w+|num_rows)\(\s*mysqli_query/',
);
$hits = array_fill_keys(array_keys($checks), array());
$totals = array_fill_keys(array_keys($checks), 0);
$minified = array();
foreach ($appFiles as $f) {
    foreach (file($f) as $i => $line) {
        $t = ltrim($line);
        if ($t === '' || $t[0] === '*' || $t[0] === '|' || $t[0] === '#' || strpos($t, '//') === 0) continue;
        if (strlen($line) > 600) { $minified[rel($f)] = true; continue; } // minified/packed code: too noisy, listed below
        $code = preg_replace('/<\?(php|=)?|\?>/', ' ; ', $line); // PHP tags in views look like ternaries
        foreach ($checks as $name => $re) {
            if (preg_match($re, $code)) {
                $totals[$name]++;
                if (count($hits[$name]) < $max) $hits[$name][] = rel($f) . ':' . ($i + 1) . ': ' . trim(mb_strimwidth($line, 0, 180, '...'));
            }
        }
    }
}
foreach ($hits as $name => $list) {
    section($name . ' [' . $totals[$name] . ']');
    foreach ($list as $l) echo $l, "\n";
    if ($totals[$name] > count($list)) echo "... ", $totals[$name] - count($list), " more (use --max=N)\n";
}
if ($minified) {
    section('Files with minified/packed lines (skipped by the pattern checks; lint and test them, or upgrade the tool they belong to)');
    foreach (array_keys($minified) as $m) echo $m, "\n";
}

// ---------------------------------------------------------------- MY_ classes
section('Core/library extensions (MY_ prefix etc.): re-check after system/ upgrade');
foreach ($appFiles as $f) {
    if (preg_match('#/(core|libraries)/[^/]+\.php$#', $f) && preg_match('/class\s+(\w+)\s+extends\s+(CI_\w+)/', file_get_contents($f), $m)) echo rel($f), ": {$m[1]} extends {$m[2]}\n";
}

// ---------------------------------------------------------------- dynamic properties
// Token-based: classes that write $this->x where x is not declared in the class, its parents or traits,
// and no class in the chain has #[AllowDynamicProperties] or __set. Deprecated in 8.2, Error in 9.0.
section('Dynamic property candidates (8.2 deprecation)');
$patchedCi = array('CI_Controller', 'CI_Model', 'CI_Loader', 'CI_Router', 'CI_URI', 'CI_DB_driver', 'CI_Table', 'CI_Driver_Library', 'CI_Profiler');
$classes = array();
function parse_classes($file) {
    $toks = @token_get_all(file_get_contents($file));
    $out = array(); $depth = 0; $paren = 0; $cur = null; $classDepth = -1; $pendingAttr = '';
    $n = count($toks); $fn = null; $mods = false;
    for ($i = 0; $i < $n; $i++) {
        $t = $toks[$i]; $id = is_array($t) ? $t[0] : $t; $tx = is_array($t) ? $t[1] : $t;
        if ($id === T_WHITESPACE || $id === T_COMMENT || $id === T_DOC_COMMENT) {
            if ($id === T_COMMENT && stripos($tx, 'AllowDynamicProperties') !== false) $pendingAttr .= $tx; // PHP 7 sees #[..] as a comment
            continue;
        }
        if (defined('T_ATTRIBUTE') && $id === T_ATTRIBUTE) {
            $j = $i; $a = '';
            while ($j < $n && $toks[$j] !== ']') { $a .= is_array($toks[$j]) ? $toks[$j][1] : $toks[$j]; $j++; }
            $pendingAttr .= $a; $i = $j; continue;
        }
        if (($id === T_CLASS || $id === T_TRAIT) && $cur === null) {
            $prev = $i - 1; while ($prev >= 0 && is_array($toks[$prev]) && $toks[$prev][0] === T_WHITESPACE) $prev--;
            if ($prev >= 0 && is_array($toks[$prev]) && in_array($toks[$prev][0], array(T_NEW, T_DOUBLE_COLON), true)) continue; // anonymous class or ::class
            $j = $i + 1; while (is_array($toks[$j]) && $toks[$j][0] === T_WHITESPACE) $j++;
            $name = is_array($toks[$j]) ? $toks[$j][1] : '?';
            $ext = null; $k = $j;
            while ($k < $n && $toks[$k] !== '{') { if (is_array($toks[$k]) && $toks[$k][0] === T_EXTENDS) { $m = $k + 1; while (is_array($toks[$m]) && $toks[$m][0] === T_WHITESPACE) $m++; $ext = ltrim($toks[$m][1], '\\'); } $k++; }
            $cur = array('name' => $name, 'trait' => $id === T_TRAIT, 'extends' => $ext, 'file' => $file, 'line' => $t[2], 'attr' => stripos($pendingAttr, 'AllowDynamicProperties') !== false, 'declared' => array(), 'writes' => array(), 'uses' => array(), 'magic_set' => false);
            $pendingAttr = ''; $classDepth = $depth; $i = $k - 1; continue;
        }
        if ($id !== T_STRING && $id !== T_ATTRIBUTE) { if ($id !== T_FINAL && $id !== T_ABSTRACT && !(defined('T_READONLY') && $id === T_READONLY)) $pendingAttr = $id === T_CLASS ? $pendingAttr : ''; }
        if ($tx === '{' || $id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) { $depth++; continue; }
        if ($tx === '}') { $depth--; if ($cur && $depth === $classDepth) { $out[] = $cur; $cur = null; } continue; }
        if (!$cur) continue;
        if ($tx === '(') { $paren++; continue; }
        if ($tx === ')') { $paren--; if ($paren === 0) $fn = $depth === $classDepth + 1 ? null : $fn; continue; }
        $inBody = $depth === $classDepth + 1;
        if ($inBody && $id === T_USE) { $j = $i + 1; while ($j < $n && $toks[$j] !== ';' && $toks[$j] !== '{') { if (is_array($toks[$j]) && in_array($toks[$j][0], array(T_STRING, defined('T_NAME_QUALIFIED') ? T_NAME_QUALIFIED : -1), true)) $cur['uses'][] = ltrim($toks[$j][1], '\\'); $j++; } continue; }
        if ($inBody && $id === T_FUNCTION) { $j = $i + 1; while (is_array($toks[$j]) && $toks[$j][0] === T_WHITESPACE) $j++; $fn = is_array($toks[$j]) ? strtolower($toks[$j][1]) : null; if ($fn === '__set') $cur['magic_set'] = true; continue; }
        if (in_array($id, array(T_PUBLIC, T_PROTECTED, T_PRIVATE, T_VAR), true)) { $mods = true; continue; }
        if ($tx === ',' || $tx === ';') { if ($paren === 0 && $tx === ';') $mods = false; if ($paren > 0) $mods = false; continue; }
        if ($id === T_VARIABLE) {
            if ($inBody && $paren === 0) { $cur['declared'][substr($tx, 1)] = true; continue; }
            if ($inBody && $paren === 1 && $fn === '__construct' && $mods) { $cur['declared'][substr($tx, 1)] = true; $mods = false; continue; }
            if ($tx === '$this' && isset($toks[$i + 1], $toks[$i + 2]) && is_array($toks[$i + 1]) && $toks[$i + 1][0] === T_OBJECT_OPERATOR && is_array($toks[$i + 2]) && $toks[$i + 2][0] === T_STRING) {
                $prop = $toks[$i + 2][1]; $j = $i + 3;
                while ($j < $n && is_array($toks[$j]) && $toks[$j][0] === T_WHITESPACE) $j++;
                $b = 0;
                while ($j < $n && ($toks[$j] === '[' || $b > 0)) { if ($toks[$j] === '[') $b++; elseif ($toks[$j] === ']') $b--; $j++; if ($b === 0) { while ($j < $n && is_array($toks[$j]) && $toks[$j][0] === T_WHITESPACE) $j++; } }
                if ($j < $n && $toks[$j] === '=') $cur['writes'][$prop] = $cur['writes'][$prop] ?? $toks[$i][2];
            }
        }
        if (!in_array($id, array(T_STRING, T_ARRAY, T_CALLABLE, T_NS_SEPARATOR, T_STATIC), true) && $tx !== '?' && $tx !== '|' && !(defined('T_READONLY') && $id === T_READONLY) && !(defined('T_NAME_QUALIFIED') && in_array($id, array(T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED), true))) $mods = $mods && false;
    }
    return $out;
}
$allFiles = array_merge(is_dir($system) ? php_files($system, '#^$#') : array(), $appFiles);
foreach ($allFiles as $f) foreach (parse_classes($f) as $c) { $classes[strtolower($c['name'])] = $c; }
function resolve_chain($name, &$seen = array()) {
    global $classes;
    $key = strtolower($name);
    if (isset($seen[$key]) || !isset($classes[$key])) return array('known' => false, 'declared' => array(), 'covered' => false, 'chain' => array($name));
    $seen[$key] = true; $c = $classes[$key];
    $declared = $c['declared']; $covered = $c['attr'] || $c['magic_set'];
    foreach ($c['uses'] as $tr) { $r = resolve_chain($tr, $seen); $declared += $r['declared']; $covered = $covered || $r['covered']; }
    $known = true; $chain = array($c['name']);
    if ($c['extends']) { $r = resolve_chain($c['extends'], $seen); $declared += $r['declared']; $covered = $covered || $r['covered']; $known = $r['known']; $chain = array_merge($chain, $r['chain']); }
    return array('known' => $known, 'declared' => $declared, 'covered' => $covered, 'chain' => $chain);
}
$dyn = 0; $unknown = array();
foreach ($classes as $c) {
    if ($c['trait'] || !in_array($c['file'], $appFiles, true) || !$c['writes']) continue;
    $r = resolve_chain($c['name']);
    // A chain ending in one of the CI classes the patch marks is covered once system/ is patched
    $viaPatch = (bool) array_intersect($r['chain'], $patchedCi);
    if ($r['covered'] || $viaPatch) continue;
    $missing = array_diff_key($c['writes'], $r['declared']);
    if (!$missing) continue;
    if (!$r['known']) { $unknown[] = rel($c['file']) . ": {$c['name']} extends " . end($r['chain']) . " (parent not found in project, check by hand): " . implode(', ', array_keys($missing)); continue; }
    foreach ($missing as $p => $line) { if ($dyn < $max) echo rel($c['file']), ":$line: {$c['name']}->$p (chain: ", implode(' < ', $r['chain']), ")\n"; $dyn++; }
}
if ($dyn > $max) echo "... ", $dyn - $max, " more\n";
if (!$dyn) echo "none\n";
foreach (array_slice($unknown, 0, $max) as $u) echo $u, "\n";
echo "\nClasses whose chain ends in a patched CI class (", implode(', ', $patchedCi), ") are not listed: the system/ patch covers them.\n";
