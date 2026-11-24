<?php

declare(strict_types=1);

namespace App\Tests\Functional\Write;

final class SubjectWriteTest extends WriteTestCase
{
    private function save(array $subjects, int $status = 302): void
    {
        $this->post(['action'=>'save_page1','case_id'=>1,'case_number'=>'UPDATED','subjects'=>$subjects], $status);
    }

    public function testMaskedAndForgedPrivateFieldsPreserveIdentityWhileStatusAndEmploymentChange(): void
    {
        $this->login('demo-owner');
        $this->db->exec("UPDATE uat_subjects SET title_th='title',last_name_th='last',position_name='position',national_id='0000000000000' WHERE id=1");
        $sql = 'SELECT title_th,first_name_th,last_name_th,position_name,national_id FROM uat_subjects WHERE id=1';
        $identity = $this->rows($sql);
        $subject = ['id'=>'1','employment'=>'employee','status'=>'active','status_reason'=>'status reason','status_date'=>'2024-02-29'];
        $this->save([$subject]);
        $this->save([$subject + ['first'=>'overwrite','last'=>'forged','position'=>'forged','title'=>'forged','national_id'=>'malformed']]);
        self::assertSame($identity, $this->rows($sql));
        self::assertSame([['employment_type'=>'employee']], $this->rows('SELECT employment_type FROM uat_subjects WHERE id=1'));
        self::assertSame([['subject_id'=>1,'status_name'=>'active','effective_date'=>'2024-02-29','reason'=>'status reason']], $this->rows('SELECT subject_id,status_name,effective_date,reason FROM uat_subject_status_history'));
        self::assertCount(2, $this->rows("SELECT id FROM uat_audit_log WHERE action='save_case_info'"));
    }

    public function testAddThenAdminEditAndPreserveBlankNationalId(): void
    {
        $this->login('demo-owner');
        $this->save([2=>['first'=>'New','last'=>'Person','national_id'=>'000-0000-000000','status'=>'new'],3=>['first'=>' ']]);
        $this->login('demo-admin');
        $this->save([['id'=>3,'first'=>' Changed ','last'=>'Family','national_id'=>'','status'=>'other']]);
        self::assertSame([3,'REF-1-3','Changed','Family','0000000000000'], array_values($this->rows('SELECT sequence_no,reference_code,first_name_th,last_name_th,national_id FROM uat_subjects WHERE id=3')[0]));
        self::assertSame(['new','other'], array_column($this->rows('SELECT status_name FROM uat_subject_status_history WHERE subject_id=3 ORDER BY id'),'status_name'));
    }

    public function testNewSubjectUsesStoredSequenceAndStaleResubmissionIsAtomic(): void
    {
        $this->login('demo-owner');
        $this->db->exec("UPDATE uat_subjects SET sequence_no=7,active=0 WHERE id=2");
        $page = $this->client->request('GET', '/case.php?id=1&page=1');
        self::assertSame('7', $page->filter('#subjects')->attr('data-next-index'));
        $form = [0=>['id'=>1],7=>['first'=>'New','status'=>'active']];
        $this->save($form);
        $state = $this->state();
        $this->save($form, 409);
        self::assertSame($state, $this->state());
        $this->save([PHP_INT_MAX - 1=>['first'=>'invalid']], 422);
        self::assertSame($state, $this->state());
    }

    public function testForeignAndInvalidSubjectCannotChangeCaseOrCreateAnotherPerson(): void
    {
        $this->login('demo-owner');
        $this->db->exec("INSERT INTO cases(id) VALUES(2); INSERT INTO uat_subjects(id,case_id,sequence_no) VALUES(3,2,1)");
        $state = $this->state();
        foreach ([['id'=>3,'first'=>'forged'],['id'=>3],['id'=>99,'first'=>'forged']] as $bad) {
            $this->save([2=>['first'=>'Earlier'],3=>$bad], 404);
            self::assertSame($state, $this->state());
        }
        $this->save([2=>['first'=>'New','national_id'=>'123']], 422);
        $this->save([['id'=>'oops','first'=>'forged']], 422);
        $this->save([['id'=>1,'status'=>'change','status_date'=>'bad']], 422);
        self::assertSame($state, $this->state());
    }
}
