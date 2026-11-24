<?php

declare(strict_types=1);

namespace App\Tests\Functional\Write;

use Browser;
use PDO;

final class WorkflowWriteTest extends WriteTestCase
{
    public function testCharacterizedWorkflow(): void
    {
        $this->writeCharacterizedWorkflow();
        self::assertSame('ok', $this->db->query('PRAGMA integrity_check')->fetchColumn());
        self::assertSame([], $this->rows('PRAGMA foreign_key_check'));
    }

    private function writeCharacterizedWorkflow(): array
    {
        $this->login('demo-owner');
        require_once $this->root.'/tests/Legacy/run.php';
        $fixtures = require $this->root.'/tests/Fixtures/legacy-workflows.php';
        $request = function (string $url, int $status, ?array $fields = null, ?string $location = null): string {
            $this->client->request($fields === null ? 'GET' : 'POST', $url, $fields ?? []);
            $response = $this->client->getResponse();
            self::assertSame($status, $response->getStatusCode(), $url.' '.($fields['action'] ?? ''));
            if ($location !== null) {
                self::assertSame($location, $response->headers->get('Location'));
            }
            return $response->getContent();
        };
        checkWorkflows($request, $this->db, $fixtures);
        return $fixtures;
    }

    public function testProceduralRollbackOnSameDatabase(): void
    {
        $legacyRoot = $this->legacyRoot();
        $fixtures = $this->writeCharacterizedWorkflow();
        $before = $this->rows('SELECT * FROM uat_audit_log ORDER BY id');
        $passwords = json_decode(file_get_contents($this->directory.'/legacy/demo-accounts.json'), true, flags: JSON_THROW_ON_ERROR);
        withLegacyServer($legacyRoot, $this->directory, function (string $base) use ($passwords, $fixtures) {
            $browser = new Browser($base);
            $browser->login('demo-owner', $passwords['demo-owner']);
            $history = $browser->request('/subject.php?case_id=1&subject_id=1', 200);
            foreach (['original_order','reference','old_decision','old_report','extended_due'] as $key) {
                self::assertStringContainsString($fixtures['round'][$key], $history);
            }
            self::assertStringContainsString($fixtures['round']['old_results'][1]['committee_opinion'], $history);
            $serious = trackId($this->db, 'discipline_serious', 2);
            $results = $browser->request(trackPath('discipline_serious', $serious, 'discipline_results'), 200);
            self::assertStringContainsString($fixtures['round']['new_result']['committee_opinion'], $results);
            $converted = $browser->request(trackPath('discipline_serious', trackId($this->db, 'discipline_serious')), 200);
            self::assertStringContainsString('2024-06-14', $converted);
        });
        self::assertSame($before, $this->rows('SELECT * FROM uat_audit_log ORDER BY id'));
        self::assertSame('ok', $this->db->query('PRAGMA integrity_check')->fetchColumn());
        self::assertSame([], $this->rows('PRAGMA foreign_key_check'));
    }

    public function testForgedPreviewsManualOverridesDraftsAndStageChanges(): void
    {
        $this->login('demo-owner');
        $fact = ['action'=>'save_fact','case_id'=>1, 'finding'=>[1=>'no_ground',2=>'no_ground']];
        $this->post($fact + ['acknowledged_date'=>'2024-01-01','start_date'=>'forged','preview_due'=>'forged'], 302);
        self::assertSame(['',30,''], array_values($this->rows('SELECT start_date,duration_days,due_date FROM uat_tracks')[0]));
        self::assertSame('closed', $this->rows('SELECT stage FROM uat_case_meta')[0]['stage']);
        $manual = ['start_mode'=>'manual','start_date'=>'2024-01-05'];
        $this->post($fact + $manual, 422);
        $this->post($fact + $manual + ['start_override_reason'=>'Order A'], 302);
        self::assertSame(['2024-01-05','2024-02-03'], array_values($this->rows('SELECT start_date,due_date FROM uat_tracks')[0]));
        $this->db->exec("INSERT INTO uat_tracks(case_id,track_type,duration_days) VALUES(1,'discipline_nonserious',60)");
        $track = ['action'=>'save_track','track_type'=>'discipline_nonserious','case_id'=>1,'track_id'=>(int)$this->db->lastInsertId()];
        $this->post($track + ['start_mode'=>'auto','start_date'=>'bad','preview_due'=>'bad','due_date'=>'bad','duration_days'=>5,
            'acknowledged_date'=>'2024-02-01','first_meeting_date'=>'2024-02-01'], 302);
        self::assertSame(['2024-02-01',60,'2024-03-31'], array_values($this->rows("SELECT start_date,duration_days,due_date FROM uat_tracks WHERE id=".$track['track_id'])[0]));
        $this->post($track + ['first_meeting_date'=>'2024-02-31'], 422);
        $this->post(['action'=>'add_extension','case_id'=>1,'track_id'=>$track['track_id'],'requested_days'=>2], 302);
        $this->post($track, 422);
    }

