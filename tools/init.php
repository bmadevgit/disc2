<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__).'/migrate.php';

$runtime = dirname(__DIR__).'/runtime';
$dbPath = $runtime.'/disc2-dev.sqlite';
$credentialsPath = $runtime.'/demo-accounts.json';
date_default_timezone_set('Asia/Bangkok');
if (!is_dir($runtime) && !mkdir($runtime,0700,true) && !is_dir($runtime)) {
    throw new RuntimeException('Could not create the runtime directory.');
}
$lockPath = $runtime.'/init.lock';
$lock = @fopen($lockPath,'x');
if (!$lock) {
    fwrite(STDERR,"Initialization is already running or interrupted. Check runtime/init.lock.\n");
    exit(1);
}
$temp = null;
$pdo = null;
try {
    if (file_exists($dbPath) || file_exists($credentialsPath)) {
        throw new RuntimeException('Demo data already exists. Use a fresh clone for a second demo database.');
    }
    if (!extension_loaded('pdo_sqlite')) throw new RuntimeException('Enable the PHP pdo_sqlite extension.');
    $temp = tempnam($runtime,'new-demo-');
    if ($temp === false) throw new RuntimeException('Could not create a temporary database.');

    $pdo = new PDO('sqlite:'.$temp,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $pdo->exec('PRAGMA foreign_keys=ON');
    $pdo->exec(file_get_contents(__DIR__.'/base-schema.sql'));
    disc2Migrate($pdo);

    $pdo->beginTransaction();
    $accounts = [];
    $user = $pdo->prepare('INSERT INTO users(username,name,password_hash,role) VALUES(?,?,?,?)');
    $assign = $pdo->prepare('INSERT INTO uat_access_assignments(bma_personal_id,display_name,access_role,unit_level1,unit_level2) VALUES(?,?,?,?,?)');
    $unit = 'สำนักงานตัวอย่าง';
    $group = 'กลุ่มงานวินัยตัวอย่าง';
    foreach ([
        'demo-admin'=>['ผู้ดูแลระบบสมมติ','admin',''],
        'demo-owner'=>['เจ้าของสำนวนสมมติ','user','case_owner'],
        'demo-reader'=>['หัวหน้ากลุ่มงานสมมติ','user','group_head'],
    ] as $username=>[$name,$role,$access]) {
        $password = bin2hex(random_bytes(10));
        $accounts[$username] = $password;
        $user->execute([$username,$name,password_hash($password,PASSWORD_DEFAULT),$role]);
        $userId = $pdo->lastInsertId();
        if ($access !== '') $assign->execute([$userId,$name,$access,$unit,$group]);
        if ($access === 'case_owner') $ownerId = $userId;
    }

    $pdo->prepare('INSERT INTO cases (เลขที่สำนวน,หน่วยงานต้นเรื่อง,unit_level1,submitted_by_id) VALUES(?,?,?,?)')
        ->execute(['DEMO-001',$unit,$unit,$ownerId]);
    $caseId = $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO uat_case_meta (case_id,responsible_unit,unit_level1,unit_level2,case_type,is_test) VALUES(?,?,?,?,?,1)')
        ->execute([$caseId,$unit,$unit,$group,'การเงินและบัญชี']);
    $pdo->prepare('INSERT INTO uat_case_owners(case_id,bma_personal_id,owner_name) VALUES(?,?,?)')
        ->execute([$caseId,$ownerId,'เจ้าของสำนวนสมมติ']);
    $subject = $pdo->prepare('INSERT INTO uat_subjects(case_id,sequence_no,first_name_th,reference_code) VALUES(?,?,?,?)');
    $subject->execute([$caseId,1,'บุคคลสมมติ ก','REF-DEMO-1']);
    $subject->execute([$caseId,2,'บุคคลสมมติ ข','REF-DEMO-2']);
    $pdo->commit();
    unset($user,$assign,$subject);
    if ($pdo->query('PRAGMA integrity_check')->fetchColumn() !== 'ok' || $pdo->query('PRAGMA foreign_key_check')->fetch()) {
        throw new RuntimeException('Database integrity check failed.');
    }
    $pdo = null;
    $credentials = json_encode($accounts,JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
    if (file_put_contents($credentialsPath,$credentials,LOCK_EX) === false) throw new RuntimeException('Could not write demo credentials.');
    if (!rename($temp,$dbPath)) {
        unlink($credentialsPath);
        throw new RuntimeException('Could not publish the demo database.');
    }
    echo "Demo data is ready. Open runtime/demo-accounts.json for your test logins.\n";
} catch (Throwable $error) {
    fwrite(STDERR,$error->getMessage().PHP_EOL);
    $failed = true;
} finally {
    $pdo = null;
    if (is_string($temp) && is_file($temp)) unlink($temp);
    fclose($lock);
    unlink($lockPath);
}
exit(empty($failed) ? 0 : 1);
