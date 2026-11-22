<?php
require __DIR__.'/auth.php';
require __DIR__.'/db.php';
requireLogin();
require __DIR__.'/ui.php';
require __DIR__.'/workflow.php';

$caseId = (int)($_GET['case_id'] ?? 0);
if (!canViewCase($pdo,$caseId)) workflowError('ไม่มีสิทธิ์เข้าดูสำนวน',403);
$case = workflowRows($pdo,'SELECT เลขที่สำนวน FROM cases WHERE id=?',[$caseId])[0] ?? [];
if (!$case) workflowError('ไม่พบสำนวน',404);
$people = workflowRows($pdo,'SELECT * FROM uat_subjects WHERE case_id=? AND active=1 ORDER BY sequence_no',[$caseId]);
$sid = (int)($_GET['subject_id'] ?? 0);
$person = null;
if ($sid) {
    foreach ($people as $candidate) {
        if ((int)$candidate['id'] === $sid) $person = $candidate;
    }
    if (!$person) workflowError('ไม่พบผู้ถูกกล่าวหาในสำนวนนี้',404);
}
pageHead($person ? 'รายละเอียดรายบุคคล' : 'ผู้ถูกกล่าวหาในสำนวน');
?>
<section class="card">
    <a href="<?=appUrl('index.php')?>">← รายการสำนวน</a>
    <h1 class="section-title">ผู้ถูกกล่าวหาในสำนวน <?=h($case['เลขที่สำนวน'] ?: '#'.$caseId)?></h1>
    <div class="actions">
        <?php foreach ($people as $candidate): ?>
            <a class="btn small <?=((int)$candidate['id']===$sid)?'green':''?>"
                href="<?=appUrl('subject.php?case_id='.$caseId.'&amp;subject_id='.(int)$candidate['id'])?>"><?=h(subjectDisplayName($candidate))?></a>
        <?php endforeach ?>
    </div>
    <?php if (!$people): ?><p>ยังไม่มีข้อมูลผู้ถูกกล่าวหา</p><?php endif ?>
</section>
<?php if (!$person) { pageFoot(); exit; }

$rounds = workflowRows($pdo,'SELECT t.*,ts.ended_at FROM uat_track_subjects ts JOIN uat_tracks t ON t.id=ts.track_id
    WHERE ts.subject_id=? AND t.case_id=? ORDER BY t.track_type<>\'fact\',t.id',[$sid,$caseId]);
$escalations = array_column(workflowRows($pdo,'SELECT * FROM uat_escalations WHERE subject_id=?',[$sid]),null,'from_track_id');
$history = workflowRows($pdo,'SELECT * FROM uat_subject_status_history WHERE subject_id=? ORDER BY id DESC',[$sid]);
$current = currentDiscipline($pdo,$caseId,$sid);
$latest = $current ?: (end($rounds) ?: []);
?>
<section class="card">
    <h2 class="section-title"><?=h(subjectDisplayName($person))?></h2>
    <p>ชั้นปัจจุบัน <strong><?=h($latest ? trackTitle($latest) : 'รับเรื่อง')?></strong></p>
    <?php if ($history): ?>
    <details><summary>ประวัติสถานะบุคคล</summary>
        <ul><?php foreach ($history as $record): ?>
            <li><?=h($record['effective_date'].' '.$record['status_name'].' '.$record['reason'])?></li>
        <?php endforeach ?></ul>
    </details>
    <?php endif ?>
</section>
<?php foreach (array_reverse($rounds) as $round):
    $tid = (int)$round['id'];
    $escalation = $escalations[$tid] ?? null;
    $snapshot = $escalation ? json_decode($escalation['snapshot'],true,512,JSON_THROW_ON_ERROR) : [];
    $dates = $snapshot['track'] ?? $round;
    $orders = $snapshot['orders'] ?? trackOrders($pdo,$tid);
    $extensions = $snapshot['extensions'] ?? trackExtensions($pdo,$tid);
    $finding = $snapshot['finding'] ?? (workflowRows($pdo,'SELECT * FROM uat_subject_findings WHERE track_id=? AND subject_id=?',[$tid,$sid])[0] ?? []);
    $decisions = $snapshot['decisions'] ?? workflowRows($pdo,'SELECT * FROM uat_decisions WHERE track_id=? AND subject_id=?',[$tid,$sid]);
    $members = $snapshot['members'] ?? workflowRows($pdo,'SELECT subject_id FROM uat_track_subjects WHERE track_id=? AND ended_at IS NULL',[$tid]);
