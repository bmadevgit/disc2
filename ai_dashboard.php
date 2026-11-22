<?php
require __DIR__ . '/auth.php';
require __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');
requireLogin(true);
verifyCsrf();
if (uatAccess($pdo)['access_role'] !== 'admin') {
    http_response_code(403); echo json_encode(['ok'=>false,'message'=>'ไม่มีสิทธิ์ใช้งานผู้ช่วย AI']); exit;
}
$configFile = getenv('DISC2_AI_CONFIG_FILE') ?: dirname(__DIR__, 3).'/private/disc2-ai.php';
if (disc2DevMode() || !is_file($configFile)) { http_response_code(503); echo json_encode(['ok'=>false,'message'=>'ยังไม่ได้ตั้งค่าผู้ช่วย AI']); exit; }
$config = require $configFile;
$stats = $pdo->query("SELECT COUNT(*) AS total, SUM(CASE WHEN COALESCE(m.stage,'case_info')='closed' THEN 1 ELSE 0 END) AS closed, SUM(CASE WHEN COALESCE(m.stage,'case_info')='discipline' THEN 1 ELSE 0 END) AS discipline FROM cases c LEFT JOIN uat_case_meta m ON m.case_id=c.id")->fetch();
$tracks = $pdo->query("SELECT t.track_type, COALESCE((SELECT new_due_date FROM uat_extensions e WHERE e.track_id=t.id ORDER BY sequence_no DESC LIMIT 1),t.due_date) AS effective_due FROM uat_tracks t WHERE t.due_date<>''")->fetchAll();
$today = new DateTimeImmutable('today'); $deadline=['overdue'=>0,'within_7_days'=>0,'by_process'=>[]];
foreach ($tracks as $track) {
    try { $due=new DateTimeImmutable($track['effective_due']); } catch (Throwable $e) { continue; }
    $days=(int)$today->diff($due)->format('%r%a'); $type=(string)$track['track_type'];
    if (!isset($deadline['by_process'][$type])) $deadline['by_process'][$type]=['overdue'=>0,'within_7_days'=>0];
    if ($days<0) { $deadline['overdue']++; $deadline['by_process'][$type]['overdue']++; }
    elseif ($days<=7) { $deadline['within_7_days']++; $deadline['by_process'][$type]['within_7_days']++; }
}
$summary=['จำนวนสำนวนทั้งหมด'=>(int)($stats['total']??0),'ปิดสำนวนแล้ว'=>(int)($stats['closed']??0),'อยู่ชั้นสอบสวนทางวินัย'=>(int)($stats['discipline']??0),'ยังไม่ปิดสำนวน'=>max(0,(int)($stats['total']??0)-(int)($stats['closed']??0)),'กำหนดเวลาเกินแล้ว'=>$deadline['overdue'],'กำหนดเวลาภายใน 7 วัน'=>$deadline['within_7_days'],'แยกตามกระบวนการ'=>$deadline['by_process']];
$payload=['model'=>(string)($config['model']??''),'temperature'=>0.2,'max_tokens'=>700,'chat_template_kwargs'=>['enable_thinking'=>false],'messages'=>[['role'=>'system','content'=>'คุณเป็นผู้ช่วยสรุป Dashboard ระบบสำนวนวินัย UAT ใช้เฉพาะสถิติที่ไม่ระบุตัวบุคคล ตอบภาษาไทยแบบกระชับ 4-6 ข้อ โดยสรุปภาพรวม ความเสี่ยงของกำหนดเวลา และสิ่งที่ควรติดตาม ห้ามเดาข้อเท็จจริง ห้ามให้ความเห็นทางกฎหมาย และห้ามกล่าวถึงหรือร้องขอข้อมูลส่วนบุคคล'],['role'=>'user','content'=>'สรุปผู้บริหารจากข้อมูลสถิติต่อไปนี้: '.json_encode($summary,JSON_UNESCAPED_UNICODE)]]];
$ch=curl_init(rtrim((string)$config['base_url'],'/').'/chat/completions');
curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($payload,JSON_UNESCAPED_UNICODE),CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>30,CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Bearer '.(string)$config['api_key']]]);
$raw=curl_exec($ch); $code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
$result=is_string($raw)?json_decode($raw,true):null; $answer=trim((string)($result['choices'][0]['message']['content']??''));
if ($code<200 || $code>=300 || $answer==='') { http_response_code(503); echo json_encode(['ok'=>false,'message'=>'AI Gateway ไม่พร้อม กรุณาลองใหม่']); exit; }
echo json_encode(['ok'=>true,'summary'=>$answer],JSON_UNESCAPED_UNICODE);
