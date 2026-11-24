<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Workflow;

use App\Domain\Workflow\DeadlineCalculator;
use App\Domain\Workflow\DeadlineInput;
use App\Domain\Workflow\DeadlineResult;
use App\Domain\Workflow\TrackType;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DeadlineCalculatorTest extends TestCase
{
    private static function fixtures(): array
    {
        return require dirname(__DIR__, 3).'/Fixtures/legacy-workflows.php';
    }

    public static function schedules(): iterable
    {
        foreach (self::fixtures()['dates'] as $type => $rows) {
            foreach ($rows as $description => [$fields, $expected]) {
                yield "$type $description" => [$type, $fields, $expected];
            }
        }

        yield 'day one on leap day' => ['fact', [
            'acknowledged_date' => '2024-02-29', 'first_meeting_date' => '2024-02-29', 'duration_days' => 1,
        ], ['2024-02-29', 1, '2024-02-29']];
        yield 'meeting before acknowledgement, year rolls over' => ['fact', [
            'acknowledged_date' => '2024-12-31', 'first_meeting_date' => '2024-12-29', 'duration_days' => 2,
        ], ['2025-01-01', 2, '2025-01-02']];
        yield 'serious needs only first meeting, common century' => ['discipline_serious', [
            'first_meeting_date' => '2100-02-28',
        ], ['2100-03-01', 120, '2100-06-28']];
    }

    #[DataProvider('schedules')]
    public function testInclusiveDeadline(string $type, array $fields, array $expected): void
    {
        $input = new DeadlineInput(TrackType::from($type), $fields['acknowledged_date'] ?? '',
            $fields['first_meeting_date'], $fields['duration_days'] ?? null);
        $result = (new DeadlineCalculator())->calculate($input);
        self::assertSame($expected, self::dates($result));
        self::assertSame($result->dueDate, $result->extendedDueDate());
    }

    private static function dates(DeadlineResult $result): array
    {
        return [$result->startDate, $result->durationDays, $result->dueDate];
    }

    public function testConfirmedConversionRetainsBothSchedulesAndAllExtensionEvidence(): void
    {
        $fixture = self::fixtures()['legacy_conversion'];
        $extensions = [];
        foreach ($fixture['extensions'] as $i => $row) {
            $extensions[] = ['id' => 9 + $i, 'requested_days' => $row['days'], 'reason' => $row['reason']];
        }
        $input = DeadlineInput::legacySerious($fixture['dates']['acknowledged_date'],
            $fixture['dates']['first_meeting_date'], extensions: $extensions);
        $calculator = new DeadlineCalculator();
        $conversion = $calculator->confirmLegacy($input, $fixture['reason'], $calculator->calculate($input));
        self::assertSame($fixture['reason'], $conversion->reason);
        foreach (['before' => 'old', 'after' => 'confirmed'] as $side => $key) {
            $result = $conversion->$side;
            self::assertSame($fixture[$key], self::dates($result));
            foreach ($extensions as $i => $extension) {
                self::assertSame($extension + ['new_due_date' => $fixture['extensions'][$i][$side]], $result->extensions[$i]);
            }
            self::assertSame($fixture['extensions'][1][$side], $result->extendedDueDate());
        }
        self::assertSame($fixture['old'], self::dates((new DeadlineCalculator())->calculate($input)));
    }

    public function testExistingPublishedDatesAreCopiedVerbatimIntoConversionHistory(): void
    {
        $input = DeadlineInput::legacySerious('', '2024-02-05');
        $published = new DeadlineResult('2024-02-04', 180, '2024-08-01', '', [
            ['requested_days' => 7, 'reason' => 'Prior decision', 'new_due_date' => '2024-08-12'],
        ]);
        $converted = (new DeadlineCalculator())->confirmLegacy($input, 'Reviewed', $published);
        self::assertSame($published, $converted->before);
        self::assertSame('2024-08-12', $converted->before->extendedDueDate());
        self::assertSame('2024-06-04', $converted->after->dueDate);
        self::assertSame([['requested_days' => 7, 'reason' => 'Prior decision', 'new_due_date' => '2024-06-11']],
            $converted->after->extensions);
    }

    public static function malformedPublishedSchedules(): iterable
    {
        yield 'missing start' => ['', 180, '2024-08-02', []];
        yield 'invalid base deadline' => ['2024-02-05', 180, '2024-02-30', []];
        yield 'published start beyond year 9999' => ['10000-01-01', 180, '9999-12-31', []];
        yield 'published due beyond year 9999' => ['9999-12-31', 2, '10000-01-01', []];
        yield 'published extension beyond year 9999' => ['9999-12-31', 1, '9999-12-31', [
            ['requested_days' => 1, 'new_due_date' => '10000-01-01'],
        ]];
        yield 'invalid base duration' => ['2024-02-05', 0, '2024-08-02', []];
        yield 'missing extension date' => ['2024-02-05', 180, '2024-08-02', [['requested_days' => 7]]];
        yield 'non-canonical extension date' => ['2024-02-05', 180, '2024-08-02', [['requested_days' => 7, 'new_due_date' => '2024-8-09']]];
        yield 'null extension date' => ['2024-02-05', 180, '2024-08-02', [['requested_days' => 7, 'new_due_date' => null]]];
        yield 'missing extension duration' => ['2024-02-05', 180, '2024-08-02', [['new_due_date' => '2024-08-09']]];
        yield 'extension duration out of bounds' => ['2024-02-05', 180, '2024-08-02', [['requested_days' => 365001, 'new_due_date' => '2024-08-09']]];
        yield 'malformed row' => ['2024-02-05', 180, '2024-08-02', ['invalid']];
    }

    #[DataProvider('malformedPublishedSchedules')]
    public function testMalformedPublishedScheduleRejectedAtConstruction(string $start, int $days, string $due, array $extensions): void
    {
        $this->expectException(InvalidArgumentException::class);
        new DeadlineResult($start, $days, $due, '', $extensions);
    }

    public function testManualOverrideSurvivesConversion(): void
    {
        $input = DeadlineInput::legacySerious('', '', '2024-02-29', 'Order changes start');
        $calculator = new DeadlineCalculator();
        $conversion = $calculator->confirmLegacy($input, 'Reviewed order', $calculator->calculate($input));
        self::assertSame(['2024-02-29', 180, '2024-08-26'], self::dates($conversion->before));
        self::assertSame(['2024-02-29', 120, '2024-06-27'], self::dates($conversion->after));
        self::assertSame('Order changes start', $conversion->after->startOverrideReason);
    }

    public static function invalidInputs(): iterable
    {
        yield 'missing acknowledgement' => ['fact', '', '2024-02-29'];
        yield 'missing meeting' => ['discipline_nonserious', '2024-02-29', ''];
        yield 'missing serious meeting' => ['discipline_serious', '2024-02-29', ''];
        yield 'invalid common-century leap date' => ['fact', '2100-02-29', '2100-03-01'];
        yield 'user date beyond year 9999' => ['fact', '10000-01-01', '10000-01-01'];
        yield 'invalid month' => ['fact', '2024-13-01', '2024-01-01'];
        yield 'noncanonical date' => ['fact', '2024-2-01', '2024-02-01'];
        yield 'zero duration' => ['fact', '2024-02-01', '2024-02-01', 0];
        yield 'over maximum duration' => ['fact', '2024-02-01', '2024-02-01', 365001];
        yield 'fixed nonserious duration' => ['discipline_nonserious', '2024-02-01', '2024-02-01', 30];
        yield 'new serious cannot choose legacy timing' => ['discipline_serious', '', '2024-02-01', 180];
        yield 'manual date without reason' => ['fact', '', '', 1, '2024-02-01', '  '];
        yield 'manual reason without date' => ['fact', '', '', 1, '', 'A reason'];
        yield 'zero extension' => ['fact', '2024-02-01', '2024-02-01', 1, '', '', [['requested_days' => 0]]];
        yield 'missing extension duration' => ['fact', '2024-02-01', '2024-02-01', 1, '', '', [['days' => 1]]];
        yield 'over maximum extension' => ['fact', '2024-02-01', '2024-02-01', 1, '', '', [['requested_days' => 365001]]];
    }

    #[DataProvider('invalidInputs')]
    public function testInvalidCombinations(string $type, string $ack, string $meeting, ?int $days = null,
        string $manualStart = '', string $reason = '', array $extensions = []): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new DeadlineCalculator())->calculate(new DeadlineInput(TrackType::from($type), $ack, $meeting, $days,
            $manualStart, $reason, $extensions));
    }

    public function testCalculatedBaseDeadlineMayCrossYear9999(): void
    {
        $result = (new DeadlineCalculator())->calculate(new DeadlineInput(TrackType::Fact,
            '9999-12-31', '9999-12-31', 2));
        self::assertSame(['9999-12-31', 2, '10000-01-01'], self::dates($result));
        self::assertSame('10000-01-01', $result->extendedDueDate());
    }

    public function testCalculatedExtensionMayCrossYear9999(): void
    {
        $result = (new DeadlineCalculator())->calculate(new DeadlineInput(TrackType::Fact,
            '9999-12-31', '9999-12-31', 1, extensions: [['requested_days' => 1, 'reason' => 'Recorded']]));
        self::assertSame('9999-12-31', $result->dueDate);
        self::assertSame([['requested_days' => 1, 'reason' => 'Recorded', 'new_due_date' => '10000-01-01']],
            $result->extensions);
        self::assertSame('10000-01-01', $result->extendedDueDate());
    }

    public function testCalculatedStartMayCrossYear9999(): void
    {
        $result = (new DeadlineCalculator())->calculate(new DeadlineInput(TrackType::Fact,
            '9999-12-31', '9999-12-30', 1));
        self::assertSame(['10000-01-01', 1, '10000-01-01'], self::dates($result));
    }

    public function testMaximumDurationIsInclusive(): void
    {
        $result = (new DeadlineCalculator())->calculate(new DeadlineInput(TrackType::Fact, '2000-01-01',
            '2000-01-01', 365000, extensions: [['requested_days' => 1]]));
        self::assertSame('2999-05-02', $result->dueDate);
        self::assertSame('2999-05-03', $result->extendedDueDate());
    }

    public function testBlankConfirmationIsRejected(): void
    {
        $input = DeadlineInput::legacySerious('', '2024-02-01');
        $this->expectException(InvalidArgumentException::class);
        $calculator = new DeadlineCalculator();
        $calculator->confirmLegacy($input, '  ', $calculator->calculate($input));
    }

    public function testAlreadyCurrentScheduleCannotBeConfirmed(): void
    {
        $input = new DeadlineInput(TrackType::Serious, '', '2024-02-01');
        $this->expectException(InvalidArgumentException::class);
        $calculator = new DeadlineCalculator();
        $calculator->confirmLegacy($input, 'Reviewed', $calculator->calculate($input));
    }
}
