<?php
require __DIR__ . '/auth.php';
require __DIR__ . '/db.php';
requireLogin();
verifyCsrf();

function c2v($v) { return trim((string)$v); }
function c2d($v) { $v=c2v($v); return preg_match('/^\d{4}-\d{2}-\d{2}$/',$v)?$v:''; }
function c2due($start,$days) { return $start && $days>0 ? (new DateTimeImmutable($start))->modify('+' . ($days-1) . ' days')->format('Y-m-d') : ''; }
function c2back($id,$page) { header('Location: '.appUrl('case.php?id='.(int)$id.'&page='.(int)$page.'&saved=1')); exit; }
function c2track(PDO $pdo,$case,$type) { $q=$pdo->prepare('SELECT * FROM uat_tracks WHERE case_id=? AND track_type=?'); $q->execute([$case,$type]); return $q->fetch(); }
function c2case(PDO $pdo,$case) { if (!$case || !canEditCase($pdo,$case)) { http_response_code(403); exit('ไม่มีสิทธิ์แก้ไขสำนวน'); } }
function c2saveTrack(PDO $pdo,$case,$type,$data=[]) {
    $t=c2track($pdo,$case,$type);
    if (!$t) { $pdo->prepare('INSERT INTO uat_tracks(case_id,track_type) VALUES(?,?)')->execute([$case,$type]); $t=c2track($pdo,$case,$type); }
    if ($data) { $sets=[];$vals=[]; foreach($data as $k=>$v){$sets[]="$k=?";$vals[]=$v;} $vals[]=$t['id']; $pdo->prepare('UPDATE uat_tracks SET '.implode(',',$sets).' WHERE id=?')->execute($vals); }
    return c2track($pdo,$case,$type);
}

$action=$_POST['action']??''; $case=(int)($_POST['case_id']??0); c2case($pdo,$case);

if ($action==='page1') {
    $caseFields=['case_no','case_group','received_date','source_detail']; $sets=[];$vals=[];
    foreach($caseFields as $f){$sets[]="$f=?";$vals[]=$f==='received_date'?c2d($_POST[$f]??''):c2v($_POST[$f]??'');}
    $vals[]=$case; $pdo->prepare('UPDATE uat_cases SET '.implode(',',$sets).' WHERE id=?')->execute($vals);
    $pdo->prepare('INSERT INTO uat_case_meta(case_id,case_type,case_type_other,is_test,test_scenario) VALUES(?,?,?,?,?) ON CONFLICT(case_id) DO UPDATE SET case_type=excluded.case_type,case_type_other=excluded.case_type_other')
        ->execute([$case,c2v($_POST['case_type']??''),c2v($_POST['case_type_other']??''),0,'']);
    foreach(($_POST['subjects']??[]) as $s) {
        $sid=(int)($s['id']??0); $values=[c2v($s['reference_code']??''),(int)!empty($s['is_unknown']),c2v($s['employment_type']??''),c2v($s['position_name']??''),c2v($s['level_name']??''),c2v($s['current_status']??''),c2d($s['status_effective_date']??''),c2v($s['note']??'')];
        if ($sid) { $values[]=$sid;$values[]=$case; $pdo->prepare('UPDATE uat_subjects SET reference_code=?,is_unknown=?,employment_type=?,position_name=?,level_name=?,current_status=?,status_effective_date=?,note=? WHERE id=? AND case_id=?')->execute($values); }
        else if (array_filter($values)) { $pdo->prepare("INSERT INTO uat_subjects(case_id,first_name_th,last_name_th,reference_code,is_unknown,employment_type,position_name,level_name,current_status,status_effective_date,note) VALUES(?,'','',?,?,?,?,?,?,?,?,?)")->execute(array_merge([$case],$values)); }
    }
    audit($pdo,$case,'update_case','UAT page 1'); c2back($case,1);
}

