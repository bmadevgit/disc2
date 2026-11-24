<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\CaseFile;

use App\Domain\CaseFile\DisciplineRound;
use App\Domain\CaseFile\Escalation;
use App\Domain\Workflow\DeadlineCalculator;
use App\Domain\Workflow\DeadlineInput;
use App\Domain\Workflow\TrackType;
use DateTimeImmutable;
use DomainException;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EscalationTest extends TestCase
{
    private array $fixture;
    private DisciplineRound $source;
    private DisciplineRound $target;

    protected function setUp(): void
    {
        $fixtures = require dirname(__DIR__, 3).'/Fixtures/legacy-workflows.php';
        $this->fixture = $row = $fixtures['round'];
        [$dates] = $fixtures['dates']['discipline_nonserious']['different'];
        $schedule = (new DeadlineCalculator())->calculate(new DeadlineInput(TrackType::NonSerious,
            $dates['acknowledged_date'], $dates['first_meeting_date'], extensions: [$row['extension']]));
        $members = [['subject_id' => 1, 'ended_at' => null], ['subject_id' => 2, 'ended_at' => null]];
        $this->source = new DisciplineRound(
            track: ['id' => 10, 'case_id' => 7, 'track_type' => 'discipline_nonserious', 'due_date' => $schedule->dueDate],
            orders: [['event_type' => 'appoint', 'order_number' => $row['original_order']]],
            extensions: $schedule->extensions,
            members: $members,
            committeeSets: [['id' => 1, 'order_number' => $row['original_order']]],
            committeeMembers: $members,
            findings: $row['old_results'],
            decisions: [1 => [['order_number' => $row['old_decision'], 'reports' => [['letter_number' => $row['old_report']]]]]],
        );
        $this->target = new DisciplineRound(
            ['id' => 21, 'case_id' => 7, 'track_type' => 'discipline_serious', 'set_no' => 2],
            [['event_type' => 'appoint', 'order_number' => $row['selected_order']]],
        );
    }

    public function testTransferToSelectedOrderPreservesPerPersonHistory(): void
    {
        $row = $this->fixture;
        $move = Escalation::transfer(1, $this->source, $this->target, $row['effective_date'], $row['reference'],
            new DateTimeImmutable('2024-05-01 09:27:00 UTC'));
        self::assertSame([1, 10, 21, $row['effective_date'], $row['reference']],
            [$move->subjectId, $move->source->track['id'], $move->target->track['id'], $move->effectiveDate, $move->reference]);
        self::assertSame($row['selected_order'], $move->target->orders[0]['order_number']);
        self::assertSame($this->source->track, $move->snapshot['track']);
        self::assertSame($row['original_order'], $move->snapshot['orders'][0]['order_number']);
        self::assertSame($row['extension'] + ['new_due_date' => $row['extended_due']], $move->snapshot['extensions'][0]);
        self::assertSame($row['old_results'][1], $move->snapshot['finding']);
        self::assertSame($row['old_decision'], $move->snapshot['decisions'][0]['order_number']);
        self::assertSame($row['old_report'], $move->snapshot['decisions'][0]['reports'][0]['letter_number']);
        self::assertSame($row['original_order'], $move->snapshot['committee_sets'][0]['order_number']);
        foreach (['members', 'committee_members'] as $key) {
            self::assertSame([1, 2], array_column($move->snapshot[$key], 'subject_id'));
            self::assertSame([null, null], array_column($move->snapshot[$key], 'ended_at'));
        }
        self::assertSame(['2024-05-01 09:27:00', null], array_column($move->source->committeeMembers, 'ended_at'));
        self::assertFalse($move->source->hasMember(1));
        self::assertTrue($move->source->hasMember(2));
        self::assertTrue($move->target->hasMember(1));
        self::assertFalse($move->target->hasMember(2));
        self::assertTrue($this->source->hasMember(1));
        self::assertSame($row['old_results'], $move->source->findings);
        $updated = $move->target->withFinding(1, $row['new_result']);
        self::assertSame($row['new_result'], $updated->findings[1]);
        self::assertSame($row['old_results'][1], $move->snapshot['finding']);
        $this->expectException(DomainException::class);
        $move->source->withFinding(1, $row['new_result']);
    }

    public static function refusedTransfers(): iterable
    {
        yield 'no serious recommendation' => [2, 7, 'discipline_serious', '2024-04-10', 'REF', DomainException::class];
        yield 'not a current member' => [3, 7, 'discipline_serious', '2024-04-10', 'REF', DomainException::class];
        yield 'cross-case target' => [1, 8, 'discipline_serious', '2024-04-10', 'REF', DomainException::class];
        yield 'non-serious target' => [1, 7, 'discipline_nonserious', '2024-04-10', 'REF', DomainException::class];
        yield 'invalid effective date' => [1, 7, 'discipline_serious', '2023-02-29', 'REF', InvalidArgumentException::class];
        yield 'missing evidence' => [1, 7, 'discipline_serious', '2024-04-10', '  ', InvalidArgumentException::class];
    }

    #[DataProvider('refusedTransfers')]
    public function testRefusedTransfer(int $subject, int $caseId, string $type, string $date, string $reference, string $exception): void
    {
        $target = new DisciplineRound(['id' => 22, 'case_id' => $caseId, 'track_type' => $type], $this->target->orders);
        $this->expectException($exception);
        Escalation::transfer($subject, $this->source, $target, $date, $reference, new DateTimeImmutable());
    }

    public function testRecommendationMustHaveAValidFlag(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->source->withFinding(1, ['finding' => 'guilty', 'recommend_serious' => 2, 'committee_opinion' => 'Recorded']);
    }

    public function testRecommendationRequiresOpinion(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->source->withFinding(1, ['finding' => 'guilty', 'recommend_serious' => 1, 'committee_opinion' => ' ']);
    }
}
