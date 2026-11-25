<?php

declare(strict_types=1);

namespace App\Domain\CaseFile;

use App\Domain\Workflow\CalendarDate;
use App\Domain\Workflow\TrackType;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use InvalidArgumentException;

final readonly class Escalation
{
    private function __construct(
        public int $subjectId,
        public DisciplineRound $source,
        public DisciplineRound $target,
        public string $effectiveDate,
        public string $reference,
        public array $snapshot,
    ) {
    }

    /** The caller supplies the serious track selected from the case's actual appointment records. */
    public static function transfer(
        int $subjectId,
        DisciplineRound $source,
        DisciplineRound $target,
        string $effectiveDate,
        string $reference,
        DateTimeImmutable $recordedAt,
    ): self {
        CalendarDate::parse($effectiveDate);
        $reference = trim($reference);
        if ($reference === '') {
            throw new InvalidArgumentException('An escalation requires an order reference.');
        }
        if ($source->type !== TrackType::NonSerious || !$source->hasMember($subjectId)) {
            throw new DomainException('Transfer requires an active member of the non-serious source round.');
        }
        if ($target->type !== TrackType::Serious || $target->track['case_id'] !== $source->track['case_id']
            || $target->track['id'] === $source->track['id']) {
            throw new DomainException('Select a serious committee round in the same case.');
        }

        $finding = $source->findings[$subjectId] ?? [];
        if (($finding['recommend_serious'] ?? 0) !== 1 || trim($finding['committee_opinion'] ?? '') === '') {
            throw new DomainException('Record the committee opinion recommending a serious round before transfer.');
        }

        $snapshot = [
            'track' => $source->track,
            'orders' => $source->orders,
            'extensions' => $source->extensions,
            'members' => $source->members,
            'committee_sets' => $source->committeeSets,
            'committee_members' => $source->committeeMembers,
            'finding' => $finding,
            'decisions' => $source->decisions[$subjectId] ?? [],
        ];
        $endedAt = $recordedAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        return new self($subjectId, $source->withoutActiveMember($subjectId, $endedAt),
            $target->withNewMember($subjectId), $effectiveDate, $reference, $snapshot);
    }
}
