<?php

declare(strict_types=1);

namespace App\Application\Query;

use App\Application\Auth\AuthSettings;
use App\Domain\Access\CaseScope;
use App\Domain\Workflow\{CalendarDate, TrackType};
use App\Infrastructure\Persistence\Repository\{CaseRepository, TrackRepository};
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class DashboardAssistant
{
    public function __construct(private AuthSettings $settings, private CaseRepository $cases,
        private TrackRepository $tracks, private string $configFile)
    {
    }

    public function summarize(CaseScope $scope): array
    {
        $config = !$this->settings->isDemo() && is_file($this->configFile) ? require $this->configFile : [];
        if (empty($config['base_url']) || empty($config['api_key'])) {
            return ['ok'=>false, 'message'=>'ยังไม่ได้ตั้งค่าผู้ช่วย AI'];
        }

        $data = $this->statistics($scope);
        $payload = ['model'=>$config['model'] ?? '', 'temperature'=>0.2, 'max_tokens'=>700,
            'chat_template_kwargs'=>['enable_thinking'=>false], 'messages'=>[
                ['role'=>'system', 'content'=>'คุณเป็นผู้ช่วยสรุป Dashboard ระบบสำนวนวินัย UAT ใช้เฉพาะสถิติที่ไม่ระบุตัวบุคคล ตอบภาษาไทยแบบกระชับ 4-6 ข้อ โดยสรุปภาพรวม ความเสี่ยงของกำหนดเวลา และสิ่งที่ควรติดตาม ห้ามเดาข้อเท็จจริง ห้ามให้ความเห็นทางกฎหมาย และห้ามกล่าวถึงหรือร้องขอข้อมูลส่วนบุคคล'],
                ['role'=>'user', 'content'=>'สรุปผู้บริหารจากข้อมูลสถิติต่อไปนี้: '.json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)],
            ]];
        $request = curl_init(rtrim($config['base_url'], '/').'/chat/completions');
        curl_setopt_array($request, [
            CURLOPT_POST=>true, CURLOPT_RETURNTRANSFER=>true, CURLOPT_CONNECTTIMEOUT=>5, CURLOPT_TIMEOUT=>30,
            CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Bearer '.$config['api_key']],
            CURLOPT_POSTFIELDS=>json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ]);
        $body = curl_exec($request);
        $status = curl_getinfo($request, CURLINFO_RESPONSE_CODE);
        $answer = is_string($body) ? (json_decode($body, true)['choices'][0]['message']['content'] ?? null) : null;
        if ($status < 200 || $status >= 300 || !is_string($answer) || trim($answer) === '') {
            return ['ok'=>false, 'message'=>'AI Gateway ไม่พร้อม กรุณาลองใหม่'];
        }
        return ['ok'=>true, 'summary'=>trim($answer)];
    }

    private function statistics(CaseScope $scope): array
    {
        $counts = $this->cases->dashboardCounts($scope);
        $overdue = $soon = 0;
        $byProcess = [];
        $today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Bangkok')))->format('Y-m-d');
        $start = CalendarDate::parse($today);
        foreach ($this->tracks->deadlines() as $track) {
            $type = $track['track_type'];
            if (TrackType::tryFrom($type) === null) {
                continue;
            }
            try {
                $due = CalendarDate::parse($track['effective_due']);
            } catch (InvalidArgumentException) {
                continue;
            }
            $days = (int)$start->diff($due)->format('%r%a');
            $byProcess[$type] ??= ['overdue'=>0, 'within_7_days'=>0];
            if ($days < 0) {
                ++$overdue;
                ++$byProcess[$type]['overdue'];
            } elseif ($days <= 7) {
                ++$soon;
                ++$byProcess[$type]['within_7_days'];
            }
        }
        return ['จำนวนสำนวนทั้งหมด'=>(int)$counts['total'], 'ปิดสำนวนแล้ว'=>(int)$counts['closed'],
            'อยู่ชั้นสอบสวนทางวินัย'=>(int)$counts['discipline'], 'ยังไม่ปิดสำนวน'=>(int)$counts['total'] - (int)$counts['closed'],
            'กำหนดเวลาเกินแล้ว'=>$overdue, 'กำหนดเวลาภายใน 7 วัน'=>$soon, 'แยกตามกระบวนการ'=>$byProcess];
    }
}
