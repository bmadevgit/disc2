<?php

declare(strict_types=1);

namespace App\Tests\Functional\Write;

use Browser;
use PDO;

final class CaseRollbackCompatibilityTest extends WriteTestCase
{
    public function testProceduralReleaseReadsAllNewRecordsOnTheSameDatabase(): void
    {
        $this->login('demo-owner');
        $this->post(['action'=>'create_case','case_number'=>'INITIAL','unit_level2'=>'RollbackGroup'], 302);
        $this->post(['action'=>'save_page1','case_id'=>2,'case_number'=>'ROLLBACK-CASE','unit_level2'=>'RollbackGroup',
            'received_date'=>'2024-02-29','case_type'=>'อื่นๆ','case_type_other'=>'LegacyTypeDetail',
            'source_other'=>'LegacySource','preliminary_allegation'=>'LegacyAllegation',
            'subjects'=>[['first'=>'SyntheticPerson','last'=>'Surname','employment'=>'ลูกจ้าง','national_id'=>'0000000000000',
                'status'=>'ลาออก','status_date'=>'2024-03-01','status_reason'=>'LegacyStatusReason']]], 302);
        $this->login('demo-admin');
        $this->post(['personal_id'=>'3','display_name'=>'LegacyReader','access_role'=>'group_head','active'=>'on',
            'unit_level1'=>'สำนักงานตัวอย่าง','unit_level2'=>'RollbackGroup'], 302, '/roles.php');

        require_once $this->root.'/tests/Legacy/run.php';
        $passwords = json_decode(file_get_contents($this->directory.'/legacy/demo-accounts.json'), true, flags: JSON_THROW_ON_ERROR);
        withLegacyServer($this->root, $this->directory, function (string $base) use ($passwords) {
            $owner = new Browser($base);
            $owner->login('demo-owner', $passwords['demo-owner']);
            self::assertStringContainsString('ROLLBACK-CASE', $owner->request('/index.php', 200));
            $ownerPage = $owner->request('/case.php?id=2&page=1', 200);
            self::assertStringContainsString('value="save_page1"', $ownerPage);

            $admin = new Browser($base);
            $admin->login('demo-admin', $passwords['demo-admin']);
            $case = $admin->request('/case.php?id=2&page=1', 200);
            foreach (['ROLLBACK-CASE','RollbackGroup','LegacySource','LegacyAllegation','LegacyTypeDetail','2024-02-29',
                'SyntheticPerson','Surname','0000000000000','ลูกจ้าง','ลาออก'] as $value) {
                self::assertStringContainsString($value, $case);
            }
            $subject = $admin->request('/subject.php?case_id=2&subject_id=3', 200);
            self::assertStringContainsString('2024-03-01 ลาออก LegacyStatusReason', $subject);
            self::assertStringContainsString('LegacyReader', $admin->request('/roles.php', 200));
            $reader = new Browser($base);
            $reader->login('demo-reader', $passwords['demo-reader']);
            self::assertStringContainsString('REF-2-1', $reader->request('/case.php?id=2&page=1', 200));
            $reader->request('/case.php?id=1&page=1', 403);
        });
        $reopened = new PDO('sqlite:'.$this->path);
        self::assertSame(['create_case','save_case_info','assign_role'], $reopened->query('SELECT action FROM uat_audit_log ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
        self::assertSame('ok', $reopened->query('PRAGMA integrity_check')->fetchColumn());
        self::assertSame([], $reopened->query('PRAGMA foreign_key_check')->fetchAll());
    }
}
