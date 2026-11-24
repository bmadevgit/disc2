<?php

declare(strict_types=1);

namespace App\Tests\Functional\Write;

final class CommitteeWriteTest extends WriteTestCase
{
    public function testCommitteeCannotReopenEndedTrackMembership(): void
    {
        $this->login('demo-owner');
        $this->db->exec("INSERT INTO uat_tracks(case_id,track_type,duration_days) VALUES(1,'discipline_nonserious',60);
            INSERT INTO uat_track_subjects(track_id,subject_id,ended_at) VALUES(1,1,'2024-05-01')");
        $this->post(['case_id'=>1,'action'=>'committee','track_type'=>'nonserious','set_name'=>'Second','members'=>[1]], 409, '/save_comment2.php');
        self::assertSame([], $this->rows('SELECT * FROM uat_committee_sets'));
        self::assertSame([], $this->rows('SELECT * FROM uat_audit_log'));
    }

    public function testTransferJoinsTheSelectedNamedAppointment(): void
    {
        $this->login('demo-owner');
        $this->db->exec("INSERT INTO uat_tracks(case_id,track_type,duration_days) VALUES(1,'discipline_nonserious',60);
            INSERT INTO uat_track_subjects(track_id,subject_id) VALUES(1,1);
            INSERT INTO uat_subject_findings(track_id,subject_id,recommend_serious,committee_opinion) VALUES(1,1,1,'Opinion')");
        $this->post(['case_id'=>1,'action'=>'committee','track_type'=>'serious','set_name'=>'Named order',
            'order_no'=>'Serious-8','appointed_date'=>'2024-03-01','members'=>[2]], 302, '/save_comment2.php');
        $transfer = ['case_id'=>1,'action'=>'escalate_discipline','subject_id'=>1,'track_id'=>1,'target_track'=>2,
            'effective_date'=>'2024-04-01','reference'=>'Transfer'];
        $this->post($transfer, 302);
        $this->post($transfer, 302);
        self::assertSame([1,2], array_column($this->rows('SELECT subject_id FROM uat_committee_set_subjects WHERE committee_set_id=1 AND ended_at IS NULL ORDER BY subject_id'), 'subject_id'));
        self::assertCount(1, $this->rows("SELECT * FROM uat_audit_log WHERE action='escalate_discipline'"));
    }

    public function testAppointmentSetAndMembersUseCurrentSchemaAndRollBackTogether(): void
    {
        $this->login('demo-owner');
        $committee = ['case_id'=>1, 'action'=>'committee','track_type'=>'nonserious','set_name'=>'First',
            'order_no'=>'ORDER-1','appointed_date'=>'2024-03-01','members'=>[1,2]];
        $url = '/save_comment2.php';
        $this->post(['members'=>[1,99]] + $committee, 404, $url);
        $this->failAudit();
        $this->post($committee, 500, $url);
        foreach (['uat_tracks','uat_track_subjects','uat_committee_orders','uat_committee_sets','uat_committee_set_subjects','uat_audit_log'] as $table) {
            self::assertSame([], $this->rows("SELECT * FROM $table"));
        }
        $this->db->exec('DROP TRIGGER fail_audit');
        self::assertSame('/case.php?id=1&page=3&saved=1', $this->post($committee, 302, $url)->headers->get('Location'));
        self::assertSame(['First','ORDER-1','2024-03-01'], array_values($this->rows('SELECT set_code,order_number,order_date FROM uat_committee_sets')[0]));
        self::assertSame([1,2], array_column($this->rows('SELECT subject_id FROM uat_committee_set_subjects ORDER BY subject_id'), 'subject_id'));
        self::assertSame([1,2], array_column($this->rows('SELECT subject_id FROM uat_track_subjects ORDER BY subject_id'), 'subject_id'));
        self::assertSame(['appoint','ORDER-1'], array_values($this->rows('SELECT event_type,order_number FROM uat_committee_orders')[0]));
        $this->post(['set_name'=>'Second'] + $committee, 409, $url);
        self::assertSame(['add_committee'], array_column($this->rows('SELECT action FROM uat_audit_log'), 'action'));
    }
}
