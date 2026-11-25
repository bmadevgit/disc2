<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Repository;

use PDO;
use PDOStatement;

abstract class Repository
{
    public function __construct(protected readonly PDO $db)
    {
    }

    protected function execute(string $sql, array $values = []): PDOStatement
    {
        $statement = $this->db->prepare($sql);
        $statement->execute($values);
        return $statement;
    }

    protected function row(string $sql, array $values = []): ?array
    {
        return $this->execute($sql, $values)->fetch() ?: null;
    }

    protected function rows(string $sql, array $values = []): array
    {
        return $this->execute($sql, $values)->fetchAll();
    }
}
