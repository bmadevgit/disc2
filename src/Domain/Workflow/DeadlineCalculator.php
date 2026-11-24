<?php

declare(strict_types=1);

namespace App\Domain\Workflow;

use DateTimeImmutable;
use InvalidArgumentException;

final class DeadlineCalculator
{
    public function calculate(DeadlineInput $input): DeadlineResult
    {
        $duration = $input->durationDays ?? $input->type->defaultDays();
        DeadlineInput::validateDays($duration);
        if ($input->legacy) {
            if ($input->type !== TrackType::Serious || $duration !== 180) {
                throw new InvalidArgumentException('Legacy timing applies to existing 180-day serious rounds.');
            }
        } elseif ($input->type !== TrackType::Fact && $duration !== $input->type->defaultDays()) {
            throw new InvalidArgumentException('Disciplinary rounds have a fixed duration.');
        }

        $start = $this->startDate($input);
        $due = $start->modify(sprintf('+%d days', $duration - 1));
        $extendedDue = $due;
        $history = [];
        foreach ($input->extensions as $extension) {
            $days = $extension['requested_days'] ?? null;
            DeadlineInput::validateDays($days);
            $extendedDue = $extendedDue->modify("+$days days");
            $extension['new_due_date'] = $extendedDue->format('Y-m-d');
            $history[] = $extension;
        }

        return new DeadlineResult($start->format('Y-m-d'), $duration, $due->format('Y-m-d'),
            trim($input->startOverrideReason), $history);
    }

    public function confirmLegacy(DeadlineInput $input, string $reason, DeadlineResult $published): DeadlineConversion
    {
        $reason = trim($reason);
        if (!$input->legacy || $input->type !== TrackType::Serious || $input->durationDays !== 180
            || $published->durationDays !== 180 || $reason === '') {
            throw new InvalidArgumentException('Confirmation requires an existing 180-day schedule and a reason.');
        }

        $current = new DeadlineInput(TrackType::Serious, $input->acknowledgedDate, $input->firstMeetingDate,
            manualStart: $input->manualStart, startOverrideReason: $input->startOverrideReason,
            extensions: $published->extensions);

        return new DeadlineConversion($published, $this->calculate($current), $reason);
    }

    private function startDate(DeadlineInput $input): DateTimeImmutable
    {
        $ack = $input->acknowledgedDate === '' ? null : CalendarDate::parse($input->acknowledgedDate);
        $meeting = $input->firstMeetingDate === '' ? null : CalendarDate::parse($input->firstMeetingDate);
        $reason = trim($input->startOverrideReason);
        if ($input->manualStart !== '' || $reason !== '') {
            if ($input->manualStart === '' || $reason === '') {
                throw new InvalidArgumentException('A manual start requires both a date and a reason.');
            }
            return CalendarDate::parse($input->manualStart);
        }

        if (!$meeting) {
            throw new InvalidArgumentException('Automatic timing requires a first meeting date.');
        }
        if ($input->type === TrackType::Serious) {
            return $input->legacy ? $meeting : $meeting->modify('+1 day');
        }
        if (!$ack) {
            throw new InvalidArgumentException('Automatic fact and non-serious timing requires an acknowledgement.');
        }
        return $ack == $meeting ? $ack : $ack->modify('+1 day');
    }
}
