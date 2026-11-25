<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Application\Auth\AuthSettings;
use App\Application\Query\DashboardAssistant;
use App\Infrastructure\Persistence\Repository\{CaseRepository, TrackRepository};
use App\Tests\Functional\Write\WriteTestCase;
use DateTimeImmutable;
use DateTimeZone;

final class DashboardGatewayTest extends WriteTestCase
{
    public function testLiveAdapterPostsOnlyAggregateStatisticsAndHandlesGatewayFailure(): void
    {
        $this->login('demo-admin');
        $day = new DateTimeImmutable('today', new DateTimeZone('Asia/Bangkok'));
        $due = $day->modify('-1 day')->format('Y-m-d');
        $extension = $day->modify('+7 days')->format('Y-m-d');
        $this->db->prepare("INSERT INTO uat_tracks(case_id,track_type,due_date) VALUES(1,'fact',?),(1,'discipline_nonserious',?)")->execute([$due,$due]);
        $this->db->prepare("INSERT INTO uat_extensions(track_id,sequence_no,new_due_date) VALUES(2,1,?)")->execute([$extension]);
        file_put_contents($this->directory.'/gateway.php', <<<'PHP'
            <?php
            if ($_SERVER['REQUEST_URI'] !== '/v1/chat/completions' || $_SERVER['REQUEST_METHOD'] !== 'POST'
                || $_SERVER['HTTP_AUTHORIZATION'] !== 'Bearer synthetic-key' || $_SERVER['CONTENT_TYPE'] !== 'application/json') {
                http_response_code(400);
                exit;
            }
            file_put_contents(__DIR__.'/posted.json', file_get_contents('php://input'));
            http_response_code(is_file(__DIR__.'/outage') ? 502 : 200);
            echo '{"choices":[{"message":{"content":"  Synthetic summary  "}}]}';
            PHP);
        $log = tmpfile();
        $server = proc_open([PHP_BINARY, '-S', '127.0.0.1:0', $this->directory.'/gateway.php'], [1=>$log, 2=>$log], $pipes);
        self::assertIsResource($server);
        try {
            for ($attempt = 0; $attempt < 100; ++$attempt) {
                if (preg_match('~Development Server \((http://127\.0\.0\.1:\d+)\) started~', file_get_contents(stream_get_meta_data($log)['uri']), $match)) {
                    break;
                }
                usleep(20000);
            }
            self::assertArrayHasKey(1, $match);
            $config = $this->directory.'/ai.php';
            file_put_contents($config, '<?php return '.var_export(['base_url'=>$match[1].'/v1', 'model'=>'synthetic', 'api_key'=>'synthetic-key'], true).';');
            $services = $this->client->getKernel()->getContainer()->get('test.service_container');
            $services->set(DashboardAssistant::class, new DashboardAssistant(new AuthSettings('prod', $this->directory.'/unused.php'),
                new CaseRepository($this->db), new TrackRepository($this->db), $config));

            $result = $this->post([], 200, '/ai_dashboard.php');
            self::assertSame(['ok'=>true,'summary'=>'Synthetic summary'], json_decode($result->getContent(), true, flags: JSON_THROW_ON_ERROR));
            $posted = json_decode(file_get_contents($this->directory.'/posted.json'), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame('synthetic', $posted['model']);
            self::assertSame(['system','user'], array_column($posted['messages'], 'role'));
            $prefix = 'สรุปผู้บริหารจากข้อมูลสถิติต่อไปนี้: ';
            self::assertStringStartsWith($prefix, $posted['messages'][1]['content']);
            $stats = json_decode(substr($posted['messages'][1]['content'], strlen($prefix)), true, flags: JSON_THROW_ON_ERROR);
            self::assertEquals(['จำนวนสำนวนทั้งหมด'=>1,'ปิดสำนวนแล้ว'=>0,'อยู่ชั้นสอบสวนทางวินัย'=>0,'ยังไม่ปิดสำนวน'=>1,
                'กำหนดเวลาเกินแล้ว'=>1,'กำหนดเวลาภายใน 7 วัน'=>1,'แยกตามกระบวนการ'=>[
                    'fact'=>['overdue'=>1,'within_7_days'=>0],
                    'discipline_nonserious'=>['overdue'=>0,'within_7_days'=>1],
                ]], $stats);
            touch($this->directory.'/outage');
            $result = $this->post([], 503, '/ai_dashboard.php');
            self::assertSame(['ok'=>false,'message'=>'AI Gateway ไม่พร้อม กรุณาลองใหม่'], json_decode($result->getContent(), true, flags: JSON_THROW_ON_ERROR));
        } finally {
            proc_terminate($server);
            proc_close($server);
            fclose($log);
        }
    }
}
