<?php

declare(strict_types=1);

namespace App\Application\Query;

use App\Domain\Workflow\TrackType;
use App\Infrastructure\Persistence\Repository\{CaseRepository, CommitteeRepository, DecisionRepository, ExtensionRepository, FindingRepository, SubjectRepository, TrackRepository};

final readonly class CaseViewQuery
{
    public function __construct(private CaseRepository $cases, private SubjectRepository $subjects, private TrackRepository $tracks,
        private CommitteeRepository $committees, private ExtensionRepository $extensions, private FindingRepository $findings,
        private DecisionRepository $decisions)
    {
    }

    public function fetch(int $id, int $page, bool $identity): ?array
    {
        $case = $this->cases->find($id);
        if (!$case) {
            return null;
        }
        $present = static fn ($row) => SubjectPresentation::forReader($row, $identity);
        $result = ['case'=>$case, 'subjects'=>array_map($present, $this->subjects->activeForCase($id)), 'id'=>$id, 'page'=>$page];
        if ($page === 2) {
            $fact = $this->tracks->find($id, TrackType::Fact);
            $result += ['fact'=>$fact, 'findings'=>$fact ? array_column($this->findings->forTrack($fact['id']), null, 'subject_id') : [],
                'orders'=>$fact ? $this->committees->orders($fact['id']) : [], 'extensions'=>$fact ? $this->extensions->forTrack($fact['id']) : []];
        } elseif ($page === 3) {
            $grounds = $this->subjects->withGrounds($id);
            $unassigned = array_filter($grounds, fn ($row) => !$this->tracks->currentDiscipline($id, $row['id']));
            $result += ['hasGrounds'=>(bool)$grounds, 'unassigned'=>array_map($present, $unassigned),
                'tracks'=>$this->tracks->disciplineForCase($id)];
        } elseif ($page === 4) {
            $result['decisionRows'] = array_map($present, $this->decisions->currentForCase($id));
        }
        return $result;
    }
}
