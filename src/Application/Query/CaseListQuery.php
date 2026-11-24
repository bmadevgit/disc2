<?php

declare(strict_types=1);

namespace App\Application\Query;

use App\Domain\Access\CaseScope;
use App\Infrastructure\Persistence\Repository\CaseRepository;
use App\Infrastructure\Persistence\Repository\SubjectRepository;

final readonly class CaseListQuery
{
    public function __construct(private CaseRepository $cases, private SubjectRepository $subjects)
    {
    }

    public function fetch(CaseScope $scope, array $parameters): array
    {
        $filters = [];
        foreach (['q','unit','type','stage','finding'] as $key) {
            $filters[$key] = is_string($parameters[$key] ?? null) ? trim($parameters[$key]) : '';
        }
        $options = ['unit'=>[], 'type'=>[], 'stage'=>WorkflowLabels::STAGES,
            'finding'=>array_map(static fn ($badge) => $badge[0], WorkflowLabels::FINDINGS)];
        $visibleCases = [];
        foreach ($this->cases->visibleTo($scope) as $case) {
            $unit = $case['unit'] ?: 'ยังไม่ระบุ';
            $type = $case['case_type'] ?: 'ยังไม่ระบุ';
            $options['unit'][$unit] = $unit;
            $options['type'][$type] = $type;
            if (($filters['unit'] !== '' && $unit !== $filters['unit'])
                || ($filters['type'] !== '' && $type !== $filters['type'])
                || ($filters['stage'] !== '' && $case['stage'] !== $filters['stage'])) {
                continue;
            }
            $tokens = [$case['เลขที่สำนวน'], $case['unit']];
            $counts = [];
            foreach ($this->subjects->withCurrentFindings($case['id']) as $row) {
                $person = SubjectPresentation::forReader($row, $scope->canAdminister());
                $tokens[] = $person['reference_code'];
                $tokens[] = $person['label'];
                $code = $person['finding'] ?? '';
                $finding = isset(WorkflowLabels::FINDINGS[$code]) ? $code : 'pending';
                $counts[$finding] = ($counts[$finding] ?? 0) + 1;
            }
            if (($filters['q'] !== '' && mb_stripos(implode(' ', $tokens), $filters['q']) === false)
                || ($filters['finding'] !== '' && !isset($counts[$filters['finding']]))) {
                continue;
            }
            $visibleCases[] = $case + ['counts'=>$counts, 'subject_count'=>array_sum($counts)];
        }
        asort($options['unit'], SORT_NATURAL);
        asort($options['type'], SORT_NATURAL);
        return compact('visibleCases', 'filters', 'options');
    }
}
