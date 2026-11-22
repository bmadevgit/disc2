<?php
require __DIR__.'/auth.php';require __DIR__.'/db.php';requireLogin();require __DIR__.'/ui.php';require __DIR__.'/workflow.php';
$caseId=(int)($_GET['case_id']??0);$type=$_GET['type']??'';if(!in_array($type,['discipline_nonserious','discipline_serious'],true)||!canViewCase($pdo,$caseId)){http_response_code(403);exit('ไม่มีสิทธิ์');}$edit=canEditCase($pdo,$caseId);$tr=disciplineTrack($pdo,$caseId,$type,(int)($_GET['track_id']??0));
$title=trackTitle($tr);
$orders=trackOrders($pdo,(int)$tr['id']);
$ext=trackExtensions($pdo,(int)$tr['id']);
$days=$type==='discipline_serious' ? ((int)$tr['duration_days']===180 ? 180 : 120) : 60;
pageHead($title);?><section class="card hero"><div><a href="<?=appUrl('case.php?id='.$caseId.'&page=3')?>">← กลับหน้าสอบสวน</a><h1 class="section-title" style="margin-top:10px"><?=$title?></h1></div><button class="btn small gray" onclick="window.print()">พิมพ์ข้อมูล</button></section><?php if(isset($_GET['saved'])):?><div class="notice">บันทึกแล้ว</div><?php endif?><section class="card"><h2 class="section-title">กำหนดระยะเวลา</h2><?php if ($edit): ?>
<form method="post" action="<?=appUrl('save_case.php')?>" class="grid" data-deadline="<?=h($type)?>">
    <input type="hidden" name="csrf" value="<?=csrfToken()?>">
    <input type="hidden" name="action" value="save_track">
    <input type="hidden" name="case_id" value="<?=$caseId?>">
    <input type="hidden" name="track_id" value="<?=$tr['id']?>">
    <input type="hidden" name="track_type" value="<?=h($type)?>">
    <?php deadlineFields($tr,$days); ?>
    <div class="actions wide"><button class="btn green">บันทึกกำหนดเวลา</button></div>
</form>
<?php else: ?>
<p>วันเริ่มนับ <?=h($tr['start_date'] ?: 'ยังไม่ระบุ')?> · <?=$days?> วัน · ครบกำหนด <?=h($tr['due_date'] ?: 'ยังไม่ระบุ')?></p>
<?php endif ?>
<?php if ($edit && $days === 180): $new = conversionValues($tr); ?>
<details>
    <summary>ตรวจสอบการปรับระยะเวลาเดิมเป็น 120 วัน</summary>
    <p>วันเริ่มนับ <?=h($tr['start_date'] ?: 'ยังไม่ระบุ')?> → <?=h($new[2] ?: 'ยังไม่ระบุ')?></p>
    <p>วันครบกำหนด <?=h($tr['due_date'] ?: 'ยังไม่ระบุ')?> → <?=h($new[4] ?: 'ยังไม่ระบุ')?></p>
    <?php if ($ext): ?>
    <table><thead><tr><th>ขยายครั้งที่</th><th>ครบกำหนดเดิม</th><th>ครบกำหนดหลังปรับ</th></tr></thead><tbody>
        <?php foreach (revisedExtensions($ext,$new[4]) as $key=>$extension): ?>
        <tr><td><?=$extension['sequence_no']?></td><td><?=h($ext[$key]['new_due_date'])?></td><td><?=h($extension['new_due_date'])?></td></tr>
        <?php endforeach ?>
    </tbody></table>
    <?php endif ?>
    <form method="post" action="<?=appUrl('save_case.php')?>" class="grid">
        <input type="hidden" name="csrf" value="<?=csrfToken()?>">
        <input type="hidden" name="action" value="convert_serious_deadline">
        <input type="hidden" name="track_type" value="<?=h($type)?>">
        <input type="hidden" name="case_id" value="<?=$caseId?>">
        <input type="hidden" name="track_id" value="<?=$tr['id']?>">
        <input type="hidden" name="revision" value="<?=h(deadlineRevision($tr,$ext))?>">
        <div><label>เหตุผลที่ปรับระยะเวลา<input name="reason" required></label></div>
        <div class="actions"><button class="btn green">ยืนยันปรับระยะเวลาเป็น 120 วัน</button></div>
    </form>
</details>
<?php endif ?>
<p><a class="btn small" href="<?=h(appUrl(trackUrl($tr,'discipline_results.php')))?>">ผลการสอบสวนวินัยรายบุคคล</a></p>
</section>
<section class="card"><h2 class="section-title">คำสั่งคณะกรรมการ</h2><?php if($edit):?><form method="post" action="<?=appUrl('save_case.php')?>" class="grid"><input type="hidden" name="csrf" value="<?=csrfToken()?>"><input type="hidden" name="action" value="add_order"><input type="hidden" name="case_id" value="<?=$caseId?>"><input type="hidden" name="track_id" value="<?=$tr['id']?>"><div><label>รายการ</label><select name="event_type"><option value="appoint">แต่งตั้ง</option><option value="change_committee">เปลี่ยนแปลงคณะกรรมการ</option><option value="amend_order">แก้ไขเพิ่มเติมคำสั่ง</option></select></div><div><label>วันที่คำสั่ง</label><input type="date" name="order_date"></div><div><label>เลขคำสั่ง</label><input name="order_number"></div><div><label>รายละเอียด</label><input name="detail"></div><div><button class="btn small">เพิ่มรายการ</button></div></form><?php endif?><table><thead><tr><th>รายการ</th><th>เลขคำสั่ง</th><th>วันที่</th><th>รายละเอียด</th></tr></thead><tbody><?php foreach($orders as $o):?><tr><td><?=h($o['event_type'])?></td><td><?=h($o['order_number'])?></td><td><?=h($o['order_date'])?></td><td><?=h($o['detail'])?></td></tr><?php endforeach?></tbody></table></section><section class="card"><h2 class="section-title">ขยายเวลาสอบสวน</h2><?php if($edit):?><form method="post" action="<?=appUrl('save_case.php')?>" class="grid"><input type="hidden" name="csrf" value="<?=csrfToken()?>"><input type="hidden" name="action" value="add_extension"><input type="hidden" name="case_id" value="<?=$caseId?>"><input type="hidden" name="track_id" value="<?=$tr['id']?>"><div><label>จำนวนวันที่ขอขยาย</label><input type="number" name="requested_days" min="1" required></div><div><label>การดำเนินการที่ผ่านมา</label><input name="completed_work"></div><div><label>เหตุผล</label><input name="reason"></div><div><button class="btn small">เพิ่มการขยาย</button></div></form><?php endif?><table><thead><tr><th>ครั้ง</th><th>วัน</th><th>การดำเนินการ</th><th>เหตุผล</th><th>ครบกำหนดใหม่</th></tr></thead><tbody><?php foreach($ext as $e):?><tr><td><?=$e['sequence_no']?></td><td><?=$e['requested_days']?></td><td><?=h($e['completed_work'])?></td><td><?=h($e['reason'])?></td><td><?=h($e['new_due_date'])?></td></tr><?php endforeach?></tbody></table></section><script src="<?=appUrl('workflow.js')?>" defer></script><?php pageFoot();
