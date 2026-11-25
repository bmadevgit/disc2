<?php

declare(strict_types=1);

namespace App\Application\Auth;

use App\Infrastructure\Persistence\Repository\AuditRepository;
use App\Security\User;
use LogicException;
use PDO;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final readonly class CaseAudit
{
    public function __construct(private TokenStorageInterface $tokens, private AuditRepository $records, private PDO $db)
    {
    }

    /** Case edits and global role assignments record the acting identity in their write transaction. */
    public function record(?int $caseId, string $action, string $detail = ''): void
    {
        $actor = $this->tokens->getToken()?->getUser();
        if (!$actor instanceof User) {
            throw new AccessDeniedException();
        }
        if (!$this->db->inTransaction()) {
            throw new LogicException('Case audit requires an active write transaction.');
        }
        $this->records->record($caseId, $actor->scope->actorId, $actor->name, $action, $detail);
    }
}
