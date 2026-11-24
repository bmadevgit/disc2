<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Application\Auth\CaseAudit;
use App\Domain\Access\CaseScope;
use App\Infrastructure\Persistence\Repository\AuditRepository;
use App\Infrastructure\Persistence\TransactionManager;
use App\Security\User;
use App\Tests\Integration\SyntheticDatabaseTestCase;
use LogicException;
use PDO;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class CaseAuditTest extends SyntheticDatabaseTestCase
{
    public function testAuditUsesAuthenticatedActorAndSharesWriteTransaction(): void
    {
        $this->copiedFixture();
        $db = new PDO('sqlite:'.$this->path, options: [PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $tokens = new TokenStorage();
        $records = new AuditRepository($db);
        $audit = new CaseAudit($tokens, $records, $db);
        $transaction = new TransactionManager($db);
        try {
            $transaction->run(fn () => $audit->record(1, 'test-action'));
            self::fail('Anonymous audit must be denied.');
        } catch (AccessDeniedException) {
            self::assertSame([], $records->forCase(1));
        }
        $actor = new User(CaseScope::forActor('actor', true), 'Synthetic actor', 'admin', true, '');
        $tokens->setToken(new UsernamePasswordToken($actor, 'main', $actor->getRoles()));
        try {
            $audit->record(1, 'test-action');
            self::fail('Audit outside a transaction must fail.');
        } catch (LogicException) {
            self::assertSame([], $records->forCase(1));
        }
        try {
            $transaction->run(function () use ($audit) {
                $audit->record(1, 'test-action');
                throw new \RuntimeException('Rollback');
            });
        } catch (\RuntimeException $e) {
            self::assertSame('Rollback', $e->getMessage());
        }
        self::assertSame([], $records->forCase(1));
        $transaction->run(fn () => $audit->record(1, 'test-action', 'detail'));
        $entry = $records->forCase(1)[0];
        self::assertSame(['actor', 'Synthetic actor', 'test-action', 'detail'],
            array_map(fn ($field) => $entry[$field], ['actor_id', 'actor_name', 'action', 'detail']));
    }
}
