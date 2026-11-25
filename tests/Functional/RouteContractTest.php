<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Functional\Write\WriteTestCase;

final class RouteContractTest extends WriteTestCase
{
    public function testContractTableMatchesNativeRouteNames(): void
    {
        $router = $this->client->getKernel()->getContainer()->get('router');
        $count = 0;
        foreach (file($this->root.'/docs/rewrite/route-contract.md') as $line) {
            if (!preg_match('~^\| \x60(/(?:[a-z0-9_]+\.php)?)\x60 \|~', $line, $match)) {
                continue;
            }
            ++$count;
            $columns = array_map('trim', explode('|', trim(trim($line), '|')));
            if (in_array($columns[1], ['internal include', 'CLI-only'], true)) {
                continue; // ExposureTest exercises every denied helper over HTTP.
            }
            self::assertSame($columns[6], $router->match($match[1])['_route'], $match[1]);
        }
        self::assertSame(33, $count);
    }

    public function testEveryPublicBookmarkAtTheAnonymousBoundary(): void
    {
        foreach (['/', '/index.php', '/admin.php', '/ai_dashboard.php', '/case.php', '/discipline_results.php',
            '/edit.php', '/logout.php', '/profile.php', '/roles.php', '/save_case.php', '/save_comment2.php',
            '/save_discipline.php', '/subject.php', '/track.php', '/users.php', '/view_case.php'] as $path) {
            $this->client->request('GET', $path);
            self::assertSame(302, $this->client->getResponse()->getStatusCode(), $path);
            self::assertSame('/login.php', $this->client->getResponse()->headers->get('Location'), $path);
        }
        $this->client->request('GET', '/login.php');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->client->request('GET', '/i.php');
        self::assertSame('/index.php', $this->client->getResponse()->headers->get('Location'));
    }

    public function testRetiredUrlsReturnTheirExactMessageRegardlessOfMethodOrLogin(): void
    {
        foreach (['/save.php'=>'UAT ใช้ save_case.php ตาม Workflow 4 หน้า',
            '/update_case.php'=>'UAT ใช้ save_case.php ตาม Workflow 4 หน้า',
            '/update_unit_target.php'=>'ฟังก์ชันยอดรวมเดิมไม่ใช้ใน UAT'] as $path=>$message) {
            foreach (['GET','POST','PUT','DELETE','OPTIONS'] as $method) {
                $this->client->request($method, $path);
                $response = $this->client->getResponse();
                self::assertSame(410, $response->getStatusCode(), "$method $path");
                self::assertSame($message, $response->getContent());
                self::assertStringStartsWith('text/plain', $response->headers->get('Content-Type'));
            }
        }
    }

    public function testOlderFormsAndWriteMethods(): void
    {
        $this->login('demo-owner');
        foreach (['/save_case.php'=>405,'/save_discipline.php'=>405,'/save_comment2.php'=>403] as $path=>$status) {
            $this->client->request('GET', $path.'?case_id=1');
            self::assertSame($status, $this->client->getResponse()->getStatusCode(), $path);
        }
        foreach (['page1','fact','track_time','extension','discipline_result','decision'] as $action) {
            $fields = ['case_id'=>1,'action'=>$action];
            $this->post($fields + ['csrf'=>'invalid'], 419, '/save_comment2.php');
            $response = $this->post($fields, 410, '/save_comment2.php');
            self::assertSame('แบบฟอร์มนี้ยกเลิกแล้ว กรุณาเปิดหน้าสำนวนใหม่', $response->getContent());
        }
        $this->post(['case_id'=>1,'action'=>'unknown'], 422, '/save_comment2.php');
        $this->login('demo-reader');
        $this->post(['case_id'=>1,'action'=>'page1'], 403, '/save_comment2.php');
    }

    public function testDenialMessagesAndDashboardJson(): void
    {
        $this->login('demo-owner');
        foreach (['/case.php?id=999'=>'ไม่มีสิทธิ์เข้าดูสำนวนนี้','/subject.php?case_id=999'=>'ไม่มีสิทธิ์เข้าดูสำนวน'] as $path=>$message) {
            $this->client->request('GET', $path);
            self::assertSame(403, $this->client->getResponse()->getStatusCode());
            self::assertSame($message, $this->client->getResponse()->getContent());
        }
        $denied = $this->post(['csrf'=>'invalid'], 403, '/ai_dashboard.php');
        self::assertSame(['ok'=>false,'message'=>'ไม่มีสิทธิ์ใช้งานผู้ช่วย AI'], json_decode($denied->getContent(), true, flags: JSON_THROW_ON_ERROR));
        $this->login('demo-admin');
        $this->post(['csrf'=>'invalid'], 419, '/ai_dashboard.php');
        foreach (['GET','POST'] as $method) {
            $this->client->request($method, '/ai_dashboard.php', ['csrf'=>$this->csrf]);
            $response = $this->client->getResponse();
            self::assertSame(503, $response->getStatusCode());
            self::assertStringStartsWith('application/json', $response->headers->get('Content-Type'));
            self::assertSame(['ok'=>false,'message'=>'ยังไม่ได้ตั้งค่าผู้ช่วย AI'], json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR));
        }
    }
}
