<?php
require __DIR__ . '/auth.php';
require __DIR__ . '/db.php';
requireLogin();
require __DIR__ . '/ui.php';
require __DIR__.'/workflow.php';

$access = uatAccess($pdo);
if (!in_array($access['access_role'], ['admin', 'executive', 'unit_head'], true)) { header('Location: ' . appUrl('index.php')); exit; }
$isAdmin = $access['access_role'] === 'admin';
$where = ''; $params = [];
if ($access['access_role'] === 'unit_head') { $where = ' WHERE m.unit_level1=?'; $params[] = $access['unit_level1']; }
$query = $pdo->prepare("SELECT COUNT(*) total, SUM(CASE WHEN m.stage='closed' THEN 1 ELSE 0 END) closed, SUM(CASE WHEN m.stage='discipline' THEN 1 ELSE 0 END) discipline FROM cases c LEFT JOIN uat_case_meta m ON m.case_id=c.id $where");
$query->execute($params); $stats = $query->fetch() ?: [];
$query = $pdo->prepare("SELECT COUNT(DISTINCT s.id) FROM uat_subjects s JOIN uat_case_meta m ON m.case_id=s.case_id $where");
$query->execute($params); $subjectCount = (int)$query->fetchColumn();
$items = [];
if ($isAdmin) $items = $pdo->query("SELECT t.*,c.เลขที่สำนวน,COALESCE((SELECT new_due_date FROM uat_extensions e WHERE e.track_id=t.id ORDER BY sequence_no DESC LIMIT 1),t.due_date) effective_due FROM uat_tracks t JOIN cases c ON c.id=t.case_id WHERE t.due_date<>'' ORDER BY effective_due")->fetchAll();
$scopeText = $access['access_role'] === 'executive' ? 'ทุกหน่วยงาน' : ($access['access_role'] === 'unit_head' ? 'เฉพาะหน่วยงานของท่าน' : 'ผู้ดูแลระบบ');
pageHead('Dashboard UAT');
?>
<section class="card hero"><div><h1 class="section-title">Dashboard UAT</h1><div class="muted">สถิติ <?= h($scopeText) ?></div></div><?php if ($isAdmin): ?><a class="btn" href="<?= appUrl('roles.php') ?>">จัดการสิทธิ์</a><?php endif; ?></section>
<section class="metrics"><div class="metric"><span>สำนวนทั้งหมด</span><b><?= (int)($stats['total'] ?? 0) ?></b></div><div class="metric"><span>อยู่ชั้นสอบสวน</span><b><?= (int)($stats['discipline'] ?? 0) ?></b></div><div class="metric"><span>ปิดสำนวนแล้ว</span><b><?= (int)($stats['closed'] ?? 0) ?></b></div><div class="metric"><span>ผู้ถูกกล่าวหา</span><b><?= $subjectCount ?></b></div></section>
<?php if ($isAdmin): ?>
<section class="card"><h2 class="section-title">สรุปผู้บริหารด้วย AI</h2><p class="muted">วิเคราะห์จากจำนวนสำนวนและกำหนดเวลาเท่านั้น ไม่ส่งข้อมูลผู้ถูกกล่าวหา หรือรายละเอียดสำนวนไปยัง AI</p><button type="button" class="btn green" id="ai-dashboard-button">วิเคราะห์สถานะ</button><div id="ai-dashboard-result" class="notice" style="display:none;margin-top:16px;white-space:pre-wrap" aria-live="polite"></div></section>
<section class="card"><h2 class="section-title">ติดตามกำหนดเวลา</h2><table><thead><tr><th>สำนวน</th><th>กระบวนการ</th><th>ครบกำหนด</th><th>สถานะ</th><th></th></tr></thead><tbody><?php foreach ($items as $item): $left=(int)((strtotime($item['effective_due'])-strtotime(date('Y-m-d')))/86400); if($left>30) continue; ?><tr><td><?=h($item['เลขที่สำนวน'])?></td><td><?=h(trackTitle($item))?></td><td><?=h($item['effective_due'])?></td><td class="status" style="color:<?=$left<0?'#b42318':'#9a6700'?>"><?=$left<0?'เลย '.abs($left).' วัน':'เหลือ '.$left.' วัน'?></td><td><a class="btn small" href="<?=h(appUrl($item['track_type']==='fact' ? 'case.php?id='.(int)$item['case_id'].'&page=2' : trackUrl($item)))?>">ดู</a></td></tr><?php endforeach; ?></tbody></table></section>
<script>
(() => { const button=document.getElementById('ai-dashboard-button'), result=document.getElementById('ai-dashboard-result'); button.addEventListener('click',async()=>{ button.disabled=true; button.textContent='กำลังวิเคราะห์…'; result.style.display='block'; result.textContent='กำลังประมวลผลสถิติ…'; try { const response=await fetch(<?=json_encode(appUrl('ai_dashboard.php'))?>,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({csrf:<?=json_encode(csrfToken())?>})}); const data=await response.json(); result.textContent=data.ok?data.summary:(data.message||'ไม่สามารถวิเคราะห์ได้ในขณะนี้'); } catch (_) { result.textContent='AI Gateway ไม่พร้อม กรุณาลองใหม่'; } button.disabled=false; button.textContent='วิเคราะห์สถานะ'; }); })();
</script>
<?php endif; pageFoot(); ?>