if ($action==='fact') {
    $start=c2d($_POST['start_date']??'');$days=max(0,(int)($_POST['duration_days']??0));
    $track=c2saveTrack($pdo,$case,'fact',['process_mode'=>c2v($_POST['process_mode']??''),'process_other'=>c2v($_POST['process_other']??''),'acknowledged_date'=>c2d($_POST['acknowledged_date']??''),'first_meeting_date'=>c2d($_POST['first_meeting_date']??''),'start_date'=>$start,'duration_days'=>$days,'due_date'=>c2due($start,$days),'note'=>c2v($_POST['note']??'')]);
    foreach(($_POST['result']??[]) as $sid=>$r){ $sid=(int)$sid; $pdo->prepare('INSERT OR IGNORE INTO uat_track_subjects(track_id,subject_id) VALUES(?,?)')->execute([$track['id'],$sid]); $pdo->prepare('INSERT INTO uat_subject_findings(track_id,subject_id,result_code,next_action,detail) VALUES(?,?,?,?,?) ON CONFLICT(track_id,subject_id) DO UPDATE SET result_code=excluded.result_code,next_action=excluded.next_action,detail=excluded.detail')->execute([$track['id'],$sid,c2v($r['result_code']??''),c2v($r['next_action']??''),c2v($r['detail']??'')]); }
    audit($pdo,$case,'update_fact','UAT page 2'); c2back($case,2);
}

if ($action==='track_time') {
    $type=in_array($_POST['track_type']??'', ['nonserious','serious'],true)?$_POST['track_type']:'nonserious';$start=c2d($_POST['start_date']??'');$days=max(0,(int)($_POST['duration_days']??0));
    c2saveTrack($pdo,$case,$type,['acknowledged_date'=>c2d($_POST['acknowledged_date']??''),'first_meeting_date'=>c2d($_POST['first_meeting_date']??''),'start_date'=>$start,'duration_days'=>$days,'due_date'=>c2due($start,$days),'note'=>c2v($_POST['note']??'')]);
    audit($pdo,$case,'update_track_time','UAT page 3'); c2back($case,3);
}

if ($action==='extension') {
    $tid=(int)($_POST['track_id']??0);$q=$pdo->prepare('SELECT id,start_date,due_date FROM uat_tracks WHERE id=? AND case_id=?');$q->execute([$tid,$case]);$t=$q->fetch(); if(!$t){http_response_code(422);exit('ไม่พบขั้นตอนสำนวน');}
    $days=max(1,(int)($_POST['requested_days']??0));$base=$t['due_date']?:$t['start_date'];if(!$base){http_response_code(422);exit('กรุณาระบุวันเริ่มต้นก่อนขยายเวลา');}
    $pdo->prepare('INSERT INTO uat_extensions(track_id,requested_days,completed_date,reason) VALUES(?,?,?,?)')->execute([$tid,$days,c2d($_POST['completed_date']??''),c2v($_POST['reason']??'')]);
    $pdo->prepare('UPDATE uat_tracks SET due_date=? WHERE id=?')->execute([c2due($base,$days+1),$tid]); audit($pdo,$case,'add_extension','UAT extension'); c2back($case,(int)($_POST['page']??2));
}

if ($action==='referral') {
    $tid=(int)($_POST['track_id']??0);$sid=(int)($_POST['subject_id']??0);$q=$pdo->prepare('SELECT t.id FROM uat_tracks t JOIN uat_subjects s ON s.case_id=t.case_id WHERE t.id=? AND s.id=? AND t.case_id=?');$q->execute([$tid,$sid,$case]);if(!$q->fetch()){http_response_code(422);exit('ข้อมูลผู้ถูกกล่าวหาไม่ถูกต้อง');}
    $ordered=c2d($_POST['ordered_date']??'');$received=c2d($_POST['received_date']??'');$status=c2v($_POST['status']??'pending'); if(!in_array($status,['pending','reviewing','reporting','continue','finished'],true))$status='pending';
    $pdo->prepare('INSERT INTO uat_referrals(track_id,subject_id,ordered_date,received_date,due_date,status,note) VALUES(?,?,?,?,?,?,?) ON CONFLICT(track_id,subject_id) DO UPDATE SET ordered_date=excluded.ordered_date,received_date=excluded.received_date,due_date=excluded.due_date,status=excluded.status,note=excluded.note')->execute([$tid,$sid,$ordered,$received,c2due($ordered,45),$status,c2v($_POST['note']??'')]);
    audit($pdo,$case,'save_referral','UAT referral');c2back($case,2);
}

