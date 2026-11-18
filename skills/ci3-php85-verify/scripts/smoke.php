<?php
// Usage: php smoke.php <base-url> <urls-file> [--cookie=name=value] [--log-dir=<application/logs>] [--insecure] [--timeout=60]
//
// Requests every URL in <urls-file> (one path per line, "#" comments and blank lines ignored,
// a tab-separated list from list_routes.php also works: only the first column is used) and prints:
//   STATUS  BYTES  SECONDS  PATH  [-> redirect]  [new CI log errors]
// After each request it reads the lines CodeIgniter appended to its log, so PHP warnings that the
// production error page hides still show up. Exit 1 if any 5xx or any new ERROR log line.
// Redirects are not followed (a 302 to the login page means the cookie is missing or expired).
// Run it once against the old PHP and once against 8.5, then diff the two outputs.
// Only GET requests: it never submits forms. Still, do not point it at production.

$opts = array('cookie' => null, 'log-dir' => null, 'insecure' => false, 'timeout' => 60);
$pos = array();
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([\w-]+)(?:=(.*))?$/', $a, $m)) $opts[$m[1]] = $m[2] ?? true;
    else $pos[] = $a;
}
list($base, $file) = $pos + array(null, null);
if (!$base || !$file || !is_file($file)) { fwrite(STDERR, "usage: php smoke.php <base-url> <urls-file> [--cookie=name=value] [--log-dir=dir] [--insecure]\n"); exit(2); }
$base = rtrim($base, '/');

function log_sizes($dir) {
    $s = array();
    if ($dir && is_dir($dir)) foreach (glob(rtrim($dir, '/\\') . '/log-*.php') as $f) { clearstatcache(true, $f); $s[$f] = filesize($f); }
    return $s;
}
function log_errors($dir, $before) {
    $lines = array();
    foreach (log_sizes($dir) as $f => $size) {
        $start = $before[$f] ?? 0;
        if ($size <= $start) continue;
        $h = fopen($f, 'r'); fseek($h, $start); $chunk = stream_get_contents($h); fclose($h);
        foreach (preg_split('/\r?\n/', $chunk) as $l) if (strpos($l, 'ERROR') === 0) $lines[] = preg_replace('/^ERROR - [\d: -]+--> /', '', $l);
    }
    return $lines;
}

$paths = array();
foreach (file($file) as $line) {
    $line = trim(explode("\t", $line)[0]);
    if ($line === '' || $line[0] === '#') continue;
    $paths[] = '/' . ltrim($line, '/');
}

$headers = "User-Agent: ci3-php85-smoke\r\n";
if ($opts['cookie']) $headers .= "Cookie: {$opts['cookie']}\r\n";
$ctx = stream_context_create(array(
    'http' => array('method' => 'GET', 'header' => $headers, 'follow_location' => 0, 'ignore_errors' => true, 'timeout' => (float) $opts['timeout']),
    'ssl'  => array('verify_peer' => !$opts['insecure'], 'verify_peer_name' => !$opts['insecure']),
));

$bad = 0;
foreach ($paths as $p) {
    $before = log_sizes($opts['log-dir']);
    $t0 = microtime(true);
    $body = @file_get_contents($base . $p, false, $ctx);
    $dt = microtime(true) - $t0;
    $status = 0; $loc = '';
    foreach ($http_response_header ?? array() as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) $status = (int) $m[1];
        if (stripos($h, 'Location:') === 0) $loc = trim(substr($h, 9));
    }
    $errs = $opts['log-dir'] ? log_errors($opts['log-dir'], $before) : array();
    if ($status >= 500 || $status === 0 || $errs) $bad++;
    printf("%3d %8d %6.2fs  %s%s\n", $status, $body === false ? 0 : strlen($body), $dt, $p, $loc ? "  -> $loc" : '');
    foreach (array_unique($errs) as $e) echo "      LOG ", $e, "\n";
}
echo "\n", count($paths), " URLs, $bad with 5xx/no response/log errors", $opts['log-dir'] ? '' : ' (no --log-dir: PHP warnings not checked)', "\n";
exit($bad ? 1 : 0);
