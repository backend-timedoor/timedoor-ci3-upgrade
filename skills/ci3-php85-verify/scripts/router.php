<?php
// Router for PHP's built-in server, emulating the usual CodeIgniter .htaccess rewrite:
// existing files are served as they are, everything else goes to index.php.
//
//   cd <project-root>
//   <php85> -S 127.0.0.1:8085 <path-to>/router.php
//
// The built-in server handles one request at a time. A page that requests another URL of the same server
// while rendering (Dompdf loading CSS/images over http, server-side curl to itself) waits forever.
// Fixes: set PHP_CLI_SERVER_WORKERS=4 before starting (Linux/macOS only), serve assets from a second
// built-in server, or test those pages on a real web server.
$uri  = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$file = $_SERVER['DOCUMENT_ROOT'] . $uri;
if ($uri !== '/' && is_file($file)) {
    return false; // let the built-in server send the static file
}
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $_SERVER['DOCUMENT_ROOT'] . '/index.php';
chdir($_SERVER['DOCUMENT_ROOT']);
require $_SERVER['DOCUMENT_ROOT'] . '/index.php';
