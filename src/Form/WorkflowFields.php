<?php

declare(strict_types=1);

namespace App\Form;

use App\Application\Command\WriteFailure;

final class WorkflowFields
{
    public static function text(array $input, string $key, string $default = ''): string
    {
        $value = $input[$key] ?? $default;
        if (!is_string($value) && !is_int($value)) {
            throw new WriteFailure('ข้อมูลแบบฟอร์มไม่ถูกต้อง');
        }
        return trim((string)$value);
    }

    public static function date(array $input, string $key): string
    {
        $date = self::text($input, $key);
        WriteForm::validateDate($date);
        return $date;
    }

    public static function id(array $input, string $key): int
    {
        $value = $input[$key] ?? null;
        if (!is_string($value) && !is_int($value)) {
            return 0;
        }
        return filter_var($value, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]) ?: 0;
    }

    public static function days(array $input, string $key, string $default = '', string $message = 'กรุณาระบุจำนวนวันเป็นจำนวนเต็มบวก'): int
    {
        $days = filter_var(self::text($input, $key, $default), FILTER_VALIDATE_INT, ['options'=>['min_range'=>1, 'max_range'=>365000]]);
        if ($days === false) {
            throw new WriteFailure($message);
        }
        return $days;
    }

    public static function subjectMap(array $input, string $key): array
    {
        $rows = $input[$key] ?? [];
        if (!is_array($rows)) {
            throw new WriteFailure('ข้อมูลผู้ถูกกล่าวหาไม่ถูกต้อง');
        }
        return $rows;
    }
}
