<?php

declare(strict_types=1);

namespace App\Application\Query;

use App\Domain\CaseFile\SubjectIdentity;

/** Each input row has already been scoped to a readable case by its caller. */
final class SubjectPresentation
{
    public static function forReader(array $row, bool $identity): array
    {
        $subject = new SubjectIdentity((int)($row['case_id'] ?? 0), (int)($row['form_subject_id'] ?? $row['id']),
            $row['sequence_no'], (string)$row['reference_code'], (string)$row['title_th'],
            (string)$row['first_name_th'], (string)$row['last_name_th']);
        $row['label'] = $subject->displayLabel($identity);
        if (!$identity) {
            unset($row['title_th'], $row['first_name_th'], $row['last_name_th'], $row['national_id'], $row['position_name']);
        }
        return $row;
    }
}
