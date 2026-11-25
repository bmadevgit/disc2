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

    public function displayLabel(bool $identity): string
    {
        if ($identity) {
            $name = trim("$this->title $this->firstName $this->lastName");
            if ($name !== '') {
                return $name;
            }
        }
        return $this->reference !== '' ? $this->reference : 'ผู้ถูกกล่าวหารายที่ '.($this->sequence ?? $this->subjectId);
    }
}
