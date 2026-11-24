<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use PDO;
use RuntimeException;
use Symfony\Component\Filesystem\Path;

final readonly class ConnectionFactory
{
    public function __construct(
        private string $projectDir,
        private string $environment,
        private string $configFile = 'C:/inetpub/private/disc2-config.php',
    ) {
    }

    public function open(): PDO
    {
        $path = in_array($this->environment, ['dev', 'test'], true)
            ? $this->projectDir.'/runtime/disc2-dev.sqlite'
            : $this->externalDatabasePath();
        $path = realpath($path);
        if ($path === false || !is_file($path)) {
            throw new RuntimeException('The configured SQLite database file does not exist.');
        }
        $public = realpath($this->projectDir.'/public');
        if ($public !== false) {
            $prefix = $public.DIRECTORY_SEPARATOR;
            $insidePublic = PHP_OS_FAMILY === 'Windows' ? strncasecmp($path, $prefix, strlen($prefix)) === 0 : str_starts_with($path, $prefix);
            if ($insidePublic) {
                throw new RuntimeException('The database must be outside the public directory.');
            }
        }
        $db = new PDO('sqlite:'.$path, options: [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // READWRITE without CREATE also protects against removal after realpath().
            (PHP_VERSION_ID >= 80400 ? \Pdo\Sqlite::ATTR_OPEN_FLAGS : PDO::SQLITE_ATTR_OPEN_FLAGS)
                => PHP_VERSION_ID >= 80400 ? \Pdo\Sqlite::OPEN_READWRITE : PDO::SQLITE_OPEN_READWRITE,
        ]);
        $db->exec('PRAGMA busy_timeout=5000');
        $db->exec('PRAGMA foreign_keys=ON');
        if ($db->query('PRAGMA journal_mode=WAL')->fetchColumn() !== 'wal') {
            throw new RuntimeException('Could not enable SQLite WAL mode.');
        }
        return $db;
    }

    private function externalDatabasePath(): string
    {
        $config = is_file($this->configFile) ? require $this->configFile : [];
        $path = $config['database_path'] ?? null;
        if (!is_string($path) || !Path::isAbsolute($path)) {
            throw new RuntimeException('Set an absolute database_path in the external DISC2 configuration.');
        }
        return $path;
    }
}
