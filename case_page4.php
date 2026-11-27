<?php
$decisionRows = [];
$decisionQuery = $pdo->prepare(<<<'SQL'
SELECT
    s.*,
    d.*,
    s.id AS form_subject_id,
    t.track_type,
    t.id AS current_track_id
FROM uat_subjects s
JOIN uat_tracks t ON t.id = (
    SELECT t2.id FROM uat_tracks t2
    JOIN uat_track_subjects ts ON ts.track_id=t2.id
    WHERE t2.case_id=s.case_id AND ts.subject_id=s.id AND ts.ended_at IS NULL AND t2.track_type<>'fact'
    ORDER BY t2.track_type='discipline_serious' DESC,t2.id DESC LIMIT 1
)
LEFT JOIN uat_decisions d ON d.subject_id=s.id AND d.track_id=t.id
WHERE s.case_id = ? AND s.active=1
ORDER BY s.sequence_no, s.id
SQL);
$decisionQuery->execute([$id]);
$decisionRows = $decisionQuery->fetchAll();
$trackDecisionRows = [];
if (!$subjects) {
    $trackDecisionRows = workflowRows($pdo, "SELECT t.id AS current_track_id, t.track_type, t.set_no, d.report_kk_date, d.kk_resolution_date, d.kk_resolution, d.commander_decision, d.order_number, d.order_date, d.finished_date
        FROM uat_tracks t
        LEFT JOIN uat_track_decisions d ON d.track_id=t.id
        WHERE t.case_id=? AND t.track_type<>'fact'
        ORDER BY t.track_type, t.set_no", [$id]);
}
?>
<style>
.decision-guide{background:#eef5ff;border:1px solid #c9dcff;border-radius:16px;padding:20px 24px;margin-bottom:22px;color:#243b63}
.decision-guide h3{margin:0 0 10px;color:#173f91}
.decision-guide ol{margin:8px 0 0;padding-left:25px;line-height:1.8}
.decision-card{border-left:5px solid #1f4aa5}
.decision-card.serious{border-left-color:#b43b3b}
.decision-card h3{margin-top:0}
.decision-type{display:inline-block;padding:5px 11px;border-radius:999px;background:#e8f0ff;color:#173f91;font-size:.92rem;margin-left:8px}
.decision-card.serious .decision-type{background:#fff0f0;color:#9e2f2f}
.field-help{display:block;color:#68758b;font-size:.88rem;line-height:1.45;margin-top:6px}
.required-mark{color:#b42318}
.save-help{color:#52627a;font-size:.92rem;align-self:center}
@media(max-width:760px){.decision-guide{padding:16px 18px}.decision-type{display:block;width:max-content;margin:8px 0 0}}
</style>

<section class="card">
    <h2 class="section-title">คำสั่งและรายงาน ก.ก.</h2>

    <div class="decision-guide">
        <h3>คำแนะนำการบันทึกผลรายบุคคล</h3>
        <ol>
            <li>ระบบแสดงผู้ถูกกล่าวหาแต่ละรายตามประเภทการสอบสวนที่เลือกไว้ในขั้นตอน 3 เท่านั้น บุคคลหนึ่งจึงควรมีแบบบันทึกเพียงชุดเดียว</li>
            <li>กรอกวันที่รายงาน ก.ก. และผลที่ผู้บังคับบัญชาพิจารณาหรือสั่งลงโทษให้ตรงตามหนังสือหรือคำสั่งจริง</li>
            <li>กรณีสอบสวนวินัยอย่างร้ายแรง ต้องกรอกวันที่มีมติ ก.ก. และสาระสำคัญของมติ ก.ก. ก่อนบันทึกเลขที่คำสั่ง</li>
            <li>เลขที่คำสั่งและวันที่คำสั่งให้กรอกเมื่อมีคำสั่งแล้ว ส่วน “เรื่องเสร็จเมื่อวันที่” ให้ใช้วันที่ดำเนินการในสำนวนเสร็จสิ้นครบถ้วน</li>
            <li>ปุ่มบันทึกในแต่ละกรอบจะบันทึกเฉพาะบุคคลที่ระบุบนหัวกรอบ หากมีผู้ถูกกล่าวหาหลายราย ให้ตรวจและบันทึกทีละราย</li>
        </ol>
    </div>

    <?php if (!$decisionRows && !$trackDecisionRows): ?>
        <div class="notice warn">
            <?= $subjects ? 'ยังไม่มีผู้ถูกกล่าวหาที่เข้าสู่ชั้นสอบสวน หรือยังไม่ได้เลือกประเภทการสอบสวนในขั้นตอน 3' : 'ยังไม่ได้เลือกประเภทการสอบสวนในขั้นตอน 3' ?>
            <a href="<?=appUrl('case.php?id='.$id.'&page=3')?>">ไปยังขั้นตอน 3</a>
        </div>
    <?php endif; ?>

    <?php foreach ($trackDecisionRows as $row):
        $isSerious = $row['track_type'] === 'discipline_serious';
        $trackLabel = trackTitle($row);
    ?>
        <div class="subject decision-card <?=$isSerious?'serious':'nonserious'?>">
            <h3>
                <?=h($trackLabel)?>
                <span class="decision-type"><?=$isSerious?'วินัยอย่างร้ายแรง':'วินัยอย่างไม่ร้ายแรง'?></span>
            </h3>

            <?php if ($edit): ?>
                <form method="post" action="<?=appUrl('save_case.php')?>" class="grid decision-form">
                    <input type="hidden" name="csrf" value="<?=csrfToken()?>">
                    <input type="hidden" name="action" value="save_track_decision">
                    <input type="hidden" name="case_id" value="<?=$id?>">
                    <input type="hidden" name="track_id" value="<?=(int)$row['current_track_id']?>">

                    <div>
                        <label>รายงาน ก.ก. เมื่อวันที่</label>
                        <input type="date" name="report_kk_date" value="<?=h($row['report_kk_date']??'')?>">
                        <small class="field-help">วันที่ส่งหรือรายงานเรื่องต่อ ก.ก. ตามหลักฐานในสำนวน</small>
                    </div>

                    <?php if ($isSerious): ?>
                        <div>
                            <label>วันที่มีมติ ก.ก. <span class="required-mark">*</span></label>
                            <input type="date" name="kk_resolution_date" value="<?=h($row['kk_resolution_date']??'')?>">
                            <small class="field-help">วันที่ ก.ก. มีมติเกี่ยวกับผลการสอบสวนหรือการลงโทษ</small>
                        </div>
                        <div class="wide">
                            <label>มติ ก.ก. <span class="required-mark">*</span></label>
                            <textarea name="kk_resolution" placeholder="สรุปสาระสำคัญของมติ ก.ก. ให้ครบถ้วน"><?=h($row['kk_resolution']??'')?></textarea>
                            <small class="field-help">ต้องบันทึกมติ ก.ก. ก่อนหรือพร้อมกับการบันทึกคำสั่งในกรณีร้ายแรง</small>
                        </div>
                    <?php endif; ?>

                    <div>
                        <label>ผู้บังคับบัญชาพิจารณา/สั่งลงโทษ <span class="required-mark">*</span></label>
                        <select name="commander_decision" required>
                            <option value="">-- เลือกผลการพิจารณา --</option>
                            <?php foreach (['ยุติเรื่อง','ไม่อาจดำเนินการทางวินัย','งดโทษ','ภาคทัณฑ์','ตัดเงินเดือน','ลดเงินเดือน','ปลดออก','ไล่ออก'] as $value): ?>
                                <option value="<?=h($value)?>" <?=($row['commander_decision']??'')===$value?'selected':''?>><?=h($value)?></option>
                            <?php endforeach; ?>
                        </select>
                        <small class="field-help">เลือกผลสุดท้ายตามคำสั่งของผู้บังคับบัญชา</small>
                    </div>

                    <div>
                        <label>เลขที่คำสั่ง</label>
                        <input name="order_number" value="<?=h($row['order_number']??'')?>" placeholder="เช่น กท 0401/123">
                        <small class="field-help">กรอกเลขที่คำสั่งเต็มตามเอกสาร ไม่ควรกรอกเฉพาะเลขลำดับ</small>
                    </div>

                    <div>
                        <label>วันที่คำสั่ง</label>
                        <input type="date" name="order_date" value="<?=h($row['order_date']??'')?>">
                        <small class="field-help">วันที่ออกคำสั่งของผู้มีอำนาจ</small>
                    </div>

                    <div>
                        <label>เรื่องเสร็จเมื่อวันที่</label>
                        <input type="date" name="finished_date" value="<?=h($row['finished_date']??'')?>">
                        <small class="field-help">วันที่ดำเนินการตามขั้นตอนและจัดเก็บสำนวนเสร็จเรียบร้อย</small>
                    </div>

                    <div class="actions wide">
                        <button class="btn green">บันทึกคำสั่ง/รายงาน ก.ก.</button>
                    </div>
                </form>
            <?php else: ?>
                <p>ผลการพิจารณา <?=h(($row['commander_decision']??'') ?: 'ยังไม่บันทึก')?></p>
                <p>เลขที่คำสั่ง <?=h(($row['order_number']??'') ?: 'ยังไม่บันทึก')?></p>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>

    <?php foreach ($decisionRows as $index => $row):
        $isSerious = $row['track_type'] === 'discipline_serious';
        $personLabel = subjectDisplayName($row);
    ?>
        <div class="subject decision-card <?=$isSerious?'serious':'nonserious'?>">
            <h3>
                ผู้ถูกกล่าวหา: <?=h($personLabel)?>
                <span class="decision-type"><?=$isSerious?'วินัยอย่างร้ายแรง':'วินัยอย่างไม่ร้ายแรง'?></span>
            </h3>

            <?php if ($edit): ?>
                <form method="post" action="<?=appUrl('save_case.php')?>" class="grid decision-form">
                    <input type="hidden" name="csrf" value="<?=csrfToken()?>">
                    <input type="hidden" name="action" value="save_decision">
                    <input type="hidden" name="case_id" value="<?=$id?>">
                    <input type="hidden" name="subject_id" value="<?=(int)$row['form_subject_id']?>">
                    <input type="hidden" name="track_id" value="<?=(int)$row['current_track_id']?>">

                    <div>
                        <label>รายงาน ก.ก. เมื่อวันที่</label>
                        <input type="date" name="report_kk_date" value="<?=h($row['report_kk_date']??'')?>">
                        <small class="field-help">วันที่ส่งหรือรายงานเรื่องต่อ ก.ก. ตามหลักฐานในสำนวน</small>
                    </div>

                    <?php if ($isSerious): ?>
                        <div>
                            <label>วันที่มีมติ ก.ก. <span class="required-mark">*</span></label>
                            <input type="date" name="kk_resolution_date" value="<?=h($row['kk_resolution_date']??'')?>">
                            <small class="field-help">วันที่ ก.ก. มีมติเกี่ยวกับผลการสอบสวนหรือการลงโทษ</small>
                        </div>
                        <div class="wide">
                            <label>มติ ก.ก. <span class="required-mark">*</span></label>
                            <textarea name="kk_resolution" placeholder="สรุปสาระสำคัญของมติ ก.ก. ให้ครบถ้วน"><?=h($row['kk_resolution']??'')?></textarea>
                            <small class="field-help">ต้องบันทึกมติ ก.ก. ก่อนหรือพร้อมกับการบันทึกคำสั่งในกรณีร้ายแรง</small>
                        </div>
                    <?php endif; ?>

                    <div>
                        <label>ผู้บังคับบัญชาพิจารณา/สั่งลงโทษ <span class="required-mark">*</span></label>
                        <select name="commander_decision" required>
                            <option value="">-- เลือกผลการพิจารณา --</option>
                            <?php
                            $payCut = $row['employment_type']==='ลูกจ้าง' ? 'ตัดค่าจ้าง' : 'ตัดเงินเดือน';
                            $payReduce = $row['employment_type']==='ลูกจ้าง' ? 'ลดขั้นค่าจ้าง' : 'ลดเงินเดือน';
                            foreach (['ยุติเรื่อง','ไม่อาจดำเนินการทางวินัย','งดโทษ','ภาคทัณฑ์',$payCut,$payReduce,'ปลดออก','ไล่ออก'] as $value):
                            ?>
                                <option value="<?=h($value)?>" <?=($row['commander_decision']??'')===$value?'selected':''?>><?=h($value)?></option>
                            <?php endforeach; ?>
                        </select>
                        <small class="field-help">เลือกผลสุดท้ายตามคำสั่งของผู้บังคับบัญชา</small>
                    </div>

                    <div>
                        <label>เลขที่คำสั่ง</label>
                        <input name="order_number" value="<?=h($row['order_number']??'')?>" placeholder="เช่น กท 0401/123">
                        <small class="field-help">กรอกเลขที่คำสั่งเต็มตามเอกสาร ไม่ควรกรอกเฉพาะเลขลำดับ</small>
                    </div>

                    <div>
                        <label>วันที่คำสั่ง</label>
                        <input type="date" name="order_date" value="<?=h($row['order_date']??'')?>">
                        <small class="field-help">วันที่ออกคำสั่งของผู้มีอำนาจ</small>
                    </div>

                    <div>
                        <label>เรื่องเสร็จเมื่อวันที่</label>
                        <input type="date" name="finished_date" value="<?=h($row['finished_date']??'')?>">
                        <small class="field-help">วันที่ดำเนินการตามขั้นตอนและจัดเก็บสำนวนเสร็จเรียบร้อย</small>
                    </div>

                    <div class="actions wide">
                        <button class="btn green">บันทึกผลของ <?=h($personLabel)?></button>
                        <span class="save-help">ข้อมูลในกรอบนี้บันทึกแยกจากผู้ถูกกล่าวหารายอื่น</span>
                    </div>
                </form>
            <?php else: ?>
                <p>ผลการพิจารณา <?=h(($row['commander_decision']??'') ?: 'ยังไม่บันทึก')?></p>
                <p>เลขที่คำสั่ง <?=h(($row['order_number']??'') ?: 'ยังไม่บันทึก')?></p>
                <a href="<?=h(appUrl('subject.php?case_id='.$id.'&subject_id='.(int)$row['form_subject_id']))?>">ดูรายละเอียดรายบุคคล</a>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</section>

<script>
document.querySelectorAll('.decision-form').forEach(function(form){
    form.addEventListener('submit', function(event){
        var resolution = form.querySelector('[name="kk_resolution"]');
        const order = form.elements.order_number.value.trim() || form.elements.order_date.value;
        if (order && resolution && (!resolution.value.trim() || !form.elements.kk_resolution_date.value)) {
            event.preventDefault();
            resolution.focus();
            alert('กรุณากรอกวันที่และมติ ก.ก. ก่อนบันทึกคำสั่งกรณีร้ายแรง');
        }
    });
});
</script>
