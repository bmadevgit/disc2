<?php
require __DIR__.'/auth.php';
require __DIR__.'/db.php';
requireLogin();
verifyCsrf();
require __DIR__.'/workflow.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') workflowError('ต้องส่งแบบฟอร์ม',405);
$caseId = (int)($_POST['case_id'] ?? 0);
$trackId = (int)($_POST['track_id'] ?? 0);
$sid = (int)($_POST['subject_id'] ?? 0);
if (!canEditCase($pdo,$caseId)) workflowError('ไม่มีสิทธิ์แก้ไข',403);
beginWorkflowWrite($pdo);
$track = currentDiscipline($pdo,$caseId,$sid);
if (!$track || (int)$track['id'] !== $trackId) workflowError('รอบสอบสวนเปลี่ยนไปแล้ว กรุณาเปิดหน้าปัจจุบัน',409);
$finding = (string)($_POST['finding'] ?? '');
if (!in_array($finding,['','not_guilty','guilty'],true)) workflowError('ผลการสอบสวนไม่ถูกต้อง');
$fields = array_map(fn($field)=>trim((string)($_POST[$field]??'')),array_keys(disciplineFields()));
$serious = $track['track_type'] === 'discipline_serious';
$recommend = !$serious && ($_POST['recommend_serious']??'') === '1';
if ($recommend && $fields[0] === '') workflowError('กรุณาบันทึกความเห็นของคณะกรรมการ');
$pdo->prepare('INSERT INTO uat_subject_findings(track_id,subject_id,finding,severity,committee_opinion,
    appointing_authority_opinion,offense_basis,allegation,recommend_serious) VALUES(?,?,?,?,?,?,?,?,?)
    ON CONFLICT(track_id,subject_id) DO UPDATE SET finding=excluded.finding,severity=excluded.severity,
    committee_opinion=excluded.committee_opinion,appointing_authority_opinion=excluded.appointing_authority_opinion,
    offense_basis=excluded.offense_basis,allegation=excluded.allegation,recommend_serious=excluded.recommend_serious')
    ->execute([$trackId,$sid,$finding,$serious?'serious':'nonserious',...$fields,(int)$recommend]);
audit($pdo,$caseId,'save_discipline_findings',$sid.':'.$trackId);
$pdo->commit();
header('Location: '.appUrl(trackUrl($track,'discipline_results.php').'&saved=1'));
exit;
