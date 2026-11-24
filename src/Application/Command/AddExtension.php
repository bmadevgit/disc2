<?php

declare(strict_types=1);

namespace App\Application\Command;

use App\Application\Auth\CaseAudit;
use App\Form\WorkflowFields as Fields;
use App\Infrastructure\Persistence\Repository\ExtensionRepository;
use App\Security\User;
use DateTimeImmutable;
use DateTimeZone;

final readonly class AddExtension
{
    public function __construct(private WorkflowWrite $write, private ExtensionRepository $extensions, private CaseAudit $audit)
    {
    }

    public function run(User $user, int $caseId, array $input): array
    {
        return $this->write->run($user, $caseId, function () use ($caseId, $input) {
            $track = $this->write->track($caseId, $input);
            $days = Fields::days($input, 'requested_days');
            if ($track['due_date'] === '') {
                throw new WriteFailure('กรุณาบันทึกกำหนดเวลาก่อนขยายเวลา');
            }
            $extensions = $this->extensions->forTrack($track['id']);
            $last = end($extensions);
            $base = ($last['new_due_date'] ?? '') ?: $track['due_date'];
            // Calculated dates can cross year 9999 even though form inputs use four-digit years.
            $date = DateTimeImmutable::createFromFormat('!X-m-d', $base, new DateTimeZone('UTC'));
            if (!$date || $date->format('Y-m-d') !== $base) {
                throw new WriteFailure('วันที่ครบกำหนดเดิมไม่ถูกต้อง');
            }
            $this->extensions->add($track['id'], ($last['sequence_no'] ?? 0) + 1, $days, Fields::text($input, 'completed_work'),
                Fields::text($input, 'reason'), $date->modify("+$days days")->format('Y-m-d'));
            $this->audit->record($caseId, 'add_extension', (string)$track['id']);
            return $track;
        });
    }
}
