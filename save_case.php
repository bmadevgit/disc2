<?php
require __DIR__.'/auth.php'; require __DIR__.'/db.php'; requireLogin(); verifyCsrf(); require __DIR__.'/workflow.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') workflowError('ต้องส่งแบบฟอร์ม',405);
function clean($v){return trim((string)$v);} function dateValue($v){return workflowDate($v);} function go($id,$page=1){header('Location: '.appUrl('case.php?id='.(int)$id.'&page='.(int)$page.'&saved=1'));exit;} function track(PDO $pdo,$caseId,$type){$s=$pdo->prepare('SELECT * FROM uat_tracks WHERE case_id=? AND track_type=? AND set_no=1');$s->execute([(int)$caseId,(string)$type]);return $s->fetch()?:null;}
function requireEditor(PDO $pdo,$caseId=0){$a=uatAccess($pdo);if($caseId? !canEditCase($pdo,$caseId):!in_array($a['access_role'],['admin','case_owner'],true)){http_response_code(403);exit('ไม่มีสิทธิ์แก้ไข');}return$a;}
$action=$_POST['action']??'';
if($action==='create_case'){
 $a=requireEditor($pdo);$number=clean($_POST['case_number']??'');if($number===''){exit('กรุณาระบุเลขที่สำนวน');}
 $unit=$a['access_role']==='admin'?clean($_POST['responsible_unit']??''):($a['unit_level1']?:clean($_SESSION['user_unit_level1']??''));
 $pdo->beginTransaction();try{$s=$pdo->prepare('INSERT INTO cases (`เลขที่สำนวน`,`หน่วยงานต้นเรื่อง`,unit_level1,`เจ้าหน้าที่รับผิดชอบ`,submitted_by_id,submitted_by_name) VALUES (?,?,?,?,?,?)');$s->execute([$number,$unit,$unit,clean($_SESSION['user_name']??''),clean($_SESSION['user_id']??''),clean($_SESSION['user_name']??'')]);$id=(int)$pdo->lastInsertId();$m=$pdo->prepare('INSERT INTO uat_case_meta(case_id,responsible_unit,unit_level1,unit_level2,received_date,preliminary_allegation) VALUES(?,?,?,?,?,?)');$m->execute([$id,$unit,$unit,clean($_POST['unit_level2']??''),dateValue($_POST['received_date']??''),clean($_POST['preliminary_allegation']??'')]);saveCaseType($pdo,$id);$o=$pdo->prepare('INSERT INTO uat_case_owners(case_id,bma_personal_id,owner_name) VALUES(?,?,?)');$o->execute([$id,clean($_SESSION['user_id']??''),clean($_SESSION['user_name']??'')]);audit($pdo,$id,'create_case');$pdo->commit();go($id,1);}catch(Throwable $e){$pdo->rollBack();throw$e;}
}
$caseId=(int)($_POST['case_id']??0);
if ($caseId < 1) workflowError('ไม่พบสำนวน',404);
$a=requireEditor($pdo,$caseId);
require __DIR__.'/save_workflow.php';
if($action==='save_page1'){
 $unit=$a['access_role']==='admin'?clean($_POST['responsible_unit']??''):($a['unit_level1']?:clean($_SESSION['user_unit_level1']??''));
 $pdo->beginTransaction();try{$pdo->prepare('UPDATE cases SET `เลขที่สำนวน`=?,`หน่วยงานต้นเรื่อง`=?,unit_level1=? WHERE id=?')->execute([clean($_POST['case_number']??''),$unit,$unit,$caseId]);$m=$pdo->prepare('INSERT INTO uat_case_meta(case_id,responsible_unit,unit_level1,unit_level2,source_type,source_other,received_date,preliminary_allegation,updated_at) VALUES(?,?,?,?,?,?,?,?,CURRENT_TIMESTAMP) ON CONFLICT(case_id) DO UPDATE SET responsible_unit=excluded.responsible_unit,unit_level1=excluded.unit_level1,unit_level2=excluded.unit_level2,source_type=excluded.source_type,source_other=excluded.source_other,received_date=excluded.received_date,preliminary_allegation=excluded.preliminary_allegation,updated_at=CURRENT_TIMESTAMP');$m->execute([$caseId,$unit,$unit,clean($_POST['unit_level2']??''),clean($_POST['source_type']??''),clean($_POST['source_other']??''),dateValue($_POST['received_date']??''),clean($_POST['preliminary_allegation']??'')]);
 saveCaseType($pdo,$caseId);
 foreach(($_POST['subjects']??[]) as $n=>$sub){
  $first=clean($sub['first']??''); if($first==='') continue;
  $sid=(int)($sub['id']??0); $nationalId=preg_replace('/\D/','',clean($sub['national_id']??''));
  if($nationalId!=='' && strlen($nationalId)!==13) throw new InvalidArgumentException('เลขบัตรประชาชนผู้ถูกกล่าวหาต้องมี 13 หลัก');
  $vals=[clean($sub['title']??''),$first,clean($sub['last']??''),clean($sub['position']??''),clean($sub['employment']??'')];
  if($sid){
   $chk=$pdo->prepare('SELECT national_id FROM uat_subjects WHERE id=? AND case_id=?');$chk->execute([$sid,$caseId]);$current=$chk->fetch();
   if($current){$storedNationalId=$nationalId!==''?$nationalId:$current['national_id'];$pdo->prepare('UPDATE uat_subjects SET title_th=?,first_name_th=?,last_name_th=?,position_name=?,employment_type=?,national_id=? WHERE id=?')->execute(array_merge($vals,[$storedNationalId,$sid]));}else $sid=0;
  }
  if(!$sid){$ins=$pdo->prepare('INSERT INTO uat_subjects(case_id,sequence_no,title_th,first_name_th,last_name_th,position_name,employment_type,national_id,reference_code) VALUES(?,?,?,?,?,?,?,?,?)');$ins->execute(array_merge([$caseId,(int)$n+1],$vals,[$nationalId,'REF-'.$caseId.'-'.((int)$n+1)]));$sid=(int)$pdo->lastInsertId();}
  $status=clean($sub['status']??'');if($status!==''){$last=$pdo->prepare('SELECT status_name FROM uat_subject_status_history WHERE subject_id=? ORDER BY id DESC LIMIT 1');$last->execute([$sid]);if($last->fetchColumn()!==$status)$pdo->prepare('INSERT INTO uat_subject_status_history(subject_id,status_name,effective_date,reason) VALUES(?,?,?,?)')->execute([$sid,$status,dateValue($sub['status_date']??''),clean($sub['status_reason']??'')]);}
 }
 audit($pdo,$caseId,'save_case_info');$pdo->commit();go($caseId,1);}catch(Throwable $e){$pdo->rollBack();throw$e;}
}
if($action==='save_fact'){
 $days = filter_var($_POST['duration_days'] ?? 30, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1,'max_range'=>365000]]);
 if ($days === false) workflowError('กรุณาระบุจำนวนวันเป็นจำนวนเต็มบวก');
 $values = deadlineValues('fact',$days,$_POST);
 beginWorkflowWrite($pdo);
 try {
 $pdo->prepare("INSERT OR IGNORE INTO uat_tracks(case_id,track_type) VALUES(?,'fact')")->execute([$caseId]);
 $tr=track($pdo,$caseId,'fact');
 if (!sameDeadlineInputs($tr,$values)) saveTrackDates($pdo,$tr,$values,'save_fact_deadline');
 $subjectRows = workflowRows($pdo,'SELECT id FROM uat_subjects WHERE case_id=? AND active=1',[$caseId]);
 foreach($subjectRows as $s){$sid=$s['id'];$pdo->prepare('INSERT OR IGNORE INTO uat_track_subjects(track_id,subject_id) VALUES(?,?)')->execute([$tr['id'],$sid]);$f=clean($_POST['finding'][$sid]??'');$sev=clean($_POST['severity'][$sid]??'');$detail=clean($_POST['finding_detail'][$sid]??'');$pdo->prepare('INSERT INTO uat_subject_findings(track_id,subject_id,finding,severity,allegation_detail) VALUES(?,?,?,?,?) ON CONFLICT(track_id,subject_id) DO UPDATE SET finding=excluded.finding,severity=excluded.severity,allegation_detail=excluded.allegation_detail')->execute([$tr['id'],$sid,$f,$sev,$detail]);}$q=$pdo->prepare('SELECT COUNT(*) total,SUM(CASE WHEN finding="no_ground" THEN 1 ELSE 0 END) no_ground FROM uat_subject_findings WHERE track_id=? AND subject_id IN (SELECT id FROM uat_subjects WHERE case_id=? AND active=1)');$q->execute([$tr['id'],$caseId]);$c=$q->fetch();$inDiscipline = workflowRows($pdo,"SELECT 1 FROM uat_track_subjects ts JOIN uat_tracks t ON t.id=ts.track_id
 WHERE t.case_id=? AND t.track_type<>'fact' AND ts.ended_at IS NULL LIMIT 1",[$caseId]) || (!$subjectRows && workflowRows($pdo,"SELECT 1 FROM uat_tracks WHERE case_id=? AND track_type<>'fact' LIMIT 1",[$caseId]));
 $stage=$inDiscipline ? 'discipline' : (((int)$c['total']>0 && (int)$c['total']===(int)$c['no_ground'])?'closed':'fact_finding');$pdo->prepare('UPDATE uat_case_meta SET stage=?,closed_at=? WHERE case_id=?')->execute([$stage,$stage==='closed'?date('Y-m-d'):null,$caseId]);audit($pdo,$caseId,'save_fact_finding');$pdo->commit();go($caseId,2);}catch(Throwable$e){$pdo->rollBack();throw$e;}
}