?>
<section class="card">
    <h2 class="section-title"><?=h(trackTitle($round))?></h2>
    <?php if ($escalation): ?>
        <p class="notice warn">ยืนยันยกระดับเมื่อ <?=h($escalation['effective_date'])?> · อ้างอิง <?=h($escalation['reference'])?>
            · บันทึกโดย <?=h($escalation['actor_name'] ?: 'เจ้าหน้าที่')?> เมื่อ <?=h($escalation['created_at'])?> (UTC)</p>
    <?php endif ?>
    <?php if ($round['track_type'] === 'fact'): ?>
        <?php showFinding($finding['finding'] ?? ''); ?>
        <?php if (!empty($finding['allegation_detail'])): ?><p class="workflow-remarks"><?=h($finding['allegation_detail'])?></p><?php endif ?>
    <?php else: ?>
        <?php showDisciplineFinding($finding); ?>
        <?php if (!empty($finding['recommend_serious'])): ?><p>คกก. เสนอให้สอบสวนอย่างร้ายแรง</p><?php endif ?>
    <?php endif ?>
    <details open>
        <summary>กำหนดเวลาและคำสั่ง คกก.</summary>
        <dl>
            <dt>วันที่ประธานรับทราบคำสั่ง</dt><dd><?=h($dates['acknowledged_date'] ?: '–')?></dd>
            <dt>วันประชุมนัดแรก</dt><dd><?=h($dates['first_meeting_date'] ?: '–')?></dd>
            <dt>วันเริ่มนับ</dt><dd><?=h($dates['start_date'] ?: '–')?></dd>
            <dt>ระยะเวลา</dt><dd><?=h($dates['duration_days'] ?: '–')?> วัน</dd>
            <dt>วันครบกำหนด</dt><dd><?=h($dates['due_date'] ?: '–')?></dd>
            <?php if ($dates['start_override_reason'] !== ''): ?><dt>เหตุผลที่แก้วันเริ่มนับ</dt><dd><?=h($dates['start_override_reason'])?></dd><?php endif ?>
            <dt>ผู้ถูกกล่าวหาในชุด</dt><dd><?=count(array_filter($members,fn($member)=>empty($member['ended_at'])))?> ราย</dd>
        </dl>
        <?php if ($orders): ?>
        <h3>คำสั่งคณะกรรมการ</h3>
        <ul><?php foreach ($orders as $order): ?>
            <li><?=h((['appoint'=>'แต่งตั้ง','change_committee'=>'เปลี่ยนแปลงคณะกรรมการ','amend_order'=>'แก้ไขเพิ่มเติมคำสั่ง'][$order['event_type']]??'').' '.$order['order_date'].' '.$order['order_number'].' '.$order['detail'])?></li>
        <?php endforeach ?></ul>
        <?php endif ?>
        <?php if ($extensions): ?>
        <h3>การขยายเวลา</h3>
        <ul><?php foreach ($extensions as $extension): ?>
            <li>ครั้งที่ <?=$extension['sequence_no']?> · <?=$extension['requested_days']?> วัน · ครบกำหนด <?=h($extension['new_due_date'])?>
                <br><?=h($extension['completed_work'])?> · <?=h($extension['reason'])?></li>
        <?php endforeach ?></ul>
        <?php endif ?>
    </details>
    <?php foreach ($decisions as $decision): ?>
        <details open>
            <summary>คำสั่งและรายงาน ก.ก.</summary>
            <?php foreach (['report_kk_date'=>'วันที่รายงาน ก.ก.','kk_resolution_date'=>'วันที่มีมติ ก.ก.','kk_resolution'=>'มติ ก.ก.',
                'commander_decision'=>'ผลการพิจารณาของผู้บังคับบัญชา','order_number'=>'เลขที่คำสั่ง','order_date'=>'วันที่คำสั่ง',
                'finished_date'=>'วันที่เรื่องเสร็จ','termination_reason'=>'เหตุผลที่ยุติเรื่อง','proposal_letter_number'=>'เลขที่หนังสือเสนอ',
                'other_outcome'=>'ผลอื่น','other_note'=>'หมายเหตุ'] as $field=>$label): ?>
                <?php if ($decision[$field] !== ''): ?><p><strong><?=h($label)?></strong> <span class="workflow-remarks"><?=h($decision[$field])?></span></p><?php endif ?>
            <?php endforeach ?>
            <?php foreach ($decision['reports'] ?? workflowRows($pdo,'SELECT * FROM uat_kk_reports WHERE decision_id=? ORDER BY sequence_no',[$decision['id']]) as $report): ?>
                <p>รายงานครั้งที่ <?=$report['sequence_no']?> · <?=h($report['report_date'].' '.$report['letter_number'].' '.$report['resolution_text'].' '.$report['resolution_action'].' '.$report['note'])?></p>
            <?php endforeach ?>
        </details>
    <?php endforeach ?>
</section>
<?php endforeach; pageFoot();
