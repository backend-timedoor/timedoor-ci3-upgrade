<?php
// Usage: php lint_all.php <project-root> [php-binary] [--with-vendor]
// Lints every .php file with all error levels on, using the given PHP (default: the PHP running this script).
// Prints failures and compile-time deprecations only. Exit 1 if any.
// vendor/ folders are skipped by default: Composer packages ship version-gated polyfills that
// lint "wrong" on purpose. Pass --with-vendor to include them (useful for hand-vendored libraries).
$args = array_values(array_filter(array_slice($argv, 1), function ($a) { return $a !== '--with-vendor'; }));
$withVendor = in_array('--with-vendor', $argv, true);
$root = $args[0] ?? null;
$php  = $args[1] ?? PHP_BINARY;
if (!$root || !is_dir($root)) { fwrite(STDERR, "usage: php lint_all.php <project-root> [php-binary] [--with-vendor]\n"); exit(2); }

$dirs = '\.git|node_modules|cache|logs|uploads?' . ($withVendor ? '' : '|vendor');
$skip = '#[\\\\/](' . $dirs . ')[\\\\/]#';
$fail = 0;
$count = 0;
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
    $path = $file->getPathname();
    if (substr($path, -4) !== '.php' || preg_match($skip, $path)) continue;
    $count++;
    $cmd = escapeshellarg($php) . ' -d error_reporting=-1 -d display_errors=1 -l ' . escapeshellarg($path) . ' 2>&1';
    $out = trim((string) shell_exec($cmd));
    if (strpos($out, 'No syntax errors detected') !== 0 || preg_match('/deprecated|warning/i', $out)) {
        echo $out, "\n";
        $fail = 1;
    }
}
echo $fail ? "lint FAILED ($count files)\n" : "lint clean ($count files)\n";
exit($fail);
