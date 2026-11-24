<?php

declare(strict_types=1);

namespace App\Tests\Integration\Persistence;

use App\Domain\CaseFile\Escalation;
use App\Domain\Workflow\TrackType;
use App\Infrastructure\Persistence\ConnectionFactory;
use App\Infrastructure\Persistence\Repository\CommitteeRepository;
use App\Infrastructure\Persistence\Repository\DecisionRepository;
use App\Infrastructure\Persistence\Repository\EscalationRepository;
use App\Infrastructure\Persistence\Repository\ExtensionRepository;
use App\Infrastructure\Persistence\Repository\FindingRepository;
use App\Infrastructure\Persistence\Repository\SubjectRepository;
use App\Infrastructure\Persistence\Repository\TrackRepository;
use App\Infrastructure\Persistence\TransactionManager;
use App\Tests\Integration\SyntheticDatabaseTestCase;
use DateTimeImmutable;

final class WorkflowRepositoryTest extends SyntheticDatabaseTestCase
{
    public function testMembershipsRequireMatchingCases(): void
    {
        $this->copiedFixture();
        $db = (new ConnectionFactory($this->directory, 'test'))->open();
        $tracks = new TrackRepository($db);
        $db->exec('INSERT INTO cases(id) VALUES(2)');
        $otherTrack = $tracks->ensureFirst(2, TrackType::Serious);
        foreach ([false, true] as $allowExisting) {
            try {
                $tracks->addMember($otherTrack, 1, $allowExisting);
                self::fail('A subject from a different case must be rejected.');
            } catch (\DomainException) {
                self::assertSame(0, $db->query('SELECT COUNT(*) FROM uat_track_subjects')->fetchColumn());
            }
        }
    }

    public function testInconsistentLegacyMembershipsAreExcludedFromCaseReads(): void
    {
        $this->copiedFixture();
        $db = (new ConnectionFactory($this->directory, 'test'))->open();
        $tracks = new TrackRepository($db);
        $findings = new FindingRepository($db);
        $db->exec('INSERT INTO cases(id) VALUES(2)');
        $otherTrack = $tracks->ensureFirst(2, TrackType::Serious);
        $db->prepare('INSERT INTO uat_track_subjects(track_id,subject_id) VALUES(?,1)')->execute([$otherTrack]);
        $db->prepare("INSERT INTO uat_subject_findings(track_id,subject_id,finding) VALUES(?,1,'guilty')")->execute([$otherTrack]);
        $db->prepare("INSERT INTO uat_committee_sets(track_id,set_code) VALUES(?,'legacy')")->execute([$otherTrack]);
        $db->prepare('INSERT INTO uat_committee_set_subjects(committee_set_id,subject_id) VALUES(?,1)')->execute([$db->lastInsertId()]);
        self::assertSame([], $tracks->membersWithResults($otherTrack));
        self::assertSame([], $tracks->members($otherTrack));
        self::assertSame([], $tracks->history(2, 1));
        self::assertFalse($tracks->hasActiveDiscipline(2));
        self::assertNull($tracks->currentDiscipline(2, 1));
        self::assertSame(['total'=>0, 'no_ground'=>null], $findings->factCounts($otherTrack, 1));
        self::assertSame(['total'=>0, 'no_ground'=>null], $findings->factCounts($otherTrack, 2));

        self::assertSame([], $findings->forTrack($otherTrack));
        self::assertNull($findings->forSubject($otherTrack, 1));
        self::assertSame([], (new CommitteeRepository($db))->setMembers($otherTrack));
    }

