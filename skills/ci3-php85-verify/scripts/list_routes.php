<?php
// Usage: php list_routes.php <project-root>
// Lists the URLs a CodeIgniter 3 app serves, from its controllers and config/routes.php, without running it.
// Output: one tab-separated line per URL: url  parent-class  note
//   parent-class tells you which base controller (and so which login) guards the page.
//   "needs args (N)" = method has N required parameters; fill in real ids from the database.
//   "WRITES?" = the method name contains a verb that usually changes data (save, delete, toggle,
//   send, logout...). Leave those out of a GET smoke test; test them deliberately, on a DB backup.

$root = $argv[1] ?? null;
if (!$root || !is_dir($root)) { fwrite(STDERR, "usage: php list_routes.php <project-root>\n"); exit(2); }
$root = rtrim(str_replace('\\', '/', realpath($root)), '/');

$app = "$root/application";
if (is_file("$root/index.php") && preg_match('/\$application_folder\s*=\s*[\'"]([^\'"]*)[\'"]/', file_get_contents("$root/index.php"), $m)) $app = "$root/" . trim($m[1], '/');
$ctrlDir = "$app/controllers";
if (!is_dir($ctrlDir)) { fwrite(STDERR, "no controllers folder at $ctrlDir\n"); exit(2); }

$writeWords = array('add', 'save', 'store', 'insert', 'create', 'update', 'edit', 'del', 'delete', 'remove', 'destroy',
    'upload', 'import', 'send', 'submit', 'toggle', 'approve', 'reject', 'reset', 'refresh', 'sync', 'finish', 'logout',
    'process', 'generate', 'migrate', 'install', 'truncate', 'clear', 'capture', 'log', 'next', 'auth', 'login',
    'register', 'pay', 'cancel', 'publish', 'move', 'sort', 'set', 'do', 'post', 'answer', 'verify', 'activate');

$out = array();
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($ctrlDir, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    $p = str_replace('\\', '/', $f->getPathname());
    if (substr($p, -4) !== '.php') continue;
    $sub = trim(substr(dirname($p), strlen($ctrlDir)), '/');
    $toks = token_get_all(file_get_contents($p));
    $n = count($toks); $class = null; $parent = ''; $depth = 0; $classDepth = -1;
    for ($i = 0; $i < $n; $i++) {
        $t = $toks[$i]; $id = is_array($t) ? $t[0] : $t; $tx = is_array($t) ? $t[1] : $t;
        if ($id === T_CLASS && $class === null) {
            $j = $i + 1; while (is_array($toks[$j]) && $toks[$j][0] === T_WHITESPACE) $j++;
            $class = $toks[$j][1];
            $k = $j;
            while ($k < $n && $toks[$k] !== '{') {
                if (is_array($toks[$k]) && $toks[$k][0] === T_EXTENDS) {
                    $m2 = $k + 1; while (is_array($toks[$m2]) && $toks[$m2][0] === T_WHITESPACE) $m2++;
                    $parent = ltrim($toks[$m2][1], '\\');
                }
                $k++;
            }
            $classDepth = $depth; $i = $k - 1; continue;
        }
        if ($tx === '{' || $id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) { $depth++; continue; }
        if ($tx === '}') { $depth--; continue; }
        if ($class === null || $depth !== $classDepth + 1 || $id !== T_FUNCTION) continue;

        // visibility: CI only routes public, non-static methods
        $hidden = false; $b = $i - 1;
        while ($b > 0 && is_array($toks[$b]) && in_array($toks[$b][0], array(T_WHITESPACE, T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_FINAL, T_ABSTRACT, T_DOC_COMMENT, T_COMMENT), true)) {
            if (in_array($toks[$b][0], array(T_PROTECTED, T_PRIVATE, T_STATIC), true)) $hidden = true;
            $b--;
        }
        $j = $i + 1; while (is_array($toks[$j]) && $toks[$j][0] === T_WHITESPACE) $j++;
        $method = is_array($toks[$j]) ? $toks[$j][1] : '';

        // count required parameters (a $var not followed by "=" before "," or ")")
        $k = $j; while ($k < $n && $toks[$k] !== '(') $k++;
        $pd = 0; $required = 0; $hasDefault = false; $seenVar = false;
        for ($k++; $k < $n; $k++) {
            $tk = $toks[$k]; $tt = is_array($tk) ? $tk[1] : $tk;
            if ($tt === '(' || $tt === '[') $pd++;
            elseif (($tt === ')' || $tt === ']') && $pd > 0) $pd--;
            elseif ($tt === ')') { if ($seenVar && !$hasDefault) $required++; break; }
            elseif ($pd === 0 && is_array($tk) && $tk[0] === T_VARIABLE) $seenVar = true;
            elseif ($pd === 0 && ($tt === '=' || (is_array($tk) && $tk[0] === T_ELLIPSIS))) $hasDefault = true;
            elseif ($pd === 0 && $tt === ',') { if ($seenVar && !$hasDefault) $required++; $seenVar = false; $hasDefault = false; }
        }
        if ($hidden || $method === '' || $method[0] === '_' || strcasecmp($method, $class) === 0) continue;

        $seg = strtolower(($sub !== '' ? "$sub/" : '') . basename($p, '.php'));
        $url = strtolower($method) === 'index' ? $seg : "$seg/$method";
        $notes = array();
        if ($required) $notes[] = "needs args ($required)";
        // split snake_case and camelCase into words; any write verb marks the method
        // (controller words too: Finish::index is a write), plus glued prefixes like addklien / delpo / editpo
        $words = array_map('strtolower', preg_split('/_|(?<=[a-z])(?=[A-Z])/', $method . '_' . basename($p, '.php')));
        if (array_intersect($words, $writeWords) || preg_match('/^(add|del|edit|save|upd|ins|rem|upload|send|submit|toggle|reset|import|export|process|sync|capture)/i', $method)) $notes[] = 'WRITES?';
        $out[] = array($url, $parent, implode(', ', $notes));
    }
}
usort($out, function ($a, $b) { return strcmp($a[1] . "\0" . $a[0], $b[1] . "\0" . $b[0]); });
foreach ($out as $r) echo implode("\t", $r), "\n";

// Custom routes: shown so the tester can add the pretty URLs too
$routes = "$app/config/routes.php";
if (is_file($routes)) {
    echo "\n# config/routes.php\n";
    foreach (file($routes) as $line) {
        if (preg_match('/^\s*\$route\[[\'"]([^\'"]+)[\'"]\]\s*=\s*(.+?);/', $line, $m)) echo "# route\t{$m[1]}\t=> ", trim($m[2]), "\n";
    }
}
