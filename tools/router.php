<?php
$remoteAddress = $_SERVER['REMOTE_ADDR'] ?? '';
$isLoopback = in_array($remoteAddress, ['127.0.0.1', '::1'], true);
$isDockerClient = getenv('DISC2_DOCKER') === '1'
    && filter_var($remoteAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
    && preg_match('/^(?:10\.|172\.(?:1[6-9]|2[0-9]|3[01])\.|192\.168\.)/', $remoteAddress) === 1;
if (PHP_SAPI !== 'cli-server' || getenv('DISC2_DEV') !== '1' || !($isLoopback || $isDockerClient)) {
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
