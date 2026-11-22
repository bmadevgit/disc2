<?php
/** DISC2 UAT database connection. Schema changes are only run from migrations/. */
require_once __DIR__.'/settings.php';
$dbFile = __DIR__.(disc2DevMode() ? '/runtime/disc2-dev.sqlite' : '/data/disc_uat.db');
if (!is_file($dbFile)) {
    http_response_code(500);
    exit('ไม่พบฐานข้อมูล UAT');
}
$pdo = new PDO('sqlite:' . $dbFile, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec('PRAGMA busy_timeout = 5000');
$pdo->exec('PRAGMA journal_mode = WAL');
