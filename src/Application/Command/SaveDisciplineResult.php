<?php

declare(strict_types=1);

namespace App\Application\Command;

use App\Application\Auth\CaseAudit;
use App\Domain\CaseFile\DisciplineRound;
use App\Domain\Workflow\TrackType;
use App\Form\WorkflowFields as Fields;
use App\Infrastructure\Persistence\Repository\{DecisionRepository, FindingRepository};
use App\Security\User;
use InvalidArgumentException;

final readonly class SaveDisciplineResult
{
    public function __construct(private WorkflowWrite $write, private FindingRepository $findings,
        private DecisionRepository $decisions, private CaseAudit $audit)
    {
    }

    public function run(User $user, int $caseId, array $input): array
    {
        return $this->write->run($user, $caseId, function () use ($caseId, $input) {
            $sid = Fields::id($input, 'subject_id');
            $track = $this->write->current($caseId, $sid, Fields::id($input, 'track_id'));
            $serious = $track['track_type'] === TrackType::Serious->value;
            $fields = [];
            foreach (['finding','committee_opinion','appointing_authority_opinion','offense_basis','allegation'] as $field) {
                $fields[$field] = Fields::text($input, $field);
            }
            $fields['recommend_serious'] = (int)(!$serious && Fields::text($input, 'recommend_serious') === '1');
            try {
                DisciplineRound::validateFinding(TrackType::from($track['track_type']), $fields);
            } catch (InvalidArgumentException) {
                throw new WriteFailure($fields['recommend_serious'] && $fields['committee_opinion'] === ''
                    ? 'กรุณาบันทึกความเห็นของคณะกรรมการ' : 'ผลการสอบสวนไม่ถูกต้อง');
            }
            $this->findings->saveDiscipline($track['id'], $sid, $serious ? 'serious' : 'nonserious', $fields);
            $this->audit->record($caseId, 'save_discipline_findings', $sid.':'.$track['id']);
            return $track;
        });
    }

    public function decision(User $user, int $caseId, array $input): void
    {
        $this->write->run($user, $caseId, function () use ($caseId, $input) {
            $sid = Fields::id($input, 'subject_id');
            $track = $this->write->current($caseId, $sid, Fields::id($input, 'track_id'));
            $route = $track['track_type'] === TrackType::Serious->value ? 'serious' : 'nonserious';
            $fields = [];
            foreach (['kk_resolution','commander_decision','order_number'] as $field) {
                $fields[$field] = Fields::text($input, $field);
            }
            foreach (['report_kk_date','kk_resolution_date','order_date','finished_date'] as $field) {
                $fields[$field] = Fields::date($input, $field);
            }
            if ($route === 'serious' && ($fields['order_number'] !== '' || $fields['order_date'] !== '')
                && ($fields['kk_resolution'] === '' || $fields['kk_resolution_date'] === '')) {
                throw new WriteFailure('กรณีร้ายแรงต้องบันทึกวันที่และมติ ก.ก. ก่อนออกคำสั่ง');
            }
            $this->decisions->save($caseId, $track['id'], $sid, $route, $fields);
            $this->audit->record($caseId, 'save_decision', $sid.':'.$track['id']);
        });
    }
}
