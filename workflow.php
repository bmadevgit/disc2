<?php
function workflowRows(PDO $pdo, string $sql, array $params): array {
    $query = $pdo->prepare($sql);
    $query->execute($params);
    return $query->fetchAll();
}
function caseTypes(): array {
    return ['ทุจริตและผลประโยชน์ทับซ้อน','จัดซื้อจัดจ้างและพัสดุ','การอนุญาตหรือควบคุมอาคารและผังเมือง','การจัดเก็บรายได้และภาษี','การเงินและบัญชี','งานบริการสาธารณะเฉพาะด้าน','ปฏิบัติหน้าที่บกพร่องหรือละเลย','ความประพฤติส่วนบุคคล','เกี่ยวเนื่องกับคดีอาญา','การบริหารงานบุคคล','วินัยการปฏิบัติราชการทั่วไป','อื่นๆ'];
}
function caseTypeFields(array $case = []): void {
    $selected = $case['case_type'] ?? '';
    $types = caseTypes();
    if ($selected !== '' && !in_array($selected, $types, true)) $types[] = $selected;
    ?>
    <div><label>ประเภทสำนวน<select name="case_type">
        <option value="">ยังไม่ระบุ</option>
        <?php foreach ($types as $type): ?>
            <option <?= $type === $selected ? 'selected' : '' ?>><?=h($type)?></option>
        <?php endforeach ?>
    </select></label></div>
    <div><label>รายละเอียดประเภทกรณีเลือก “อื่น ๆ”<input name="case_type_other" value="<?=h($case['case_type_other'] ?? '')?>"></label></div>
    <?php
}
function saveCaseType(PDO $pdo, int $caseId): void {
    if (!isset($_POST['case_type'])) return;
    $type = trim((string)$_POST['case_type']);
    $stored = workflowRows($pdo, 'SELECT case_type FROM uat_case_meta WHERE case_id=?', [$caseId])[0]['case_type'];
    if ($type !== '' && $type !== $stored && !in_array($type, caseTypes(), true)) workflowError('ประเภทสำนวนไม่ถูกต้อง');
    $detail = $type === 'อื่นๆ' ? trim((string)($_POST['case_type_other'] ?? '')) : '';
    $pdo->prepare('UPDATE uat_case_meta SET case_type=?,case_type_other=? WHERE case_id=?')->execute([$type,$detail,$caseId]);
}
function workflowError(string $message, int $status = 422): never {
    http_response_code($status);
    exit($message);
}
function workflowDate($value): string {
    $value = trim((string)$value);
    if ($value === '') return '';
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    if (!$date || $date->format('Y-m-d') !== $value) workflowError('วันที่ไม่ถูกต้อง');
    return $value;
}
function shiftDate(string $date, int $days): string {
    return $date === '' ? '' : (new DateTimeImmutable($date))->modify(sprintf('%+d days',$days))->format('Y-m-d');
}
function automaticStart(string $type, string $ack, string $meeting, bool $legacy = false): string {
    if ($type === 'discipline_serious') return shiftDate($meeting, $legacy ? 0 : 1);
    if ($ack === '' || $meeting === '') return '';
    return shiftDate($ack, $ack === $meeting ? 0 : 1);
}
function deadlineValues(string $type, int $days, array $input): array {
    $ack = workflowDate($input['acknowledged_date'] ?? '');
    $meeting = workflowDate($input['first_meeting_date'] ?? '');
    $start = automaticStart($type, $ack, $meeting, $type === 'discipline_serious' && $days === 180);
    $reason = '';
    $mode = $input['start_mode'] ?? (!empty($input['start_date']) && !empty($input['start_override_reason']) ? 'manual' : 'auto');
    if ($mode === 'manual') {
        $start = workflowDate($input['start_date'] ?? '');
        $reason = trim((string)($input['start_override_reason'] ?? ''));
        if ($start === '' || $reason === '') workflowError('กรุณาระบุวันเริ่มนับและเหตุผลที่แก้ไข');
    }
    return [$ack,$meeting,$start,$days,shiftDate($start,$days-1),$reason];
}
function trackRedirect(array $track, string $extra = ''): never {
    header('Location: '.appUrl(trackUrl($track).$extra));
    exit;
}
function trackUrl(array $track, string $page = 'track.php'): string {
    return $page.'?case_id='.(int)$track['case_id'].'&type='.$track['track_type'].'&track_id='.(int)$track['id'];
}
function disciplineTrack(PDO $pdo, int $caseId, string $type, int $trackId): array {
    $track = workflowRows($pdo, 'SELECT * FROM uat_tracks WHERE case_id=? AND track_type=? AND '.($trackId ? 'id=?' : 'set_no=?'),
        [$caseId,$type,$trackId ?: 1])[0] ?? [];
    if (!$track) workflowError('ไม่พบกระบวนการสอบสวน',404);
    return $track;
}
function trackTitle(array $track): string {
    if ($track['track_type'] === 'fact') return 'สืบสวนข้อเท็จจริง';
    if ($track['track_type'] === 'discipline_nonserious') return 'สอบสวนวินัยอย่างไม่ร้ายแรง';
    return 'สอบสวนวินัยอย่างร้ายแรง · ชุดที่ '.(int)$track['set_no'];
}
function trackExtensions(PDO $pdo, int $trackId): array {
    return workflowRows($pdo,'SELECT * FROM uat_extensions WHERE track_id=? ORDER BY sequence_no',[$trackId]);
}
function revisedExtensions(array $extensions, string $due): array {
    foreach ($extensions as &$extension) {
        $due = shiftDate($due,(int)$extension['requested_days']);
        $extension['new_due_date'] = $due;
    }
    return $extensions;
}
function saveTrackDates(PDO $pdo, array $track, array $values, string $auditAction, string $reason = ''): void {
    $before = trackExtensions($pdo,(int)$track['id']);
    $after = $track['due_date'] === $values[4] ? $before : revisedExtensions($before,$values[4]);
    if ($after && $values[4] === '') workflowError('กรุณาระบุวันครบกำหนดสำหรับชุดที่มีการขยายเวลา');
    $pdo->prepare('UPDATE uat_tracks SET acknowledged_date=?,first_meeting_date=?,start_date=?,duration_days=?,due_date=?,start_override_reason=? WHERE id=?')
        ->execute([...$values,$track['id']]);
    $update = $pdo->prepare('UPDATE uat_extensions SET new_due_date=? WHERE id=?');
    if ($before !== $after) foreach ($after as $extension) $update->execute([$extension['new_due_date'],$extension['id']]);
    $keys = ['acknowledged_date','first_meeting_date','start_date','duration_days','due_date','start_override_reason'];
    audit($pdo,(int)$track['case_id'],$auditAction,json_encode([
        'track_id'=>$track['id'],'reason'=>$reason,
        'before'=>array_intersect_key($track,array_flip($keys)),'after'=>array_combine($keys,$values),
        'extensions_before'=>$before,'extensions_after'=>$after,
    ],JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
}
function beginWorkflowWrite(PDO $pdo): void {
    $pdo->beginTransaction();
    // Acquire the SQLite write lock before reading state that this transaction may change.
    $pdo->exec('UPDATE uat_tracks SET id=id WHERE 0');
}
function disciplineMembers(PDO $pdo, int $trackId): array {
    return workflowRows($pdo, 'SELECT s.*, ts.ended_at,f.finding,f.allegation_detail,f.offense_basis,
        f.committee_opinion,f.appointing_authority_opinion,f.allegation,f.recommend_serious
        FROM uat_track_subjects ts JOIN uat_subjects s ON s.id=ts.subject_id
        LEFT JOIN uat_subject_findings f ON f.track_id=ts.track_id AND f.subject_id=ts.subject_id
        WHERE ts.track_id=? AND s.active=1 ORDER BY s.sequence_no', [$trackId]);
}
function trackOrders(PDO $pdo, int $trackId): array {
    return workflowRows($pdo,'SELECT * FROM uat_committee_orders WHERE track_id=? ORDER BY id',[$trackId]);
}
function deadlineFields(array $track, int $days, bool $editableDays = false): void {
    $manual = ($track['start_override_reason'] ?? '') !== '';
    ?>
    <div><label>วันที่ประธานรับทราบคำสั่ง<input type="date" name="acknowledged_date" value="<?=h($track['acknowledged_date']??'')?>"></label></div>
    <div><label>วันประชุมนัดแรก<input type="date" name="first_meeting_date" value="<?=h($track['first_meeting_date']??'')?>"></label></div>
    <div><label><?= $editableDays ? 'ระยะเวลาตามคำสั่ง (วัน)' : ($days === 180 ? 'ระยะเวลาที่บันทึกไว้ (วัน)' : 'ระยะเวลาตามกฎหมาย (วัน)') ?>
        <input type="number" min="1" name="duration_days" value="<?=$days?>" <?= $editableDays ? 'required' : 'readonly' ?>>
    </label></div>
    <div><label>การกำหนดวันเริ่มนับ<select name="start_mode">
        <option value="auto">อัตโนมัติ</option><option value="manual" <?=$manual?'selected':''?>>กำหนดเอง</option>
    </select></label></div>
    <div><label>วันเริ่มนับ (แก้ได้)<input type="date" name="start_date" value="<?=h($track['start_date']??'')?>"></label></div>
    <div><label>เหตุผลที่แก้วันเริ่มนับ<input name="start_override_reason" value="<?=h($track['start_override_reason']??'')?>"></label></div>
    <div><label>วันครบกำหนด<input type="text" name="preview_due" readonly value="<?=h($track['due_date']??'')?>"></label></div>
    <?php
}


function currentDiscipline(PDO $pdo, int $caseId, int $subjectId): array {
    return workflowRows($pdo, "SELECT t.* FROM uat_tracks t
        JOIN uat_track_subjects ts ON ts.track_id=t.id
        JOIN uat_subjects s ON s.id=ts.subject_id AND s.case_id=t.case_id
        WHERE t.case_id=? AND s.id=? AND s.active=1 AND t.track_type<>'fact' AND ts.ended_at IS NULL
        ORDER BY t.track_type='discipline_serious' DESC,t.id DESC LIMIT 1",[$caseId,$subjectId])[0] ?? [];
}
function sameDeadlineInputs(array $track, array $values): bool {
    return [$track['acknowledged_date'],$track['first_meeting_date'],(int)$track['duration_days'],$track['start_override_reason']]
        === [$values[0],$values[1],$values[3],$values[5]]
        && ($values[5] === '' || $track['start_date'] === $values[2]);
}
function deadlineRevision(array $track, array $extensions): string {
    return hash('sha256',serialize([$track,$extensions]));
}
function conversionValues(array $track): array {
    $track['start_mode'] = $track['start_override_reason'] === '' ? 'auto' : 'manual';
    return deadlineValues('discipline_serious',120,$track);
}

function disciplineFields(): array {
    return [
        'committee_opinion'=>'ความเห็นของคณะกรรมการ',
        'appointing_authority_opinion'=>'ความเห็นของผู้มีอำนาจสั่งบรรจุ',
        'offense_basis'=>'ฐานความผิด',
        'allegation'=>'ข้อกล่าวโทษ',
    ];
}
function stageLabels(): array {
    return ['legacy'=>'ข้อมูลเดิม','case_info'=>'รับเรื่อง','fact_finding'=>'สืบสวนข้อเท็จจริง',
        'discipline'=>'สอบสวนทางวินัย','closed'=>'ยุติเรื่อง'];
}
function findingLabels(): array {
    return [
        'ground'=>['มีมูล','warn'],'no_ground'=>['ไม่มีมูล','clear'],
        'guilty'=>['ผิดวินัย','alert'],'not_guilty'=>['ไม่ผิดวินัย','clear'],
        'pending'=>['รอบันทึกผล','neutral'],
    ];
}
function showFinding(string $finding, string $suffix = ''): void {
    [$label,$color] = findingLabels()[$finding] ?? findingLabels()['pending'];
    ?><span class="pill workflow-<?=h($color)?>"><?=h($label.$suffix)?></span><?php
}
function showDisciplineFinding(array $data): void {
    showFinding($data['finding'] ?? '');
    foreach (disciplineFields() + ['allegation_detail'=>'รายละเอียดที่บันทึกเดิม'] as $key=>$label) {
        if (($data[$key]??'') === '') continue;
        ?><p><strong><?=h($label)?></strong><br><span class="workflow-remarks"><?=h($data[$key])?></span></p><?php
    }
}
