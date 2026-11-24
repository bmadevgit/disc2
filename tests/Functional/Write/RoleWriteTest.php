<?php

declare(strict_types=1);

namespace App\Tests\Functional\Write;

final class RoleWriteTest extends WriteTestCase
{
    public function testAdminUpsertsAndDeactivatesWithScopedAudit(): void
    {
        $this->login('demo-admin');
        $fields = ['personal_id'=>' synthetic ','display_name'=>'Role user','access_role'=>'unit_head','unit_level1'=>' A ','unit_level2'=>'B'];
        $target = '/roles.php?saved=1';
        self::assertSame($target, $this->post($fields + ['active'=>'on'], 302, '/roles.php')->headers->get('Location'));
        self::assertSame(['Role user','unit_head','A','B',1], array_values($this->rows("SELECT display_name,access_role,unit_level1,unit_level2,active FROM uat_access_assignments WHERE bma_personal_id='synthetic'")[0]));
        self::assertSame($target, $this->post(['access_role'=>'executive'] + $fields, 302, '/roles.php')->headers->get('Location'));
        self::assertSame([['access_role'=>'executive','active'=>0]], $this->rows("SELECT access_role,active FROM uat_access_assignments WHERE bma_personal_id='synthetic'"));
        $details = array_map(static fn (array $row) => json_decode($row['detail'], true, flags: JSON_THROW_ON_ERROR),
            $this->rows('SELECT detail FROM uat_audit_log ORDER BY id'));
        self::assertSame([
            ['personal_id'=>'synthetic', 'role'=>'unit_head', 'active'=>true],
            ['personal_id'=>'synthetic', 'role'=>'executive', 'active'=>false],
        ], $details);
        self::assertSame([[null,'1','assign_role'],[null,'1','assign_role']], array_map('array_values', $this->rows('SELECT case_id,actor_id,action FROM uat_audit_log ORDER BY id')));
    }

    public function testInvalidForbiddenExpiredAndFailedAuditKeepAssignment(): void
    {
        $fields = ['personal_id'=>'synthetic','access_role'=>'unit_head'];
        $this->login('demo-admin');
        $state = $this->state();
        foreach ([['access_role'=>'admin'],['access_role'=>' unit_head '],['personal_id'=>' ']] as $invalid) {
            self::assertSame('/roles.php?saved=1', $this->post($invalid + $fields, 302, '/roles.php')->headers->get('Location'));
        }
        $this->post($fields + ['csrf'=>'expired'], 419, '/roles.php');
        $this->failAudit();
        $this->post($fields, 500, '/roles.php');
        $this->login('demo-owner');
        $this->post($fields, 403, '/roles.php');
        self::assertSame($state, $this->state());
    }
}
