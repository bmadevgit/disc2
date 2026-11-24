<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Repository;

final class UserRepository extends Repository
{
    public function byUsername(string $username, bool $adminOnly = false): ?array
    {
        return $this->row('SELECT id,username,name,role,password_hash FROM users WHERE username=?'
            .($adminOnly ? " AND role='admin'" : ''), [$username]);
    }

    public function unitFor(string $personalId): ?string
    {
        return $this->row('SELECT unit_level1 FROM user_unit_assignments WHERE bma_personal_id=?', [$personalId])['unit_level1'] ?? null;
    }

    public function assignInitialUnit(string $personalId, string $email, string $unit): void
    {
        $this->execute('INSERT OR IGNORE INTO user_unit_assignments(bma_personal_id,bma_email,unit_level1) VALUES(?,?,?)',
            [$personalId, $email, $unit]);
    }

    public function failuresSince(string $ip, string $cutoff): int
    {
        return (int)$this->execute("SELECT COUNT(*) FROM login_log WHERE ip=? AND status='fail' AND created_at>=?",
            [$ip, $cutoff])->fetchColumn();
    }

    public function logLogin(string $username, string $name, string $role, string $ip, string $agent, string $status): void
    {
        $this->execute('INSERT INTO login_log(username,user_name,user_role,ip,user_agent,status) VALUES(?,?,?,?,?,?)',
            [$username, $name, $role, $ip, substr($agent, 0, 500), $status]);
    }
}
