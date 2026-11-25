<?php

declare(strict_types=1);

namespace App\Application\Query;

final class WorkflowLabels
{
    public const STAGES = ['legacy'=>'ข้อมูลเดิม', 'case_info'=>'รับเรื่อง', 'fact_finding'=>'สืบสวนข้อเท็จจริง',
        'discipline'=>'สอบสวนทางวินัย', 'closed'=>'ยุติเรื่อง'];

    public const FINDINGS = ['ground'=>['มีมูล','warn'], 'no_ground'=>['ไม่มีมูล','clear'],
        'guilty'=>['ผิดวินัย','alert'], 'not_guilty'=>['ไม่ผิดวินัย','clear'], 'pending'=>['รอบันทึกผล','neutral']];

    public const DISCIPLINE_FIELDS = ['committee_opinion'=>'ความเห็นของคณะกรรมการ',
        'appointing_authority_opinion'=>'ความเห็นของผู้มีอำนาจสั่งบรรจุ', 'offense_basis'=>'ฐานความผิด', 'allegation'=>'ข้อกล่าวโทษ'];

    public static function trackTitle(array $track): string
    {
        return match ($track['track_type']) {
            'fact' => 'สืบสวนข้อเท็จจริง',
            'discipline_nonserious' => 'สอบสวนวินัยอย่างไม่ร้ายแรง',
            default => 'สอบสวนวินัยอย่างร้ายแรง · ชุดที่ '.$track['set_no'],
        };
    }
}
