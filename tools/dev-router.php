<?php

declare(strict_types=1);

$public = dirname(__DIR__).'/public';
$path = rawurldecode(explode('?', $_SERVER['REQUEST_URI'], 2)[0]);
if (preg_match('~^/assets/(?:login\.(?:css|js)|uat\.css|workflow\.js)$~D', $path)) {
    $asset = realpath($public.$path);
    if ($asset !== false && $asset === realpath($public).DIRECTORY_SEPARATOR.'assets'.DIRECTORY_SEPARATOR.basename($path) && is_file($asset)) {
        return false;
    }
}

// Public application routes occupy a single path segment. Symfony matches the route and method.
if (!preg_match('~^/(?:[a-z][a-z0-9_-]*(?:\.php)?)?$~D', $path)) {
    http_response_code(404);
    return true;
}

// Give Runtime the front controller as the executing script for every route.
$_SERVER['SCRIPT_FILENAME'] = $public.'/index.php';
$_SERVER['SCRIPT_NAME'] = $_SERVER['PHP_SELF'] = '/index.php';
require dirname(__DIR__).'/public/index.php';
