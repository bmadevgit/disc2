<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\Command\{AddExtension, ConvertSeriousDeadline, CreateCommittee, EscalateDiscipline, SaveDisciplineResult, SaveFinding, SaveTrack};
use App\Domain\Workflow\TrackType;
use App\Security\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;

final class WorkflowController extends AbstractController
{
    public function __construct(private readonly SaveTrack $dates, private readonly ConvertSeriousDeadline $convert,
        private readonly SaveFinding $findings, private readonly CreateCommittee $committees, private readonly AddExtension $extensions,
        private readonly EscalateDiscipline $escalate, private readonly SaveDisciplineResult $outcomes)
    {
    }

    /** Called after the save_case voter. Each command rechecks under the SQLite writer lock. */
    public function save(User $user, int $caseId, array $input): Response
    {
        $action = is_string($input['action'] ?? null) ? $input['action'] : '';
        switch ($action) {
            case 'save_fact':
                $this->findings->run($user, $caseId, $input);
                return $this->tab($caseId, 2);
            case 'assign_discipline':
                $this->findings->assign($user, $caseId, $input);
                return $this->tab($caseId, 3);
            case 'save_decision':
                $this->outcomes->decision($user, $caseId, $input);
                return $this->tab($caseId, 4);
        }
        $track = match ($action) {
            'save_track' => $this->dates->run($user, $caseId, $input),
            'convert_serious_deadline' => $this->convert->run($user, $caseId, $input),
            'add_extension' => $this->extensions->run($user, $caseId, $input),
            'add_order' => $this->committees->order($user, $caseId, $input),
            'escalate_discipline' => $this->escalate->run($user, $caseId, $input),
            default => null,
        };
        if ($track === null) {
            return new Response('ไม่พบรายการที่ต้องการบันทึก', 400);
        }
        return $track['track_type'] === TrackType::Fact->value ? $this->tab($caseId, 2) :
            $this->redirectToRoute('discipline_track', ['case_id'=>$caseId,'type'=>$track['track_type'],'track_id'=>$track['id'],'saved'=>1]);
    }

    private function tab(int $caseId, int $page): Response
    {
        return $this->redirectToRoute('case_detail', ['id'=>$caseId,'page'=>$page,'saved'=>1]);
    }
}
