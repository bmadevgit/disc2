<?php
require __DIR__.'/auth.php'; require __DIR__.'/db.php'; requireLogin(); require __DIR__.'/ui.php'; require __DIR__.'/workflow.php';
$access=uatAccess($pdo); $role=$access['access_role'];
if($role==='none'){pageHead('ยังไม่ได้กำหนดสิทธิ์');?><section class="card"><h1>ยังไม่ได้กำหนดสิทธิ์ UAT</h1><p>ผู้ดูแลระบบต้องกำหนด Role และขอบเขตหน่วยงานก่อนใช้งาน</p></section><?php pageFoot();exit;}
if(in_array($role,['executive','unit_head'],true)){header('Location: '.appUrl('admin.php'));exit;}
pageHead('รายการสำนวน'); ?>
<section class="card hero"><div><h1 class="section-title">สำนวนวินัย — UAT</h1><div class="muted">Role: <?=h($role)?> · สามารถเพิ่มสำนวนและดำเนินขั้นตอนถัดไปได้โดยไม่ต้องระบุผู้ถูกกล่าวหารายบุคคลก่อน</div></div><?php if($role==='admin'): ?><a class="btn" href="<?=appUrl('roles.php')?>">จัดการสิทธิ์</a><?php endif ?></section>
<?php if(in_array($role,['admin','case_owner'],true)): ?><section id="new" class="card" tabindex="-1"><h2 class="section-title">เพิ่มสำนวน UAT</h2><p id="new-case-status" class="muted" aria-live="polite">กรอกเลขที่สำนวนและตรวจสอบหน่วยงาน ก่อนกดสร้างสำนวน</p><form id="new-case-form" method="post" action="<?=appUrl('save_case.php')?>" class="grid"><input type="hidden" name="csrf" value="<?=csrfToken()?>"><input type="hidden" name="action" value="create_case"><div><label>เลขที่สำนวน</label><input name="case_number" required></div><div><label>หน่วยงานที่รับผิดชอบ</label><input name="responsible_unit" value="<?=h($access['unit_level1'] ?: $_SESSION['user_unit_level1']??'')?>" required></div><div><label>UnitLevel2/กลุ่มงาน</label><input name="unit_level2"></div><?php caseTypeFields(); ?><div><label>วันที่รับเรื่อง</label><input type="date" name="received_date"></div><div class="wide"><label>ข้อกล่าวหาเบื้องต้น</label><textarea name="preliminary_allegation"></textarea></div><div class="actions wide"><button class="btn green" id="create-case-button">สร้างสำนวนและกรอกข้อมูลต่อ</button></div></form></section><?php endif ?>
<?php require __DIR__.'/case_listing.php'; ?>
<?php if(in_array($role,['admin','case_owner'],true)): ?><script>
(() => {
  const form = document.getElementById('new-case-form');
  const status = document.getElementById('new-case-status');
  const submit = document.getElementById('create-case-button');
  form?.addEventListener('submit', () => {
    if (!form.checkValidity()) return;
    submit.disabled = true;
    submit.textContent = 'กำลังสร้างสำนวน…';
    status.textContent = 'ระบบกำลังสร้างสำนวน โปรดรอสักครู่';
  });
})();
</script><?php endif; pageFoot();
