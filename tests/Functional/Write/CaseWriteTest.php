<?php

declare(strict_types=1);

namespace App\Tests\Functional\Write;

final class CaseWriteTest extends WriteTestCase
{
    public function testOwnerCreatesCaseAndUpdatesOnlyTheirCase(): void
    {
        $this->login('demo-owner');
        $create = ['action'=>'create_case','case_number'=>' NEW ','responsible_unit'=>'forged','case_type'=>'อื่นๆ',
            'case_type_other'=>'custom','received_date'=>'2024-02-29','unit_level2'=>'group','preliminary_allegation'=>'detail'];
        self::assertSame('/case.php?id=2&page=1&saved=1', $this->post($create, 302)->headers->get('Location'));
        $owner = $this->rows("SELECT name FROM users WHERE id=2")[0]['name'];
        $unit = $this->rows("SELECT unit_level1 FROM uat_access_assignments WHERE bma_personal_id='2'")[0]['unit_level1'];
        self::assertSame(['NEW',$unit,$unit,'2',$owner,$owner], array_values($this->rows('SELECT เลขที่สำนวน,หน่วยงานต้นเรื่อง,unit_level1,submitted_by_id,submitted_by_name,เจ้าหน้าที่รับผิดชอบ FROM cases WHERE id=2')[0]));
        self::assertSame([$unit,$unit,'group','detail','2024-02-29','อื่นๆ','custom'], array_values($this->rows('SELECT responsible_unit,unit_level1,unit_level2,preliminary_allegation,received_date,case_type,case_type_other FROM uat_case_meta WHERE case_id=2')[0]));
        self::assertSame([['bma_personal_id'=>'2','owner_name'=>$owner]], $this->rows('SELECT bma_personal_id,owner_name FROM uat_case_owners WHERE case_id=2'));
        $update = ['action'=>'save_page1','case_id'=>'2','case_number'=>' B ','source_type'=>'S','source_other'=>'O'];
        self::assertSame('/case.php?id=2&page=1&saved=1', $this->post($update, 302)->headers->get('Location'));
        self::assertSame(['B','S','O','อื่นๆ','custom'], array_values($this->rows('SELECT เลขที่สำนวน,source_type,source_other,case_type,case_type_other FROM cases JOIN uat_case_meta ON case_id=id WHERE id=2')[0]));
        self::assertSame([[2,'2',$owner,'create_case'],[2,'2',$owner,'save_case_info']], array_map('array_values', $this->rows('SELECT case_id,actor_id,actor_name,action FROM uat_audit_log ORDER BY id')));
        $this->db->exec("UPDATE uat_case_owners SET ended_at='2024-02-29' WHERE case_id=2");
        $state = $this->state();
        $this->post($update + ['received_date'=>'bad'], 403);
        self::assertSame($state, $this->state());
    }

    public function testValidationSecurityAndAuditRollback(): void
    {
        $this->login('demo-admin');
        $state = $this->state();
        $create = ['action'=>'create_case','case_number'=>'N','responsible_unit'=>'admin unit'];
        $update = ['action'=>'save_page1','case_id'=>1];
        self::assertSame('กรุณาระบุเลขที่สำนวน', $this->post(['case_number'=>' '] + $create, 200)->getContent());
        self::assertSame('วันที่ไม่ถูกต้อง', $this->post($create + ['received_date'=>'2025-02-29'], 422)->getContent());
        self::assertSame('ประเภทสำนวนไม่ถูกต้อง', $this->post($update + ['case_type'=>'unknown'], 422)->getContent());
        $this->post($create + ['csrf'=>'bad'], 419);
        $this->post(['action'=>'unknown','case_id'=>1], 400);
        $this->post(['case_id'=>500] + $update, 404);
        $this->post(['action'=>'save_page1'], 404);
        $this->failAudit();
        $this->post($create, 500);
        $this->post($update, 500);
        self::assertSame($state, $this->state());
        $this->login('demo-reader');
        $this->post(['case_number'=>''] + $create, 403);
        $this->post($update + ['received_date'=>'bad'], 403);
        self::assertSame($state, $this->state());
    }

    public function testAuthenticationPrecedesProtectedWriteCsrfAndGetMethodCheck(): void
    {
        foreach (['/save_case.php','/roles.php'] as $url) {
            $this->client->request('POST', $url);
            self::assertSame(302, $this->client->getResponse()->getStatusCode());
            self::assertSame('/login.php', $this->client->getResponse()->headers->get('Location'));
        }
        $this->client->request('GET', '/save_case.php');
        self::assertSame('/login.php', $this->client->getResponse()->headers->get('Location'));
        $this->login('demo-owner');
        $this->client->request('GET', '/save_case.php');
        self::assertSame(405, $this->client->getResponse()->getStatusCode());
        self::assertSame('POST', $this->client->getResponse()->headers->get('Allow'));
    }

    public function testCreateFormAndRoleFormSubmitAtIisMount(): void
    {
        $mount = '/exam/disc2';
        $this->client->setServerParameter('SCRIPT_FILENAME', 'C:/inetpub/wwwroot/exam/disc2/public/index.php');
        $this->client->setServerParameter('SCRIPT_NAME', $mount.'/index.php');
        $accounts = json_decode(file_get_contents($this->directory.'/legacy/demo-accounts.json'), true, flags: JSON_THROW_ON_ERROR);
        $login = $this->client->request('GET', $mount.'/login.php');
        $this->client->submit($login->filter('form')->form(['username'=>'demo-admin', 'password'=>$accounts['demo-admin']]));
        $index = $this->client->request('GET', $mount.'/index.php');
        $this->client->submit($index->filter('#new-case-form')->form(['case_number'=>'MOUNTED','responsible_unit'=>'Unit']));
        self::assertSame($mount.'/case.php?id=2&page=1&saved=1', $this->client->getResponse()->headers->get('Location'));
        $roles = $this->client->request('GET', $mount.'/roles');
        $this->client->submit($roles->filter('form.grid')->form(['personal_id'=>'mount','access_role'=>'group_head']));
        self::assertSame($mount.'/roles.php?saved=1', $this->client->getResponse()->headers->get('Location'));
    }

    public function testStoredLegacyTypeAndAdminUnit(): void
    {
        $this->login('demo-admin');
        $this->db->exec("UPDATE uat_case_meta SET case_type='legacy'");
        $this->post(['action'=>'save_page1','case_id'=>1,'case_type'=>'legacy','case_type_other'=>'ignore','responsible_unit'=>'admin unit'], 302);
        self::assertSame(['legacy','','admin unit'], array_values($this->rows('SELECT case_type,case_type_other,responsible_unit FROM uat_case_meta')[0]));
    }
}