    public function testUnscheduledConversionAndUnavailablePreview(): void
    {
        $this->login('demo-owner');
        $this->db->exec("INSERT INTO uat_tracks(case_id,track_type,set_no,duration_days,acknowledged_date)
            VALUES(1,'discipline_serious',1,180,'2024-01-01'),(1,'discipline_serious',2,180,'')");
        $confirm = ['action'=>'convert_serious_deadline','case_id'=>1,'track_type'=>'discipline_serious','reason'=>'Order'];
        $page = $this->client->request('GET','/track.php?case_id=1&type=discipline_serious&track_id=1');
        $this->post($confirm + ['track_id'=>1,'revision'=>$page->filter('input[name="revision"]')->attr('value')], 302);
        self::assertSame(['',120,''], array_values($this->rows('SELECT start_date,duration_days,due_date FROM uat_tracks WHERE id=1')[0]));
        $this->db->exec("INSERT INTO uat_extensions(track_id,sequence_no) VALUES(2,1)");
        $revision = hash('sha256', serialize([$this->rows('SELECT * FROM uat_tracks WHERE id=2')[0], $this->rows('SELECT * FROM uat_extensions')]));
        $this->post($confirm + ['track_id'=>2,'revision'=>$revision], 422);
        self::assertSame(180, $this->rows('SELECT duration_days FROM uat_tracks WHERE id=2')[0]['duration_days']);
        // A meeting alone cannot repair malformed published history through a forged confirmation.
        $this->db->exec("UPDATE uat_tracks SET first_meeting_date='2024-01-01',start_date='2024-01-01',due_date='invalid' WHERE id=2");
        $this->db->exec("UPDATE uat_extensions SET requested_days=2,new_due_date='2024-06-30' WHERE track_id=2");
        $revision = hash('sha256', serialize([$this->rows('SELECT * FROM uat_tracks WHERE id=2')[0], $this->rows('SELECT * FROM uat_extensions')]));
        $this->post($confirm + ['track_id'=>2,'revision'=>$revision], 422);
        self::assertCount(1, $this->rows('SELECT * FROM uat_audit_log'));
    }

    public function testAlternateFactReferralUsesOwnedSubjectAndAtomic45DaySchedule(): void
    {
        $this->login('demo-owner');
        $this->db->exec("INSERT INTO uat_tracks(case_id,track_type,duration_days) VALUES(1,'fact',30)");
        $form = ['action'=>'referral','case_id'=>1,'track_id'=>1,'subject_id'=>1,'ordered_date'=>'2024-02-01',
            'received_date'=>'2024-02-03','status'=>'reviewing','note'=>'Synthetic referral','due_date'=>'forged'];
        $url = '/save_comment2.php';
        $this->post(['subject_id'=>999] + $form, 422, $url);
        $this->failAudit();
        $this->post($form, 500, $url);
        self::assertSame([], $this->rows('SELECT * FROM uat_referrals'));
        $this->db->exec('DROP TRIGGER fail_audit');
        self::assertSame('/case.php?id=1&page=2&saved=1', $this->post($form, 302, $url)->headers->get('Location'));
        self::assertSame([1,1,'2024-02-01','2024-02-03','2024-03-16','reviewing','Synthetic referral'],
            array_values($this->rows('SELECT track_id,subject_id,ordered_date,received_date,due_date,status,note FROM uat_referrals')[0]));
        $this->post(['ordered_date'=>'','status'=>'other'] + $form, 302, $url);
        self::assertSame([['due_date'=>'','status'=>'pending']], $this->rows('SELECT due_date,status FROM uat_referrals'));
        self::assertCount(2, $this->rows("SELECT * FROM uat_audit_log WHERE action='save_referral'"));
    }

    public function testLockContentionRetryAndFailedAuditRollBackWholeRequest(): void
    {
        $this->login('demo-owner');
        $this->db->exec('PRAGMA busy_timeout=25');
        $lock = new PDO('sqlite:'.$this->path);
        $lock->exec('BEGIN IMMEDIATE');
        $fields = ['case_id'=>1,'action'=>'save_fact','finding'=>[1=>'ground',2=>'ground']];
        try {
            $response = $this->post($fields, 503);
            self::assertSame('1', $response->headers->get('Retry-After'));
            self::assertSame([], $this->rows('SELECT * FROM uat_tracks'));
            self::assertSame([], $this->rows('SELECT * FROM uat_audit_log'));
        } finally {
            $lock->exec('ROLLBACK');
        }
        $this->db->exec("CREATE TRIGGER fail_fact BEFORE INSERT ON uat_audit_log WHEN NEW.action='save_fact_finding'
            BEGIN SELECT RAISE(ABORT,'synthetic final audit failure'); END");
        $this->post($fields, 500);
        foreach (['uat_tracks','uat_track_subjects','uat_subject_findings','uat_audit_log'] as $table) {
            self::assertSame([], $this->rows("SELECT * FROM $table"));
        }
        $this->db->exec('DROP TRIGGER fail_fact');
        $this->post($fields, 302);
        self::assertCount(2, $this->rows('SELECT * FROM uat_audit_log'));
    }
}
