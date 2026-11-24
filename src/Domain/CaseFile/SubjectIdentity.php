<?php

declare(strict_types=1);

namespace App\Domain\CaseFile;

final readonly class SubjectIdentity
{
    public function __construct(
        public int $caseId,
        public int $subjectId,
        public ?int $sequence,
        public string $reference,
        public string $title,
        public string $firstName,
        public string $lastName,
    ) {
    }
}
