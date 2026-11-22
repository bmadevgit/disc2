<?php
require __DIR__.'/auth.php';
require __DIR__.'/db.php';
requireLogin();
require __DIR__.'/ui.php';
require __DIR__.'/workflow.php';
$caseId = (int)($_GET['case_id'] ?? 0);
$type = (string)($_GET['type'] ?? '');
if (!in_array($type,['discipline_nonserious','discipline_serious'],true) || !canViewCase($pdo,$caseId)) workflowError('ไม่มีสิทธิ์',403);
$edit = canEditCase($pdo,$caseId);
$track = disciplineTrack($pdo,$caseId,$type,(int)($_GET['track_id'] ?? 0));
$persons = disciplineMembers($pdo,(int)$track['id']);
$seriousTracks = $edit && $type === 'discipline_nonserious' ? workflowRows($pdo,
    "SELECT t.*, (SELECT order_number FROM uat_committee_orders o WHERE o.track_id=t.id AND o.event_type='appoint'
    ORDER BY o.id DESC LIMIT 1) appointment FROM uat_tracks t
    WHERE t.case_id=? AND t.track_type='discipline_serious' ORDER BY t.set_no",[$caseId]) : [];
pageHead('ผลการสอบสวนรายบุคคล');
?>
<section class="card">
    <a href="<?=h(appUrl(trackUrl($track)))?>">← <?=h(trackTitle($track))?></a>
    <h1 class="section-title">ผลการสอบสวนวินัยรายบุคคล</h1>
    <?php if (isset($_GET['saved'])): ?><div class="notice">บันทึกแล้ว</div><?php endif ?>
    <?php if (!$persons): ?><p>ยังไม่มีผู้ถูกกล่าวหาในชุดนี้</p><?php endif ?>
    <?php foreach ($persons as $person): $sid = (int)$person['id']; ?>
    <article class="subject">
        <h2><?=h(subjectDisplayName($person))?></h2>
        <p><a href="<?=h(appUrl('subject.php?case_id='.$caseId.'&subject_id='.$sid))?>">ดูรายละเอียดและประวัติรายบุคคล</a></p>
        <?php if ($person['ended_at']): ?><p>สิ้นสุดรอบนี้แล้ว</p><?php endif ?>
        <?php if ($edit && !$person['ended_at']): ?>
        <form method="post" action="<?=appUrl('save_discipline.php')?>" class="grid">
            <input type="hidden" name="csrf" value="<?=csrfToken()?>">
            <input type="hidden" name="case_id" value="<?=$caseId?>">
            <input type="hidden" name="track_id" value="<?=$track['id']?>">
            <input type="hidden" name="subject_id" value="<?=$sid?>">
            <div><label>ผลการสอบสวนวินัย<select name="finding">
                <?php foreach ([''=>'ยังไม่บันทึก','not_guilty'=>'ไม่ผิดวินัย','guilty'=>'ผิดวินัย'] as $key=>$label): ?>
                    <option value="<?=h($key)?>" <?=($person['finding']??'')===$key?'selected':''?>><?=h($label)?></option>
                <?php endforeach ?>
            </select></label></div>
            <?php if ($type === 'discipline_nonserious'): ?>
                <div><label class="workflow-checkbox"><input type="checkbox" name="recommend_serious" value="1" <?=!empty($person['recommend_serious'])?'checked':''?>> คกก. เห็นควรให้สอบสวนอย่างร้ายแรง</label></div>
            <?php endif ?>
            <?php foreach (disciplineFields() as $field=>$label): ?>
                <div><label><?=h($label)?><textarea name="<?=h($field)?>"><?=h($person[$field]??'')?></textarea></label></div>
            <?php endforeach ?>
            <div class="actions wide"><button class="btn green">บันทึกผลการสอบสวน</button></div>
        </form>
        <?php else: showDisciplineFinding($person); endif ?>
        <?php if ($edit && !$person['ended_at'] && !empty($person['allegation_detail'])): ?>
            <details><summary>รายละเอียดที่บันทึกเดิม</summary><p class="workflow-remarks"><?=h($person['allegation_detail'])?></p></details>
        <?php endif ?>
        <?php if ($edit && !$person['ended_at'] && $type==='discipline_nonserious' && !empty($person['recommend_serious'])): ?>
        <details>
            <summary>ยืนยันเปลี่ยนเป็นสอบสวนอย่างร้ายแรง</summary>
            <form method="post" action="<?=appUrl('save_case.php')?>" class="grid">
                <input type="hidden" name="csrf" value="<?=csrfToken()?>">
                <input type="hidden" name="action" value="escalate_discipline">
                <input type="hidden" name="case_id" value="<?=$caseId?>">
                <input type="hidden" name="track_id" value="<?=$track['id']?>">
                <input type="hidden" name="subject_id" value="<?=$sid?>">
                <div><label>ชุด คกก. ร้ายแรง<select name="target_track" required>
                    <option value="">เลือกชุด คกก.</option>
                    <?php foreach ($seriousTracks as $target): ?>
                        <option value="<?=$target['id']?>"><?=h(trackTitle($target).($target['appointment'] ? ' · '.$target['appointment'] : ''))?></option>
                    <?php endforeach ?>
                    <option value="new">เปิดชุดใหม่ตามคำสั่งแต่งตั้ง</option>
                </select></label></div>
                <div><label>วันที่คำสั่งมีผล<input type="date" name="effective_date" required></label></div>
                <div><label>เลขที่คำสั่งหรือหนังสืออ้างอิง<input name="reference" required></label></div>
                <div class="actions wide"><button class="btn green">ยืนยันเปลี่ยนเป็นร้ายแรง</button></div>
            </form>
        </details>
        <?php endif ?>
    </article>
    <?php endforeach ?>
</section>
<?php pageFoot();
