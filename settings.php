<?php
function disc2DevMode(): bool
{
    return in_array(PHP_SAPI, ['cli', 'cli-server'], true) && getenv('DISC2_DEV') === '1';
}

function disc2LiveConfig(): array
{
    static $config;
    if (isset($config)) return $config;
    if (disc2DevMode()) return $config = [];
    $path = getenv('DISC2_CONFIG_FILE') ?: 'C:/inetpub/private/disc2-config.php';
    return $config = is_file($path) ? require $path : [];
}
