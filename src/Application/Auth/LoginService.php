<?php

declare(strict_types=1);

namespace App\Application\Auth;

use App\Infrastructure\Persistence\Repository\UserRepository;
use App\Infrastructure\Persistence\TransactionManager;
use App\Security\User;
use App\Security\UserProvider;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;

final readonly class LoginService
{
    public function __construct(
        private AuthSettings $settings,
        private BmaDirectory $directory,
        private UserRepository $users,
        private UserProvider $provider,
        private TransactionManager $transactions,
    ) {
    }

    public function login(string $username, #[\SensitiveParameter] string $password, string $type, string $ip, string $agent): User
    {
        $username = trim($username);
        if (!$this->settings->isDemo()) {
            $password = trim($password);
        }
        if ($username === '' || $password === '') {
            throw new CustomUserMessageAuthenticationException('กรุณากรอกข้อมูลให้ครบถ้วน');
        }
        $this->checkLimit($ip);
        $local = $this->settings->isDemo() || $type === 'admin';
        $logRole = $type === 'admin' && !$this->settings->isDemo() ? 'admin' : 'user';
        $row = $record = null;
        $email = $username;
        if ($local) {
            $row = $this->users->byUsername($username, !$this->settings->isDemo());
            if ($row !== null && !password_verify($password, $row['password_hash'])) {
                $row = null;
            }
        } else {
            $mailbox = preg_replace('/@bangkok\.go\.th$/iD', '', $username);
            $email = $mailbox.'@bangkok.go.th';
            $profile = $this->directory->lookup($mailbox);
            $id = $profile['LdapPersonalID'] ?? null;
            if (is_string($id) && $id !== '' && hash_equals($id, $password)) {
                $record = $profile;
            }
        }

        // Recheck under the SQLite writer lock after password verification or the remote lookup.
        $actor = $this->transactions->run(function () use ($local, $row, $record, $username, $email, $logRole, $ip, $agent) {
            $this->checkLimit($ip);
            $user = null;
            $role = $logRole;
            if ($local && $row !== null) {
                $current = $this->users->byUsername($username, !$this->settings->isDemo());
                if ($current !== null && $current['id'] === $row['id'] && $current['password_hash'] === $row['password_hash']) {
                    $user = $this->provider->fromLocal($current);
                    $role = $current['role'];
                }
            } elseif ($record !== null) {
                $user = $this->resolveBma($record, $email, $username);
            }
            $this->users->logLogin($email, $user?->name ?? '', $role, $ip, $agent, $user ? 'success' : 'fail');
            return $user;
        });
        if ($actor === null) {
            throw new CustomUserMessageAuthenticationException($record === null ? 'ชื่อบัญชีหรือรหัสผ่านไม่ถูกต้อง' : 'ไม่พบข้อมูลหน่วยงาน (UnitLevel1) สำหรับบัญชีนี้');
        }
        return $actor;
    }

    private function resolveBma(array $record, string $email, string $username): ?User
    {
        $id = $record['LdapPersonalID'];
        $unit = $this->users->unitFor($id);
        if ($unit === null && trim($record['UnitLevel1'] ?? '') !== '') {
            $this->users->assignInitialUnit($id, $email, trim($record['UnitLevel1']));
            $unit = $this->users->unitFor($id);
        }
        if ($unit === null || $unit === '') {
            return null;
        }
        $name = trim(implode(' ', array_filter(array_map('trim', [
            $record['TitleTH'] ?? '', $record['FirstNameTH'] ?? '', $record['LastNameTH'] ?? '',
        ]))));
        if ($name === '') {
            $name = trim($record['LdapDisplayName'] ?? '');
            if ($name === '' && !empty($record['LdapFirstName'])) {
                $name = trim($record['LdapFirstName'].' '.($record['LdapLastName'] ?? ''));
            }
        }
        return $this->provider->fromBma($id, $name ?: $username, $email, $unit);
    }

    private function checkLimit(string $ip): void
    {
        // login_log.created_at uses SQLite CURRENT_TIMESTAMP (UTC).
        if ($this->users->failuresSince($ip, gmdate('Y-m-d H:i:s', time() - 900)) >= 5) {
            throw new CustomUserMessageAuthenticationException('IP นี้ถูกล็อกชั่วคราว 15 นาที');
        }
    }
}
