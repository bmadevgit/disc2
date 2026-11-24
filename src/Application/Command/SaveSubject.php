<?php

declare(strict_types=1);

namespace App\Application\Command;

use App\Form\WriteForm;
use App\Infrastructure\Persistence\Repository\SubjectRepository;

final readonly class SaveSubject
{
    public function __construct(private SubjectRepository $subjects)
    {
    }

    /** Page 1 commits the header, subjects, status changes and audit together. */
    public function apply(int $caseId, int $sequence, array $fields, bool $identity, int $submittedRows): void
    {
        $id = $fields['id'];
        $prior = $id ? $this->subjects->activeStatus($caseId, $id) : null;
        if ($id && $prior === null) {
            throw new WriteFailure('ไม่พบผู้ถูกกล่าวหาในสำนวนนี้', 404);
        }
        if ($id && !$identity) {
            if (isset($fields['employment'])) {
                $this->subjects->updateEmployment($caseId, $id, $fields['employment']);
            }
        } else {
            if (($fields['first'] ?? null) === '') {
                return;
            }
            if (!$id) {
                $next = $this->subjects->nextSequence($caseId);
                if ($sequence < $next) {
                    throw new WriteFailure('ข้อมูลผู้ถูกกล่าวหามีการเปลี่ยนแปลง กรุณาเปิดแบบฟอร์มใหม่', 409);
                }
                // A blank new row can leave a gap, bounded by the number of rows on this form.
                if ($sequence - $next >= $submittedRows) {
                    throw new WriteFailure('ลำดับผู้ถูกกล่าวหาไม่ถูกต้อง');
                }
            }
            $id = $this->subjects->save($caseId, $id, $sequence, [
                'title_th'=>$fields['title'] ?? null, 'first_name_th'=>$fields['first'] ?? null, 'last_name_th'=>$fields['last'] ?? null,
                'position_name'=>$fields['position'] ?? null, 'employment_type'=>$fields['employment'] ?? null, 'national_id'=>$fields['national_id'] ?? null,
            ]);
        }
        $status = $fields['status'] ?? '';
        if ($status !== '' && $status !== ($prior['status_name'] ?? null)) {
            $date = $fields['status_date'] ?? '';
            WriteForm::validateDate($date);
            $this->subjects->recordStatus($id, $status, $date, $fields['status_reason'] ?? '');
        }
    }
}
