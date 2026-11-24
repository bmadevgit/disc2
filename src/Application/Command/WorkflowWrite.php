<?php

declare(strict_types=1);

namespace App\Application\Command;

use App\Domain\Workflow\TrackType;
use App\Form\WorkflowFields;
use App\Infrastructure\Persistence\Repository\TrackRepository;
use App\Infrastructure\Persistence\TransactionManager;
use App\Security\User;
use PDOException;

final readonly class WorkflowWrite
{
    public function __construct(private TransactionManager $transactions, private WriteAccess $access,
        private TrackRepository $tracks)
    {
    }

    public function run(User $user, int $caseId, callable $operation): mixed
    {
        try {
            return $this->transactions->run(function () use ($user, $caseId, $operation) {
                $actor = $this->access->editor($user, $caseId);
                return $operation($actor);
            });
        } catch (PDOException $error) {
            // SQLite extended codes retain the primary result code in the low byte.
            if (in_array(((int)($error->errorInfo[1] ?? 0)) & 255, [5, 6], true)) {
                throw new WriteFailure('สำนวนกำลังถูกบันทึก กรุณาลองอีกครั้ง', 503);
            }
            throw $error;
        }
    }

    public function track(int $caseId, array $input): array
    {
        return $this->tracks->byId($caseId, WorkflowFields::id($input, 'track_id'))
            ?? throw new WriteFailure('ไม่พบชุดสอบสวน', 404);
    }

    public function discipline(int $caseId, array $input): array
    {
        $type = TrackType::tryFrom(WorkflowFields::text($input, 'track_type'));
        if (!$type || $type === TrackType::Fact) {
            throw new WriteFailure('ประเภทการสอบสวนไม่ถูกต้อง');
        }
        $id = WorkflowFields::id($input, 'track_id');
        if (!$id && !in_array($input['track_id'] ?? '', ['', '0', 0], true)) {
            throw new WriteFailure('ไม่พบกระบวนการสอบสวน', 404);
        }
        return $this->tracks->find($caseId, $type, $id) ?? throw new WriteFailure('ไม่พบกระบวนการสอบสวน', 404);
    }

    public function enroll(int $trackId, int $subjectId): void
    {
        $membership = $this->tracks->member($trackId, $subjectId);
        if ($membership === null) {
            $this->tracks->addMember($trackId, $subjectId);
        } elseif ($membership['ended_at'] !== null) {
            throw new WriteFailure('รอบสอบสวนของบุคคลนี้สิ้นสุดแล้ว', 409);
        }
    }

    public function current(int $caseId, int $subjectId, int $trackId): array
    {
        $track = $this->tracks->currentDiscipline($caseId, $subjectId);
        if (!$track || $track['id'] !== $trackId) {
            throw new WriteFailure('รอบสอบสวนเปลี่ยนไปแล้ว กรุณาเปิดหน้าปัจจุบัน', 409);
        }
        return $track;
    }
}
