<?php
require_once __DIR__.'/settings.php';
if (session_status() === PHP_SESSION_NONE) {
    session_name(disc2DevMode() ? 'disc2_dev_'.substr(hash('sha256',__DIR__),0,12) : 'disc2_uat');
    session_start(['cookie_httponly'=>true, 'cookie_samesite'=>'Lax', 'cookie_secure'=>!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off']);
}
if (!defined('BASE_PATH')) {
    $appDir=rtrim(str_replace('\\','/',__DIR__),'/'); $docRoot=rtrim(str_replace('\\','/',$_SERVER['DOCUMENT_ROOT']??''),'/');
    $base=($docRoot!=='' && strpos($appDir,$docRoot)===0)?substr($appDir,strlen($docRoot)):dirname($_SERVER['SCRIPT_NAME']??'');
    define('BASE_PATH',rtrim($base,'/'));
}
function appUrl($path='') { return BASE_PATH . '/' . ltrim($path,'/'); }
function h($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function requireLogin($adminOnly=false) { if(empty($_SESSION['user_id'])){header('Location: '.appUrl('login.php'));exit;} if($adminOnly && ($_SESSION['user_role']??'')!=='admin' && !isUatSuperAdmin()){http_response_code(403);exit('ไม่มีสิทธิ์');} }
function checkRateLimit(PDO $pdo) { $cutoff=date('Y-m-d H:i:s',strtotime('-15 minutes'));$s=$pdo->prepare("SELECT COUNT(*) FROM login_log WHERE ip=? AND status='fail' AND created_at>=?");$s->execute([$_SERVER['REMOTE_ADDR']??'',$cutoff]);return(int)$s->fetchColumn()>=5?'IP นี้ถูกล็อกชั่วคราว 15 นาที เนื่องจากพยายาม Login ผิดเกิน 5 ครั้ง':null; }
function writeLoginLog(PDO $pdo,$username,$name,$role,$status){$s=$pdo->prepare('INSERT INTO login_log(username,user_name,user_role,ip,user_agent,status) VALUES(?,?,?,?,?,?)');$s->execute([(string)$username,(string)$name,(string)$role,$_SERVER['REMOTE_ADDR']??'',substr($_SERVER['HTTP_USER_AGENT']??'',0,500),(string)$status]);}
function csrfToken() { if(empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(32)); return $_SESSION['csrf']; }
function verifyCsrf() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;
    $expected = $_SESSION['csrf'] ?? '';
    $submitted = $_POST['csrf'] ?? null;
    if ($expected === '' || !is_string($submitted) || !hash_equals($expected,$submitted)) {
        http_response_code(419);
        exit('คำขอหมดอายุ กรุณาลองใหม่');
    }
}
function isUatSuperAdmin() {
    return in_array((string)($_SESSION['user_id']??''), (disc2LiveConfig()['super_admin_ids'] ?? []), true);
}
function canViewSubjectIdentity() {
    return isUatSuperAdmin() || (($_SESSION['user_role']??'') === 'admin');
}
function subjectDisplayName(array $subject) {
    if (canViewSubjectIdentity()) {
        $name=trim(($subject['title_th']??'').' '.($subject['first_name_th']??'').' '.($subject['last_name_th']??''));
        if ($name!=='') return $name;
    }
    return ($subject['reference_code']??'')!=='' ? $subject['reference_code'] : 'ผู้ถูกกล่าวหารายที่ '.($subject['sequence_no']??$subject['id']??'');
}
function uatAccess(PDO $pdo) {
    if(($_SESSION['user_role']??'')==='admin' || isUatSuperAdmin()) return ['access_role'=>'admin','unit_level1'=>'','unit_level2'=>''];
    $stmt=$pdo->prepare('SELECT access_role,unit_level1,unit_level2 FROM uat_access_assignments WHERE bma_personal_id=? AND active=1'); $stmt->execute([(string)($_SESSION['user_id']??'')]);
    $assigned=$stmt->fetch();
    if($assigned) return $assigned;
    // UAT onboarding mode: every successfully authenticated BMA user may create
    // and test their own UAT cases. Production permissions are unchanged because
    // this fallback exists only in /disc2 and still scopes edits to owned cases.
    $unit=(string)($_SESSION['user_unit_level1']??'');
    if($unit!=='') return ['access_role'=>'case_owner','unit_level1'=>$unit,'unit_level2'=>''];
    return ['access_role'=>'none','unit_level1'=>'','unit_level2'=>''];
}
function canEditCase(PDO $pdo,$caseId) { $a=uatAccess($pdo); if($a['access_role']==='admin') return true; if($a['access_role']!=='case_owner') return false; $s=$pdo->prepare('SELECT 1 FROM uat_case_owners WHERE case_id=? AND bma_personal_id=? AND ended_at IS NULL');$s->execute([(int)$caseId,(string)$_SESSION['user_id']]);return(bool)$s->fetchColumn(); }
function canViewCase(PDO $pdo,$caseId) { $a=uatAccess($pdo); if($a['access_role']==='admin')return true; if($a['access_role']==='case_owner')return canEditCase($pdo,$caseId); if($a['access_role']==='group_head'){$s=$pdo->prepare('SELECT 1 FROM uat_case_meta WHERE case_id=? AND unit_level1=? AND unit_level2=?');$s->execute([(int)$caseId,$a['unit_level1'],$a['unit_level2']]);return(bool)$s->fetchColumn();} return false; }
function audit(PDO $pdo,$caseId,$action,$detail=''){$s=$pdo->prepare('INSERT INTO uat_audit_log(case_id,actor_id,actor_name,action,detail) VALUES(?,?,?,?,?)');$s->execute([(int)$caseId,(string)($_SESSION['user_id']??''),(string)($_SESSION['user_name']??''),(string)$action,(string)$detail]);}
