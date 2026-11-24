<?php

declare(strict_types=1);

$public = dirname(__DIR__).'/public';
$path = rawurldecode(explode('?', $_SERVER['REQUEST_URI'], 2)[0]);
if (preg_match('~^/assets/(?:[\w-][\w.-]*/)*[\w-][\w.-]*\.(?:css|js|png|jpe?g|gif|svg|webp|ico|woff2?)$~iD', $path)) {
    $asset = realpath($public.$path);
    if ($asset !== false && str_starts_with($asset, realpath($public).DIRECTORY_SEPARATOR) && is_file($asset)) {
        return false;
    }
}

// Give Runtime the front controller as the executing script for every route.
$_SERVER['SCRIPT_FILENAME'] = $public.'/index.php';
$_SERVER['SCRIPT_NAME'] = $_SERVER['PHP_SELF'] = '/index.php';
require $public.'/index.php';
