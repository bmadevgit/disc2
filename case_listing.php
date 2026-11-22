<?php
// Scope the source cases before loading subject data and building filter choices.
$params = [];
$conditions = '';
if ($role === 'case_owner') {
    $conditions = 'WHERE EXISTS (SELECT 1 FROM uat_case_owners o WHERE o.case_id=c.id AND o.bma_personal_id=? AND o.ended_at IS NULL)';
    $params = [(string)$_SESSION['user_id']];
} elseif ($role === 'group_head') {
    $conditions = 'WHERE m.unit_level1=? AND m.unit_level2=?';
    $params = [$access['unit_level1'],$access['unit_level2']];
}
$cases = workflowRows($pdo,"SELECT c.id,c.เลขที่สำนวน,c.created_at,
    COALESCE(NULLIF(m.responsible_unit,''),c.หน่วยงานต้นเรื่อง,'') AS unit,
    COALESCE(NULLIF(m.stage,''),'legacy') AS stage,
    COALESCE(m.case_type,'') AS case_type
    FROM cases c LEFT JOIN uat_case_meta m ON m.case_id=c.id $conditions ORDER BY c.id DESC",$params);

$filters = [];
foreach (['q','unit','type','stage','finding'] as $key) {
    $filters[$key] = is_string($_GET[$key] ?? null) ? trim($_GET[$key]) : '';
}
$options = ['unit'=>[],'type'=>[],'stage'=>stageLabels(),'finding'=>array_column(findingLabels(),0)];
$options['finding'] = array_combine(array_keys(findingLabels()),$options['finding']);
$subjectQuery = $pdo->prepare("SELECT s.*, f.finding FROM uat_subjects s
    LEFT JOIN uat_subject_findings f ON f.subject_id=s.id AND f.track_id=(
        SELECT t.id FROM uat_track_subjects ts JOIN uat_tracks t ON t.id=ts.track_id
        WHERE ts.subject_id=s.id AND t.case_id=s.case_id AND ts.ended_at IS NULL
        ORDER BY t.track_type='discipline_serious' DESC,t.track_type='discipline_nonserious' DESC,t.id DESC LIMIT 1
    )
    WHERE s.case_id=? AND s.active=1 ORDER BY s.sequence_no");
$visibleCases = [];
foreach ($cases as $case) {
    $unit = $case['unit'] ?: 'ยังไม่ระบุ';
    $type = $case['case_type'] ?: 'ยังไม่ระบุ';
    $options['unit'][$unit] = $unit;
    $options['type'][$type] = $type;

    $subjectQuery->execute([$case['id']]);
    $people = $subjectQuery->fetchAll();
    $tokens = [$case['เลขที่สำนวน'],$case['unit']];
    $counts = [];
    foreach ($people as $person) {
        $tokens[] = $person['reference_code'];
        $tokens[] = subjectDisplayName($person);
        if (canViewSubjectIdentity()) $tokens[] = $person['first_name_th'].' '.$person['last_name_th'];
        $finding = $person['finding'] ?? '';
        $finding = isset(findingLabels()[$finding]) ? $finding : 'pending';
        $counts[$finding] = ($counts[$finding] ?? 0) + 1;
    }
    $searchText = implode(' ',$tokens);
    $matches = $filters['q'] === '' || str_contains($searchText,$filters['q']) ||
        (function_exists('mb_stripos') ? mb_stripos($searchText,$filters['q'],0,'UTF-8') !== false : stripos($searchText,$filters['q']) !== false);
    if (!$matches || ($filters['unit'] !== '' && $unit !== $filters['unit']) ||
        ($filters['type'] !== '' && $type !== $filters['type']) ||
        ($filters['stage'] !== '' && $case['stage'] !== $filters['stage']) ||
        ($filters['finding'] !== '' && !isset($counts[$filters['finding']]))) continue;
    $visibleCases[] = $case + ['subject_count'=>count($people),'counts'=>$counts];
}
asort($options['unit'],SORT_NATURAL);
asort($options['type'],SORT_NATURAL);
?>
<section class="card">
    <h2 class="section-title">รายการสำนวนที่เข้าถึงได้</h2>
    <form method="get" action="<?=appUrl('index.php')?>" class="grid workflow-filters">
        <div><label>ค้นหาสำนวน<input type="search" name="q" value="<?=h($filters['q'])?>"
            placeholder="<?=canViewSubjectIdentity()?'เลขสำนวน หน่วยงาน ชื่อ หรือรหัสบุคคล':'เลขสำนวน หน่วยงาน หรือรหัสบุคคล'?>"></label></div>
        <?php foreach (['type'=>'ประเภทสำนวน','unit'=>'หน่วยงาน','stage'=>'ชั้นดำเนินการ','finding'=>'ผลรายบุคคล'] as $key=>$label): ?>
        <div><label><?=$label?><select name="<?=$key?>">
            <option value="">ทั้งหมด</option>
            <?php foreach ($options[$key] as $value=>$choice): ?>
                <option value="<?=h($value)?>" <?=$filters[$key]===(string)$value?'selected':''?>><?=h($choice)?></option>
            <?php endforeach ?>
        </select></label></div>
        <?php endforeach ?>
        <div class="actions"><button class="btn">ค้นหา</button><a class="btn gray" href="<?=appUrl('index.php')?>">ล้างตัวกรอง</a></div>
    </form>
    <div style="overflow:auto"><table>
        <thead><tr><th>เลขที่สำนวน</th><th>หน่วยงาน</th><th>ประเภทสำนวน</th><th>ชั้นดำเนินการและผล</th><th>ผู้ถูกกล่าวหา</th><th>บันทึก</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($visibleCases as $case): ?>
        <tr>
            <td><b><?=h($case['เลขที่สำนวน'] ?: 'ไม่ระบุ')?></b></td>
            <td><?=h($case['unit'] ?: 'ยังไม่ระบุ')?></td>
            <td><?=h($case['case_type'] ?: 'ยังไม่ระบุ')?></td>
            <td><div class="workflow-badges"><span class="pill workflow-stage"><?=h(stageLabels()[$case['stage']] ?? 'รับเรื่อง')?></span>
                <?php foreach ($case['counts'] as $finding=>$count) showFinding($finding,' '.$count.' ราย'); ?>
                <?php if (!$case['subject_count']): ?><span class="pill workflow-neutral">ต้องเพิ่มรายบุคคล</span><?php endif ?>
            </div></td>
            <td><?php if ($case['subject_count']): ?><a href="<?=appUrl('subject.php?case_id='.(int)$case['id'])?>"><?=$case['subject_count']?> ราย</a><?php else: ?>–<?php endif ?></td>
            <td><?=h(substr((string)$case['created_at'],0,16))?></td>
            <td><a class="btn small" href="<?=appUrl('case.php?id='.(int)$case['id'])?>">เปิด</a></td>
        </tr>
        <?php endforeach ?>
        <?php if (!$visibleCases): ?><tr><td colspan="7" class="muted">ไม่พบสำนวนที่ตรงกับการค้นหา</td></tr><?php endif ?>
        </tbody>
    </table></div>
</section>
