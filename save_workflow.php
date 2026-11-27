<?php
// Included by save_case.php after authentication, CSRF and case edit checks.
if ($action === 'save_track' || $action === 'convert_serious_deadline') {
    $type = (string)($_POST['track_type'] ?? '');
    if (!in_array($type,['discipline_nonserious','discipline_serious'],true)) workflowError('ประเภทการสอบสวนไม่ถูกต้อง');
    beginWorkflowWrite($pdo);
    $track = disciplineTrack($pdo,$caseId,$type,(int)($_POST['track_id'] ?? 0));
    $days = $type === 'discipline_serious' ? ((int)$track['duration_days'] === 180 ? 180 : 120) : 60;
    if ($action === 'convert_serious_deadline') {
        $extensions = trackExtensions($pdo,(int)$track['id']);
        if ($days !== 180 || !hash_equals(deadlineRevision($track,$extensions),(string)($_POST['revision'] ?? ''))) {
            workflowError('กำหนดเวลามีการเปลี่ยนแปลง กรุณาเปิดหน้าตรวจสอบอีกครั้ง',409);
        }
        $reason = trim((string)($_POST['reason'] ?? ''));
        if ($reason === '') workflowError('กรุณาระบุเหตุผลที่ปรับระยะเวลา');
        $values = conversionValues($track);
        saveTrackDates($pdo,$track,$values,$action,$reason);
    } else {
        $values = deadlineValues($type,$days,$_POST);
        if (!sameDeadlineInputs($track,$values)) saveTrackDates($pdo,$track,$values,$action);
    }
    $pdo->commit();
    trackRedirect($track,'&saved=1');
}

if ($action === 'open_discipline_track') {
    $type = (string)($_POST['track_type'] ?? '');
    if (!in_array($type,['discipline_nonserious','discipline_serious'],true)) workflowError('ประเภทการสอบสวนไม่ถูกต้อง');
    beginWorkflowWrite($pdo);
    $pdo->prepare('INSERT OR IGNORE INTO uat_tracks(case_id,track_type,duration_days) VALUES(?,?,?)')
        ->execute([$caseId,$type,$type === 'discipline_serious' ? 120 : 60]);
    $pdo->prepare("UPDATE uat_case_meta SET stage='discipline' WHERE case_id=?")->execute([$caseId]);
    audit($pdo,$caseId,'open_discipline_track',$type);
    $pdo->commit();
    go($caseId,3);
}

