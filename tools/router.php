<?php
if (PHP_SAPI !== 'cli-server' || getenv('DISC2_DEV') !== '1' || !in_array($_SERVER['REMOTE_ADDR'], ['127.0.0.1', '::1'], true)) {
    http_response_code(404);
    return true;
}
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/');
if ($path === '/') {
    require dirname(__DIR__).'/index.php';
    return true;
}
if (!preg_match('~^/[a-zA-Z0-9_]+\.(php|css|js)$~D', $path) || !is_file(dirname(__DIR__).$path)) {
    http_response_code(404);
    return true;
}
return false;
