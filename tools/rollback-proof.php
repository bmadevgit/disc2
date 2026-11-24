<?php

declare(strict_types=1);

// Run outside the old document root after freezing writes. Both connections stay read-only during comparison.
if (PHP_SAPI !== 'cli' || $argc !== 4 || !ctype_digit($argv[3])) {
    fwrite(STDERR, "Usage: php rollback-proof.php PRIOR_RELEASE CURRENT_FROZEN_BACKUP PRE_CUTOVER_MAX_AUDIT_ID\n");
    exit(1);
}
$complete = false;
register_shutdown_function(static function () use (&$complete): void {
    // Some procedural connection errors exit with a message and a success status.
    if (!$complete) {
        fwrite(STDERR, "Rollback proof incomplete. Keep writes frozen.\n");
        exit(1);
    }
});
try {
    $previous = realpath($argv[1].'/db.php');
    $backup = realpath($argv[2]);
    if (!$previous || !$backup || !is_file($backup)) {
        throw new RuntimeException('Select the prior code and the frozen online backup.');
    }
    putenv('DISC2_DEV=0');
    $old = (static function (string $entry): PDO {
        require $entry;
        return $pdo;
    })($previous);
    $livePath = $old->query('PRAGMA database_list')->fetch(PDO::FETCH_ASSOC)['file'];
    if (realpath($livePath) === $backup) {
        throw new RuntimeException('The prior release must open the retained live database.');
    }
    $frozen = new PDO('sqlite:'.$backup, options: [
        (PHP_VERSION_ID >= 80400 ? Pdo\Sqlite::ATTR_OPEN_FLAGS : PDO::SQLITE_ATTR_OPEN_FLAGS)
            => PHP_VERSION_ID >= 80400 ? Pdo\Sqlite::OPEN_READONLY : PDO::SQLITE_OPEN_READONLY,
    ]);
    foreach ([$old, $frozen] as $db) {
        $db->exec('PRAGMA query_only=ON');
        $db->beginTransaction();
        if ($db->query('PRAGMA integrity_check')->fetchAll(PDO::FETCH_COLUMN) !== ['ok'] || $db->query('PRAGMA foreign_key_check')->fetch() !== false) {
            throw new RuntimeException('SQLite integrity or foreign-key check failed.');
        }
    }
    $tables = static fn (PDO $db): array => $db->query("SELECT name,sql FROM sqlite_schema WHERE type='table' ORDER BY name")->fetchAll(PDO::FETCH_KEY_PAIR);
    $schema = $tables($frozen);
    if (!$schema || $tables($old) !== $schema) {
        throw new RuntimeException('Prior release sees a different table schema.');
    }
    $quote = static fn (string $id): string => '"'.str_replace('"', '""', $id).'"';
    foreach (array_keys($schema) as $table) {
        $name = $quote($table);
        $columns = $frozen->query("PRAGMA table_info($name)")->fetchAll(PDO::FETCH_ASSOC);
        $order = implode(', ', array_map(static fn ($c) => $quote($c['name']).' COLLATE BINARY', $columns));
        $query = "SELECT * FROM $name ORDER BY $order";
        $a = $old->query($query);
        $b = $frozen->query($query);
        do {
            $row = $a->fetch(PDO::FETCH_NUM);
            if ($row !== $b->fetch(PDO::FETCH_NUM)) {
                throw new RuntimeException("Frozen records differ in $table");
            }
        } while ($row !== false);
    }
    $audits = $old->prepare('SELECT count(*) FROM uat_audit_log WHERE id > ?');
    $audits->execute([(int)$argv[3]]);
    echo count($schema).' tables matched through the prior release, including '.$audits->fetchColumn()." post-cutover audits.\n";
    $complete = true;
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage()."\n");
    exit(1);
}
