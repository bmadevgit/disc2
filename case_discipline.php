<?php
$grounds = workflowRows($pdo,"SELECT s.*,f.severity FROM uat_subjects s
    JOIN uat_subject_findings f ON f.subject_id=s.id AND f.finding='ground'
    JOIN uat_tracks t ON t.id=f.track_id AND t.track_type='fact' AND t.case_id=s.case_id
    WHERE s.case_id=? AND s.active=1 ORDER BY s.sequence_no",[$id]);
$unassigned = array_filter($grounds,fn($s)=>!currentDiscipline($pdo,$id,(int)$s['id']));
$tracks = workflowRows($pdo,"SELECT * FROM uat_tracks WHERE case_id=? AND track_type<>'fact' ORDER BY track_type,set_no",[$id]);
$existingTrackTypes = array_column($tracks,'track_type');
$openableTracks = array_filter([
    'discipline_nonserious' => 'สอบสวนวินัยอย่างไม่ร้ายแรง (60 วัน)',
    'discipline_serious' => 'สอบสวนวินัยอย่างร้ายแรง (120 วัน)',
], fn($type) => !in_array($type, $existingTrackTypes, true), ARRAY_FILTER_USE_KEY);
?>
<section class="card">
    <h2 class="section-title">ชั้นสอบสวนทางวินัย</h2>
    <?php if ($subjects && !$grounds && !$tracks): ?>
        <div class="notice warn">ต้องบันทึกผล “มีมูล” ในหน้าสืบสวนก่อน</div>
    <?php endif ?>
    <?php if ($edit && !$subjects && $openableTracks): ?>
        <form method="post" action="<?=appUrl('save_case.php')?>" class="grid">
            <input type="hidden" name="csrf" value="<?=csrfToken()?>">
            <input type="hidden" name="action" value="open_discipline_track">
            <input type="hidden" name="case_id" value="<?=$id?>">
            <div><label>ประเภทการสอบสวน (กรณีไม่ระบุผู้ถูกกล่าวหารายบุคคล)
                <select name="track_type" required>
                    <?php foreach ($openableTracks as $type=>$label): ?>
                        <option value="<?=h($type)?>"><?=h($label)?></option>
                    <?php endforeach ?>
                </select>
            </label></div>
            <div class="actions wide"><button class="btn green">เปิดชั้นสอบสวนทางวินัย</button></div>
        </form>
    <?php endif ?>
    <?php if ($edit && $unassigned): ?>
        <form method="post" action="<?=appUrl('save_case.php')?>" class="grid">
            <input type="hidden" name="csrf" value="<?=csrfToken()?>">
            <input type="hidden" name="action" value="assign_discipline">
            <input type="hidden" name="case_id" value="<?=$id?>">
            <?php foreach ($unassigned as $s): ?>
                <div><label><?=h(subjectDisplayName($s))?>
                    <select name="track_for[<?=$s['id']?>]">
                        <option value="">ยังไม่กำหนด</option>
                        <option value="discipline_nonserious" <?=$s['severity']==='nonserious'?'selected':''?>>ไม่ร้ายแรง</option>
                        <option value="discipline_serious" <?=$s['severity']==='serious'?'selected':''?>>ร้ายแรง</option>
                    </select>
                </label></div>
            <?php endforeach ?>
            <div class="actions wide"><button class="btn green">บันทึกประเภทการสอบสวน</button></div>
        </form>
    <?php endif ?>
    <?php foreach ($tracks as $track): ?>
        <div class="subject">
            <h3><?=h(trackTitle($track))?></h3>
            <a class="btn small" href="<?=h(appUrl(trackUrl($track)))?>">กำหนดเวลา คำสั่ง และการขยายเวลา</a>
            <a class="btn small" href="<?=h(appUrl(trackUrl($track,'discipline_results.php')))?>">ผลการสอบสวนรายบุคคล</a>
            <?php if (!$subjects): ?><a class="btn small gray" href="<?=h(appUrl('case.php?id='.$id.'&page=4'))?>">ไปขั้นตอนที่ 4 (คำสั่ง/รายงาน ก.ก.) →</a><?php endif ?>
        </div>
    <?php endforeach ?>
</section>
