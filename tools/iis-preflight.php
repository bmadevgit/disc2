<?php

declare(strict_types=1);

// CLI-only companion to the IIS smoke test. Never print the configuration or credentials.
if (PHP_SAPI !== 'cli' || !in_array($argc, [5,7], true) || !in_array($argv[4], ['production', 'staging'], true)) {
    exit(1);
}
try {
    $root = dirname(__DIR__);
    $manifest = json_decode(file_get_contents($root.'/release-manifest.json'), true, flags: JSON_THROW_ON_ERROR);
    if (version_compare(PHP_VERSION, $manifest['php_min'], '<') || getenv('APP_ENV') !== 'prod' || getenv('APP_DEBUG') !== '0' || !getenv('APP_SECRET')) {
        throw new RuntimeException('Check production CLI version, environment and secret configuration.');
    }
    foreach ($manifest['extensions'] as $ext) {
        if (!extension_loaded($ext)) {
            throw new RuntimeException("Missing CLI extension $ext");
        }
    }
    require $root.'/vendor/autoload.php';
    $config = realpath(getenv('DISC2_CONFIG_FILE') ?: 'C:/inetpub/private/disc2-config.php');
    $expected = realpath($argv[1]);
    $database = realpath($argv[2]);
    $equal = static fn (string $a, string $b): bool => PHP_OS_FAMILY === 'Windows' ? strcasecmp($a, $b) === 0 : $a === $b;
    foreach ([$expected, $database] as $path) {
        if (!$path || !is_file($path) || $equal(substr($path, 0, strlen($root) + 1), $root.DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Require existing configuration and database files outside the release.');
        }
    }
    if (!$config || !$equal($config, $expected)) {
        throw new RuntimeException('Selected config differs from the CLI configuration.');
    }
    $configDir = dirname($config).DIRECTORY_SEPARATOR;
    $databaseDir = dirname($database).DIRECTORY_SEPARATOR;
    if ($equal(substr($configDir, 0, strlen($databaseDir)), $databaseDir) || $equal(substr($databaseDir, 0, strlen($configDir)), $configDir)) {
        throw new RuntimeException('Configuration and database require separate private directory trees.');
    }
    $explicitAi = getenv('DISC2_AI_CONFIG_FILE');
    $selectedAi = $explicitAi ?: 'C:/inetpub/private/disc2-ai.php';
    // The conventional file is optional. An explicit path, an existing default,
    // or a supplied recovery pair must resolve and agree before any migration.
    if ($explicitAi || file_exists($selectedAi) || $argc === 7) {
        $ai = realpath($selectedAi);
        $expectedAi = isset($argv[5]) ? realpath($argv[5]) : false;
        $recovery = isset($argv[6]) ? realpath($argv[6]) : false;
        if (!$ai || !$expectedAi || !$recovery || !Symfony\Component\Filesystem\Path::isAbsolute($selectedAi)
            || !Symfony\Component\Filesystem\Path::isAbsolute($argv[5]) || !Symfony\Component\Filesystem\Path::isAbsolute($argv[6])
            || !$equal($ai, $expectedAi) || $equal($ai, $recovery)) {
            throw new RuntimeException('Select the configured AI file and its independent recovery copy.');
        }
        foreach ([$ai, $recovery] as $file) {
            $directory = dirname($file).DIRECTORY_SEPARATOR;
            if (!is_file($file) || !is_readable($file) || $equal(substr($file, 0, strlen($root) + 1), $root.DIRECTORY_SEPARATOR)
                || $equal(substr($directory, 0, strlen($databaseDir)), $databaseDir)
                || $equal(substr($databaseDir, 0, strlen($directory)), $directory)) {
                throw new RuntimeException('Keep the AI file and recovery copy in private configuration storage.');
            }
        }
        $sourceStat = stat($ai);
        $copyStat = stat($recovery);
        if (!$sourceStat || !$copyStat || ($sourceStat['ino'] && $sourceStat['dev'] === $copyStat['dev'] && $sourceStat['ino'] === $copyStat['ino'])
            || !hash_equals(hash_file('sha256', $ai), hash_file('sha256', $recovery))) {
            throw new RuntimeException('The AI configuration needs an independent, byte-identical recovery copy.');
        }
    }
    $settings = require $config;
    $auth = new App\Application\Auth\AuthSettings('prod', $config);
    $prodHost = parse_url(App\Application\Auth\AuthSettings::BMA_DEFAULT_ENDPOINT, PHP_URL_HOST);
    if ($auth->bmaEndpoint() !== $argv[3] || ($argv[4] === 'staging' && strcasecmp(rtrim(parse_url($auth->bmaEndpoint(), PHP_URL_HOST), '.'), $prodHost) === 0)) {
        throw new RuntimeException('The configured BMA destination does not match the approved environment endpoint.');
    }
    $path = $settings['database_path'] ?? '';
    if (!is_string($path) || !Symfony\Component\Filesystem\Path::isAbsolute($path) || !$equal(realpath($path) ?: '', $database)) {
        throw new RuntimeException('Selected database differs from the configured database.');
    }
    if (!is_array($settings['super_admin_ids'] ?? null) || !is_string($settings['bma_secret_key'] ?? null) || $settings['bma_secret_key'] === '') {
        throw new RuntimeException('Missing external authentication settings.');
    }
    $db = (new App\Infrastructure\Persistence\ConnectionFactory($root, 'prod', $config))->open();
    if (!$db->query("SELECT 1 FROM sqlite_schema WHERE type='table' AND name='cases'")->fetchColumn()) {
        throw new RuntimeException('Expected DISC2 base schema.');
    }
    echo "PHP, external configuration and selected SQLite database verified.\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage()."\n");
    exit(1);
}