if ($action==='committee') {
    $type=in_array($_POST['track_type']??'', ['nonserious','serious'],true)?$_POST['track_type']:'nonserious';$track=c2saveTrack($pdo,$case,$type);$name=c2v($_POST['set_name']??'');$order=c2v($_POST['order_no']??'');$date=c2d($_POST['appointed_date']??'');
    $pdo->prepare('INSERT INTO uat_committee_sets(track_id,set_name,order_no,appointed_date,is_active) VALUES(?,?,?,?,1)')->execute([$track['id'],$name,$order,$date]);$set=(int)$pdo->lastInsertId();
    foreach(($_POST['members']??[]) as $sid){$pdo->prepare('INSERT OR IGNORE INTO uat_committee_set_subjects(committee_set_id,subject_id) VALUES(?,?)')->execute([$set,(int)$sid]);$pdo->prepare('INSERT OR IGNORE INTO uat_track_subjects(track_id,subject_id) VALUES(?,?)')->execute([$track['id'],(int)$sid]);}
    audit($pdo,$case,'add_committee','UAT committee');c2back($case,3);
}

if ($action==='discipline_result') {
    $type=in_array($_POST['track_type']??'', ['nonserious','serious'],true)?$_POST['track_type']:'nonserious';$track=c2track($pdo,$case,$type);if(!$track){http_response_code(422);exit('กรุณาบันทึกชุดคณะกรรมการก่อน');}
    foreach(($_POST['result']??[]) as $sid=>$r){$sid=(int)$sid;$check=$pdo->prepare('SELECT 1 FROM uat_track_subjects WHERE track_id=? AND subject_id=?');$check->execute([$track['id'],$sid]);if(!$check->fetch())continue;$pdo->prepare('INSERT INTO uat_subject_findings(track_id,subject_id,result_code,next_action,detail,action_type,offense_basis) VALUES(?,?,?,?,?,?,?) ON CONFLICT(track_id,subject_id) DO UPDATE SET result_code=excluded.result_code,next_action=excluded.next_action,detail=excluded.detail,action_type=excluded.action_type,offense_basis=excluded.offense_basis')->execute([$track['id'],$sid,c2v($r['result_code']??''),c2v($r['next_action']??''),c2v($r['detail']??''),c2v($r['action_type']??''),c2v($r['offense_basis']??'')]);}
    audit($pdo,$case,'save_discipline_result','UAT page 3');c2back($case,3);
}

if ($action==='decision') {
    foreach(($_POST['decision']??[]) as $sid=>$d){$sid=(int)$sid;$q=$pdo->prepare('SELECT id FROM uat_subjects WHERE id=? AND case_id=?');$q->execute([$sid,$case]);if(!$q->fetch())continue;$pdo->prepare('INSERT INTO uat_decisions(case_id,subject_id,route_type,decision_path,report_kk_date,disciplinary_resolution_date,termination_reason,proposal_letter_number,commander_outcome,other_outcome,other_note,order_no,order_date,completed_date) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?) ON CONFLICT(case_id,subject_id) DO UPDATE SET decision_path=excluded.decision_path,report_kk_date=excluded.report_kk_date,disciplinary_resolution_date=excluded.disciplinary_resolution_date,termination_reason=excluded.termination_reason,proposal_letter_number=excluded.proposal_letter_number,commander_outcome=excluded.commander_outcome,other_outcome=excluded.other_outcome,other_note=excluded.other_note,order_no=excluded.order_no,order_date=excluded.order_date,completed_date=excluded.completed_date')->execute([$case,$sid,'nonserious',c2v($d['decision_path']??''),c2d($d['report_kk_date']??''),c2d($d['disciplinary_resolution_date']??''),c2v($d['termination_reason']??''),c2v($d['proposal_letter_number']??''),c2v($d['commander_outcome']??''),c2v($d['other_outcome']??''),c2v($d['other_note']??''),c2v($d['order_no']??''),c2d($d['order_date']??''),c2d($d['completed_date']??'')]);}
    audit($pdo,$case,'save_decision','UAT page 4');c2back($case,4);
}
http_response_code(422); echo 'ไม่พบคำสั่งบันทึก';
