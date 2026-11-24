<?php

declare(strict_types=1);

namespace App\Tests\Functional\Write;

final class CommitteeWriteTest extends WriteTestCase
{
    private function membershipState(): array
    {
        $state = $this->state();
        foreach (['uat_tracks','uat_track_subjects','uat_subject_findings','uat_committee_orders','uat_committee_sets',
            'uat_committee_set_subjects','uat_escalations'] as $table) {
            $state[$table] = $this->rows("SELECT * FROM $table ORDER BY rowid");
        }
        return $state;
    }

    private function createFactFindings(string $finding): void
    {
        $this->post(['action'=>'save_fact','case_id'=>1,'finding'=>[1=>$finding,2=>$finding]], 302);
    }

    public function testCommitteeCannotReopenClosedCaseWithoutGrounds(): void
    {
        $this->login('demo-owner');
        $this->createFactFindings('no_ground');
        self::assertSame('closed', $this->rows('SELECT stage FROM uat_case_meta WHERE case_id=1')[0]['stage']);
        $before = $this->membershipState();
        foreach (['serious', 'nonserious'] as $type) {
            $this->post(['case_id'=>1,'action'=>'committee','track_type'=>$type,'set_name'=>'Unauthorized round',
                'members'=>[1]], 422, '/save_comment2.php');
            self::assertSame($before, $this->membershipState());
        }
    }