if ($action === 'assign_discipline') {
    beginWorkflowWrite($pdo);
    $subjects = workflowRows($pdo, "SELECT s.id FROM uat_subjects s
        JOIN uat_subject_findings f ON f.subject_id=s.id AND f.finding='ground'
        JOIN uat_tracks t ON t.id=f.track_id AND t.case_id=s.case_id AND t.track_type='fact'
        WHERE s.case_id=? AND s.active=1",[$caseId]);
    $assigned = false;
    foreach ($subjects as $subject) {
        $sid = (int)$subject['id'];
        $type = $_POST['track_for'][$sid] ?? '';
        if (!in_array($type,['discipline_nonserious','discipline_serious'],true) || currentDiscipline($pdo,$caseId,$sid)) continue;
        $pdo->prepare('INSERT OR IGNORE INTO uat_tracks(case_id,track_type,duration_days) VALUES(?,?,?)')
            ->execute([$caseId,$type,$type === 'discipline_serious' ? 120 : 60]);
        $track = disciplineTrack($pdo,$caseId,$type,0);
        $pdo->prepare('INSERT INTO uat_track_subjects(track_id,subject_id) VALUES(?,?)')->execute([$track['id'],$sid]);
        $assigned = true;
    }
    if ($assigned) {
        $pdo->prepare("UPDATE uat_case_meta SET stage='discipline' WHERE case_id=?")->execute([$caseId]);
        audit($pdo,$caseId,'assign_discipline');
    }
    $pdo->commit();
    go($caseId,3);
}

if ($action === 'escalate_discipline') {
    $sid = (int)($_POST['subject_id'] ?? 0);
    $from = (int)($_POST['track_id'] ?? 0);
    $date = workflowDate($_POST['effective_date'] ?? '');
    $reference = trim((string)($_POST['reference'] ?? ''));
    if (!$date || $reference === '') workflowError('กรุณาระบุวันที่และหลักฐานคำสั่ง');
    beginWorkflowWrite($pdo);
    $existing = workflowRows($pdo,'SELECT to_track_id FROM uat_escalations WHERE subject_id=? AND from_track_id=?',[$sid,$from])[0] ?? [];
    if ($existing) {
        $target = disciplineTrack($pdo,$caseId,'discipline_serious',(int)$existing['to_track_id']);
        $pdo->commit();
        trackRedirect($target,'&saved=1');
    }
    $track = currentDiscipline($pdo,$caseId,$sid);
    if (!$track || (int)$track['id'] !== $from || $track['track_type'] !== 'discipline_nonserious') {
        workflowError('รอบสอบสวนของบุคคลนี้เปลี่ยนไปแล้ว',409);
    }
    $finding = workflowRows($pdo,'SELECT * FROM uat_subject_findings WHERE track_id=? AND subject_id=?',[$from,$sid])[0] ?? [];
    if (empty($finding['recommend_serious']) || trim($finding['committee_opinion']) === '') {
        workflowError('กรุณาบันทึกความเห็น คกก. ที่เสนอให้สอบสวนร้ายแรงก่อน');
    }
    $destination = (string)($_POST['target_track'] ?? '');
    if ($destination === 'new') {
        $pdo->prepare("INSERT INTO uat_tracks(case_id,track_type,duration_days,set_no)
            SELECT ?,'discipline_serious',120,COALESCE(MAX(set_no),0)+1 FROM uat_tracks
            WHERE case_id=? AND track_type='discipline_serious'")->execute([$caseId,$caseId]);
        $destination = $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO uat_committee_orders(track_id,event_type,order_date,order_number) VALUES(?,'appoint',?,?)")
            ->execute([$destination,$date,$reference]);
    }
    if (!ctype_digit($destination) || (int)$destination < 1) workflowError('กรุณาเลือกชุด คกก. ร้ายแรง');
    $target = disciplineTrack($pdo,$caseId,'discipline_serious',(int)$destination);
    $decisions = workflowRows($pdo,'SELECT * FROM uat_decisions WHERE case_id=? AND subject_id=?
        AND (track_id=? OR (track_id IS NULL AND route_type=?))',[$caseId,$sid,$from,'nonserious']);
    foreach ($decisions as &$decision) {
        $decision['reports'] = workflowRows($pdo,'SELECT * FROM uat_kk_reports WHERE decision_id=? ORDER BY sequence_no',[$decision['id']]);
    }
    unset($decision);
    $snapshot = json_encode([
        'track'=>$track,'orders'=>trackOrders($pdo,$from),'extensions'=>trackExtensions($pdo,$from),
        'members'=>workflowRows($pdo,'SELECT * FROM uat_track_subjects WHERE track_id=?',[$from]),
        'committee_sets'=>workflowRows($pdo,'SELECT * FROM uat_committee_sets WHERE track_id=?',[$from]),
        'committee_members'=>workflowRows($pdo,'SELECT m.* FROM uat_committee_set_subjects m
            JOIN uat_committee_sets t ON t.id=m.committee_set_id WHERE t.track_id=?',[$from]),
        'finding'=>$finding,'decisions'=>$decisions,
    ],JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $pdo->prepare('UPDATE uat_track_subjects SET ended_at=CURRENT_TIMESTAMP WHERE track_id=? AND subject_id=?')->execute([$from,$sid]);
    $pdo->prepare('UPDATE uat_committee_set_subjects SET ended_at=CURRENT_TIMESTAMP WHERE subject_id=? AND ended_at IS NULL
        AND committee_set_id IN (SELECT id FROM uat_committee_sets WHERE track_id=?)')->execute([$sid,$from]);
    $pdo->prepare('INSERT INTO uat_track_subjects(track_id,subject_id) VALUES(?,?)')->execute([$target['id'],$sid]);
    $pdo->prepare('INSERT INTO uat_escalations(subject_id,from_track_id,to_track_id,effective_date,reference,snapshot,actor_id,actor_name)
        VALUES(?,?,?,?,?,?,?,?)')->execute([$sid,$from,$target['id'],$date,$reference,$snapshot,(string)$_SESSION['user_id'],(string)($_SESSION['user_name']??'')]);
    audit($pdo,$caseId,$action,json_encode(['subject_id'=>$sid,'before'=>$from,'after'=>$target['id'],'date'=>$date,'reference'=>$reference],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
    $pdo->commit();
    trackRedirect($target,'&saved=1');
}

if ($action === 'save_decision') {
    $sid = (int)($_POST['subject_id'] ?? 0);
    beginWorkflowWrite($pdo);
    $track = currentDiscipline($pdo,$caseId,$sid);
    if (!$track || (int)($_POST['track_id'] ?? 0) !== (int)$track['id']) workflowError('กรุณาเปิดหน้าคำสั่งของรอบปัจจุบันอีกครั้ง',409);
    $route = $track['track_type'] === 'discipline_serious' ? 'serious' : 'nonserious';
    $resolution = clean($_POST['kk_resolution'] ?? '');
    $resolutionDate = workflowDate($_POST['kk_resolution_date'] ?? '');
    $order = clean($_POST['order_number'] ?? '');
    $orderDate = workflowDate($_POST['order_date'] ?? '');
    if ($route === 'serious' && ($order !== '' || $orderDate !== '') && ($resolution === '' || $resolutionDate === '')) {
        workflowError('กรณีร้ายแรงต้องบันทึกวันที่และมติ ก.ก. ก่อนออกคำสั่ง');
    }
    $pdo->prepare('INSERT INTO uat_decisions(track_id,case_id,subject_id,route_type,report_kk_date,kk_resolution_date,kk_resolution,
        commander_decision,order_number,order_date,finished_date) VALUES(?,?,?,?,?,?,?,?,?,?,?)
        ON CONFLICT(track_id,subject_id) DO UPDATE SET report_kk_date=excluded.report_kk_date,kk_resolution_date=excluded.kk_resolution_date,
        kk_resolution=excluded.kk_resolution,commander_decision=excluded.commander_decision,order_number=excluded.order_number,
        order_date=excluded.order_date,finished_date=excluded.finished_date')
        ->execute([$track['id'],$caseId,$sid,$route,workflowDate($_POST['report_kk_date']??''),$resolutionDate,$resolution,
            clean($_POST['commander_decision']??''),$order,$orderDate,workflowDate($_POST['finished_date']??'')]);
    audit($pdo,$caseId,$action,$sid.':'.$track['id']);
    $pdo->commit();
    go($caseId,4);
}

if ($action === 'save_track_decision') {
    $trackId = (int)($_POST['track_id'] ?? 0);
    beginWorkflowWrite($pdo);
    $track = workflowRows($pdo,"SELECT * FROM uat_tracks WHERE id=? AND case_id=? AND track_type<>'fact'",[$trackId,$caseId])[0] ?? [];
    if (!$track) workflowError('ไม่พบกระบวนการสอบสวน',404);
    $route = $track['track_type'] === 'discipline_serious' ? 'serious' : 'nonserious';
    $resolution = clean($_POST['kk_resolution'] ?? '');
    $resolutionDate = workflowDate($_POST['kk_resolution_date'] ?? '');
    $order = clean($_POST['order_number'] ?? '');
    $orderDate = workflowDate($_POST['order_date'] ?? '');
    if ($route === 'serious' && ($order !== '' || $orderDate !== '') && ($resolution === '' || $resolutionDate === '')) {
        workflowError('กรณีร้ายแรงต้องบันทึกวันที่และมติ ก.ก. ก่อนออกคำสั่ง');
    }
    $pdo->prepare('INSERT INTO uat_track_decisions(case_id,track_id,route_type,report_kk_date,kk_resolution_date,kk_resolution,
        commander_decision,order_number,order_date,finished_date,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,CURRENT_TIMESTAMP)
        ON CONFLICT(track_id) DO UPDATE SET report_kk_date=excluded.report_kk_date,kk_resolution_date=excluded.kk_resolution_date,
        kk_resolution=excluded.kk_resolution,commander_decision=excluded.commander_decision,order_number=excluded.order_number,
        order_date=excluded.order_date,finished_date=excluded.finished_date,updated_at=CURRENT_TIMESTAMP')
        ->execute([$caseId,$track['id'],$route,workflowDate($_POST['report_kk_date']??''),$resolutionDate,$resolution,
            clean($_POST['commander_decision']??''),$order,$orderDate,workflowDate($_POST['finished_date']??'')]);
    audit($pdo,$caseId,$action,(string)$track['id']);
    $pdo->commit();
    go($caseId,4);
}