    public function testTransferRetainsPerPersonHistoryAndSelectedCommittee(): void
    {
        $this->copiedFixture();
        $fixture = (require $this->root.'/tests/Fixtures/legacy-workflows.php')['round'];
        $db = (new ConnectionFactory($this->directory, 'test'))->open();
        $tracks = new TrackRepository($db);
        $orders = new CommitteeRepository($db);
        $extensions = new ExtensionRepository($db);
        $findings = new FindingRepository($db);
        $decisions = new DecisionRepository($db);
        $subjects = new SubjectRepository($db);
        $escalations = new EscalationRepository($db, $tracks, $orders, $extensions, $findings, $decisions);
        $fact = $tracks->ensureFirst(1, TrackType::Fact);
        $source = $tracks->ensureFirst(1, TrackType::NonSerious);
        $first = $tracks->ensureFirst(1, TrackType::Serious);
        $target = $tracks->createSeriousSet(1);
        self::assertNotSame($first, $target);
        self::assertSame(2, $tracks->find(1, TrackType::Serious, $target)['set_no']);
        foreach ($fixture['old_results'] as $sid => $finding) {
            $tracks->addMember($fact, $sid);
            $findings->saveFact($fact, $sid, 'ground', 'nonserious', 'synthetic');
            $tracks->addMember($source, $sid);
            $findings->saveDiscipline($source, $sid, 'nonserious', $finding + [
                'allegation'=>'', 'offense_basis'=>'', 'appointing_authority_opinion'=>'',
            ]);
        }
        $orders->addOrder($source, 'appoint', '2024-02-01', $fixture['original_order'], '');
        $orders->addOrder($target, 'appoint', '2024-04-01', $fixture['selected_order'], '');
        $e = $fixture['extension'];
        $extensions->add($source, 1, $e['requested_days'], $e['completed_work'], $e['reason'], $fixture['extended_due']);
        $db->prepare('INSERT INTO uat_committee_sets(track_id,set_code) VALUES(?,?)')->execute([$source, 'old']);
        $committee = (int)$db->lastInsertId();
        $db->prepare('INSERT INTO uat_committee_set_subjects(committee_set_id,subject_id) VALUES(?,?)')->execute([$committee, 1]);
        // Pre-004 decisions without a mapped track still belong in the source snapshot.
        $db->prepare('INSERT INTO uat_decisions(case_id,subject_id,route_type,order_number) VALUES(?,?,?,?)')
            ->execute([1, 1, 'nonserious', $fixture['old_decision']]);
        $db->prepare('INSERT INTO uat_kk_reports(decision_id,sequence_no,letter_number) VALUES(?,?,?)')
            ->execute([(int)$db->lastInsertId(), 1, $fixture['old_report']]);

        (new TransactionManager($db))->run(function () use ($escalations, $source, $target, $fixture): void {
            $transfer = Escalation::transfer(1, $escalations->round(1, TrackType::NonSerious, $source),
                $escalations->round(1, TrackType::Serious, $target), $fixture['effective_date'], $fixture['reference'],
                new DateTimeImmutable('2024-04-10T12:00:00Z'));
            $escalations->record($transfer, 'synthetic', 'Synthetic owner');
        });
        self::assertSame([$target=>$fixture['selected_order']], array_column($tracks->seriousAppointments(1), 'appointment', 'id'));
        self::assertTrue($tracks->hasActiveDiscipline(1));
        self::assertSame(['total'=>2, 'no_ground'=>0], $findings->factCounts($fact, 1));

        $history = $escalations->forSubject(1)[0];
        $snapshot = json_decode($history['snapshot'], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($target, $history['to_track_id']);
        self::assertSame($fixture['old_report'], $snapshot['decisions'][0]['reports'][0]['letter_number']);
        self::assertSame($fixture['original_order'], $snapshot['orders'][0]['order_number']);
        self::assertSame($fixture['extended_due'], $snapshot['extensions'][0]['new_due_date']);
        self::assertNull($snapshot['committee_members'][0]['ended_at']);
        self::assertSame('2024-04-10 12:00:00', $orders->setMembers($source)[0]['ended_at']);
        self::assertSame($target, $tracks->currentDiscipline(1, 1)['id']);
        self::assertSame($source, $tracks->currentDiscipline(1, 2)['id']);
        self::assertSame([null, 'not_guilty'], array_column($subjects->withCurrentFindings(1), 'finding'));
        self::assertSame([$target, $source], array_column($decisions->currentForCase(1), 'current_track_id'));
    }
}
