<?php

declare(strict_types=1);

namespace App\Tests\Functional\Write;

final class DisciplineWriteTest extends WriteTestCase
{
    public function testCurrentRoundValidationNewAppointmentRetryAndSeriousDecision(): void
    {
        $this->login('demo-owner');
        $person = ['case_id'=>1,'subject_id'=>1,'track_id'=>1];
        $this->db->exec("INSERT INTO uat_tracks(case_id,track_type,duration_days) VALUES(1,'discipline_nonserious',60)");
        $this->db->exec('INSERT INTO uat_track_subjects(track_id,subject_id) VALUES(1,1),(1,2)');
        $transfer = ['action'=>'escalate_discipline','effective_date'=>'2024-03-02','reference'=>'ORD-8','target_track'=>'new'] + $person;
        $results = '/save_discipline.php';
        $this->post($transfer, 422);
        $this->post($person + ['recommend_serious'=>1], 422, $results);
        $this->post($person + ['finding'=>'unknown'], 422, $results);
        $this->post(['subject_id'=>88] + $person, 409, $results);
        self::assertCount(1, $this->rows('SELECT * FROM uat_tracks'));
        self::assertSame([], $this->rows('SELECT * FROM uat_audit_log'));
        $this->post($person + ['finding'=>'guilty','recommend_serious'=>1,'committee_opinion'=>'Confirmed'], 302, $results);
        $this->failAudit();
        $this->post($transfer, 500);
        self::assertCount(1, $this->rows('SELECT * FROM uat_tracks'));
        self::assertSame([], $this->rows('SELECT * FROM uat_committee_orders'));
        self::assertSame([], $this->rows('SELECT * FROM uat_escalations'));
        self::assertNull($this->rows('SELECT ended_at FROM uat_track_subjects WHERE subject_id=1')[0]['ended_at']);
        $this->db->exec('DROP TRIGGER fail_audit');
        $this->post($transfer, 302);
        $this->post($transfer, 302);
        self::assertCount(1, $this->rows('SELECT * FROM uat_escalations'));
        self::assertSame(['appoint','ORD-8'], array_values($this->rows('SELECT event_type,order_number FROM uat_committee_orders')[0]));
        $decision = ['action'=>'save_decision','order_number'=>'Final-1'] + $person;
        $this->post($decision, 409);
        $this->post(['track_id'=>2] + $decision, 422);
        $this->post(['track_id'=>2,'kk_resolution'=>'Approved','kk_resolution_date'=>'2024-04-01'] + $decision, 302);
        self::assertSame([1,2,'serious','Final-1'], array_values($this->rows('SELECT subject_id,track_id,route_type,order_number FROM uat_decisions')[0]));
    }

    public function testForeignTrackAndPersonCannotBeUsedThroughAnOwnedCase(): void
    {
        $this->login('demo-owner');
        $this->db->exec("INSERT INTO cases(id,เลขที่สำนวน) VALUES(2,'FOREIGN');
            INSERT INTO uat_subjects(id,case_id,sequence_no) VALUES(3,2,1);
            INSERT INTO uat_tracks(id,case_id,track_type,duration_days) VALUES(1,1,'discipline_nonserious',60),(2,2,'discipline_serious',120);
            INSERT INTO uat_track_subjects(track_id,subject_id) VALUES(1,1),(2,3);
            INSERT INTO uat_subject_findings(track_id,subject_id,recommend_serious,committee_opinion) VALUES(1,1,1,'Order')");
        $foreign = ['case_id'=>1,'subject_id'=>3,'track_id'=>2];
        $this->post($foreign, 409, '/save_discipline.php');
        $this->post(['action'=>'save_decision'] + $foreign, 409);
        $this->post(['action'=>'add_order','event_type'=>'appoint'] + $foreign, 404);
        $this->post(['action'=>'add_extension','requested_days'=>1] + $foreign, 404);
        $this->post(['action'=>'save_track','track_type'=>'discipline_serious'] + $foreign, 404);
        $this->post(['action'=>'escalate_discipline','case_id'=>1,'subject_id'=>1,'track_id'=>1,'target_track'=>2,
            'effective_date'=>'2024-03-01','reference'=>'foreign'], 404);
        $this->post(['case_id'=>1,'action'=>'save_fact','finding'=>[3=>'ground']], 404);
        self::assertSame([], $this->rows('SELECT * FROM uat_audit_log'));
        self::assertSame([], $this->rows('SELECT * FROM uat_escalations'));
        self::assertNull($this->rows('SELECT ended_at FROM uat_track_subjects WHERE subject_id=1')[0]['ended_at']);
    }

    public function testAuthorizationPrecedesSubjectAndTrackLookups(): void
    {
        $this->login('demo-reader');
        $fields = ['case_id'=>1,'track_id'=>42,'subject_id'=>99,'effective_date'=>'bad','members'=>['bad']];
        foreach (['save_fact','save_track','convert_serious_deadline','add_extension','add_order','assign_discipline','escalate_discipline','save_decision'] as $action) {
            $response = $this->post(['action'=>$action] + $fields, 403);
            self::assertSame('ไม่มีสิทธิ์แก้ไข', $response->getContent());
        }
        $response = $this->post($fields, 403, '/save_discipline.php');
        self::assertSame('ไม่มีสิทธิ์แก้ไข', $response->getContent());
        $this->post(['action'=>'committee'] + $fields, 403, '/save_comment2.php');
        $this->post($fields + ['csrf'=>'bad'], 419, '/save_discipline.php');
        self::assertSame([], $this->rows('SELECT * FROM uat_audit_log'));
    }
}
