<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
function disc2Migrate(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS uat_schema_migrations (version TEXT PRIMARY KEY, applied_at TEXT DEFAULT CURRENT_TIMESTAMP)");
    $done = $pdo->query('SELECT version FROM uat_schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
    foreach (glob(__DIR__.'/migrations/*.sql') as $file) {
        $version = basename($file, '.sql');
        if (in_array($version, $done, true)) continue;
        $rebuild = $version === '004_workflow';
        if ($rebuild) $pdo->exec('PRAGMA foreign_keys = OFF');
        $pdo->beginTransaction();
        try {
            $pdo->exec(file_get_contents($file));
            if ($rebuild && $pdo->query('PRAGMA foreign_key_check')->fetch()) {
                throw new RuntimeException('Foreign key check failed');
            }
            $pdo->prepare('INSERT OR IGNORE INTO uat_schema_migrations(version) VALUES(?)')->execute([$version]);
            $pdo->commit();
            echo "Applied $version\n";
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        } finally {
            if ($rebuild) $pdo->exec('PRAGMA foreign_keys = ON');
        }
    }
}
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    require __DIR__.'/db.php';
    disc2Migrate($pdo);
}
