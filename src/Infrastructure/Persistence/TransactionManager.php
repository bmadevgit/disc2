<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use PDO;
use Throwable;

final readonly class TransactionManager
{
    public function __construct(private PDO $db)
    {
    }

    /** Acquire the writer lock before the use case reads the state it will change. */
    public function run(callable $operation): mixed
    {
        $this->db->beginTransaction();
        try {
            $this->db->exec('UPDATE uat_tracks SET id=id WHERE 0');
            $result = $operation();
            $this->db->commit();
            return $result;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }
}
