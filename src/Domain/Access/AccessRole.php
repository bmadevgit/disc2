<?php

declare(strict_types=1);

namespace App\Domain\Access;

enum AccessRole: string
{
    case Anonymous = 'anonymous';
    case None = 'none';
    case Admin = 'admin';
    case Executive = 'executive';
    case UnitHead = 'unit_head';
    case GroupHead = 'group_head';
    case CaseOwner = 'case_owner';
}