if ($action === 'add_order' || $action === 'add_extension') {
    $trackId = (int)($_POST['track_id'] ?? 0);
    beginWorkflowWrite($pdo);
    $track = workflowRows($pdo,'SELECT * FROM uat_tracks WHERE id=? AND case_id=?',[$trackId,$caseId])[0] ?? [];
    if (!$track) workflowError('ไม่พบชุดสอบสวน',404);
    if ($action === 'add_order') {
        $event = clean($_POST['event_type'] ?? '');
        if (!in_array($event,['appoint','change_committee','amend_order'],true)) workflowError('ประเภทรายการไม่ถูกต้อง');
        $pdo->prepare('INSERT INTO uat_committee_orders(track_id,event_type,order_date,order_number,detail) VALUES(?,?,?,?,?)')
            ->execute([$trackId,$event,dateValue($_POST['order_date']??''),clean($_POST['order_number']??''),clean($_POST['detail']??'')]);
    } else {
        $days = filter_var($_POST['requested_days'] ?? '', FILTER_VALIDATE_INT, ['options'=>['min_range'=>1,'max_range'=>365000]]);
        if ($days === false) workflowError('กรุณาระบุจำนวนวันที่ขยายเป็นจำนวนเต็มบวก');
        if ($track['due_date'] === '') workflowError('กรุณาบันทึกกำหนดเวลาก่อนขยายเวลา');
        $extensions = trackExtensions($pdo,$trackId);
        $last = end($extensions);
        $pdo->prepare('INSERT INTO uat_extensions(track_id,sequence_no,requested_days,completed_work,reason,new_due_date) VALUES(?,?,?,?,?,?)')
            ->execute([$trackId,($last['sequence_no']??0)+1,$days,clean($_POST['completed_work']??''),clean($_POST['reason']??''),
                shiftDate(($last['new_due_date'] ?? '') ?: $track['due_date'],$days)]);
    }
    audit($pdo,$caseId,$action,(string)$trackId);
    $pdo->commit();
    if ($track['track_type'] === 'fact') go($caseId,2);
    trackRedirect($track,'&saved=1');
}
workflowError('ไม่พบรายการที่ต้องการบันทึก',400);
