<?php

declare(strict_types=1);

namespace App\Application\Command;

use App\Application\Auth\CaseAudit;
use App\Domain\Workflow\CalendarDate;
use App\Form\WorkflowFields as Fields;
use App\Infrastructure\Persistence\Repository\ReferralRepository;
use App\Security\User;

final readonly class SaveReferral
{
    public function __construct(private WorkflowWrite $write, private ReferralRepository $referrals, private CaseAudit $audit)
    {
    }

    public function run(User $user, int $caseId, array $input): void
    {
        $this->write->run($user, $caseId, function () use ($caseId, $input) {
            $ordered = Fields::date($input, 'ordered_date');
            $due = $ordered === '' ? '' : CalendarDate::parse($ordered)->modify('+44 days')->format('Y-m-d');
            $status = Fields::text($input, 'status');
            if (!in_array($status, ['pending','reviewing','reporting','continue','finished'], true)) {
                $status = 'pending';
            }
            if (!$this->referrals->save($caseId, Fields::id($input, 'track_id'), Fields::id($input, 'subject_id'),
                $ordered, Fields::date($input, 'received_date'), $due, $status, Fields::text($input, 'note'))) {
                throw new WriteFailure('ข้อมูลผู้ถูกกล่าวหาไม่ถูกต้อง');
            }
            $this->audit->record($caseId, 'save_referral', 'UAT referral');
        });
    }
}