    public function testExistingRoundMemberCanJoinCommitteeWithoutNewGrounds(): void
    {
        $this->login('demo-owner');
        $this->db->exec("INSERT INTO uat_tracks(case_id,track_type,duration_days) VALUES(1,'discipline_nonserious',60);
            INSERT INTO uat_track_subjects(track_id,subject_id) VALUES(1,1)");
        $this->post(['case_id'=>1,'action'=>'committee','track_type'=>'nonserious','set_name'=>'Recorded membership',
            'members'=>[1]], 302, '/save_comment2.php');
        self::assertCount(1, $this->rows('SELECT * FROM uat_track_subjects'));
        self::assertCount(1, $this->rows('SELECT * FROM uat_committee_set_subjects'));
    }

    public function testCommitteeCannotReopenEndedTrackMembership(): void
    {
        $this->login('demo-owner');
        $this->db->exec("INSERT INTO uat_tracks(case_id,track_type,duration_days) VALUES(1,'discipline_nonserious',60);
            INSERT INTO uat_track_subjects(track_id,subject_id,ended_at) VALUES(1,1,'2024-05-01')");
        $this->createFactFindings('ground');
        $before = $this->membershipState();
        $this->post(['case_id'=>1,'action'=>'committee','track_type'=>'nonserious','set_name'=>'Second','members'=>[1]], 409, '/save_comment2.php');
        self::assertSame($before, $this->membershipState());
    }

    public function testTransferJoinsTheSelectedNamedAppointment(): void
    {
        $this->login('demo-owner');
        $this->db->exec("INSERT INTO uat_tracks(case_id,track_type,duration_days) VALUES(1,'discipline_nonserious',60);
            INSERT INTO uat_track_subjects(track_id,subject_id) VALUES(1,1);
            INSERT INTO uat_subject_findings(track_id,subject_id,recommend_serious,committee_opinion) VALUES(1,1,1,'Opinion')");
        $this->createFactFindings('ground');
        $this->post(['case_id'=>1,'action'=>'committee','track_type'=>'serious','set_name'=>'Named order',
            'order_no'=>'Serious-8','appointed_date'=>'2024-03-01','members'=>[2]], 302, '/save_comment2.php');
        $url = '/discipline_results.php?case_id=1&type=discipline_nonserious&track_id=1';
        $display = fn () => $this->client->request('GET', $url)->filterXPath('//form[input[@value="escalate_discipline"]]')->form()->getPhpValues();
        $values = ['subject_id'=>1,'track_id'=>1,'target_track'=>3,'effective_date'=>'2024-04-01','reference'=>'Transfer'];
        $first = $values + $display();
        $this->post(['case_id'=>1,'action'=>'add_order','track_id'=>3,'event_type'=>'appoint','order_number'=>'Replacement'], 302);
        $before = $this->membershipState();
        $this->post($first, 409);
        self::assertSame($before, $this->membershipState());
        // Restore the original named order as a newly issued appointment.
        $this->post(['case_id'=>1,'action'=>'add_order','track_id'=>3,'event_type'=>'appoint','order_number'=>'Serious-8','order_date'=>'2024-03-01'], 302);
        $fresh = $values + $display();
        $this->post($first, 409);
        $this->post(['target_revision'=>[]] + $fresh, 409);
        $this->db->exec('UPDATE uat_committee_sets SET active=0 WHERE id=1');
        $before = $this->membershipState();
        $this->post($fresh, 409);
        self::assertSame($before, $this->membershipState());
        $this->db->exec('UPDATE uat_committee_sets SET active=1 WHERE id=1');
        $this->post($fresh, 302);
        $this->post(['case_id'=>1,'action'=>'add_order','track_id'=>3,'event_type'=>'appoint','order_number'=>'Later'], 302);
        $before = $this->membershipState();
        $redirect = $this->post($fresh, 302)->headers->get('Location');
        self::assertSame('/track.php?case_id=1&type=discipline_serious&track_id=3&saved=1', $redirect);
        self::assertSame($before, $this->membershipState());
        self::assertSame([1,2], array_column($this->rows('SELECT subject_id FROM uat_committee_set_subjects WHERE committee_set_id=1 AND ended_at IS NULL ORDER BY subject_id'), 'subject_id'));
        self::assertCount(1, $this->rows("SELECT * FROM uat_audit_log WHERE action='escalate_discipline'"));
    }

    public function testOnlyRecordedAppointmentsCanReceiveAnEscalation(): void
    {
        $this->login('demo-owner');
        $this->db->exec("INSERT INTO uat_tracks(id,case_id,track_type) VALUES(1,1,'discipline_nonserious'),(2,1,'discipline_serious');
            INSERT INTO uat_track_subjects(track_id,subject_id) VALUES(1,1);
            INSERT INTO uat_subject_findings(track_id,subject_id,recommend_serious,committee_opinion) VALUES(1,1,1,'Opinion');
            INSERT INTO uat_committee_orders(track_id,event_type,order_number) VALUES(2,'appoint','Original')");
        $url = '/discipline_results.php?case_id=1&type=discipline_nonserious&track_id=1';
        $form = $this->client->request('GET', $url)->filterXPath('//form[input[@value="escalate_discipline"]]')->form()->getPhpValues();
        $this->db->exec("DELETE FROM uat_committee_orders WHERE track_id=2;
            INSERT INTO uat_committee_orders(track_id,event_type,order_number) VALUES(2,'amend_order','Amendment only')");
        $page = $this->client->request('GET', $url);
        // Even a revision obtained when no order exists cannot authorize the transfer.
        $revision = $page->filter('input[name="target_revision[2]"]');
        $values = ['subject_id'=>1,'track_id'=>1,'target_track'=>2,'effective_date'=>'2024-04-01','reference'=>'Transfer'];
        $before = $this->membershipState();
        $presented = $revision->count() ? $revision->attr('value') : $form['target_revision'][2];
        $this->post(['target_revision'=>[2=>$presented]] + $values + $form, 409);
        self::assertSame($before, $this->membershipState());
        self::assertCount(0, $page->filter('select[name=target_track] option[value="2"]'));
        self::assertCount(0, $revision);
    }

    public function testAppointmentSetAndMembersUseCurrentSchemaAndRollBackTogether(): void
    {
        $this->login('demo-owner');
        $this->createFactFindings('ground');
        $before = $this->membershipState();
        $committee = ['case_id'=>1, 'action'=>'committee','track_type'=>'nonserious','set_name'=>'First',
            'order_no'=>'ORDER-1','appointed_date'=>'2024-03-01','members'=>[1,2]];
        $url = '/save_comment2.php';
        $this->post(['members'=>[1,99]] + $committee, 404, $url);
        $this->failAudit();
        $this->post($committee, 500, $url);
        self::assertSame($before, $this->membershipState());
        $this->db->exec('DROP TRIGGER fail_audit');
        self::assertSame('/case.php?id=1&page=3&saved=1', $this->post($committee, 302, $url)->headers->get('Location'));
        self::assertSame(['First','ORDER-1','2024-03-01'], array_values($this->rows('SELECT set_code,order_number,order_date FROM uat_committee_sets')[0]));
        self::assertSame([1,2], array_column($this->rows('SELECT subject_id FROM uat_committee_set_subjects ORDER BY subject_id'), 'subject_id'));
        self::assertSame([1,2], array_column($this->rows('SELECT subject_id FROM uat_track_subjects WHERE track_id=2 ORDER BY subject_id'), 'subject_id'));
        self::assertSame(['appoint','ORDER-1'], array_values($this->rows('SELECT event_type,order_number FROM uat_committee_orders')[0]));
        $this->post(['set_name'=>'Second'] + $committee, 409, $url);
        self::assertSame(['save_fact_deadline','save_fact_finding','add_committee'], array_column($this->rows('SELECT action FROM uat_audit_log'), 'action'));
    }
}
