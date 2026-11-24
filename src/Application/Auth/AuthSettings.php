<?php

declare(strict_types=1);

namespace App\Application\Auth;

final readonly class AuthSettings
{
    private array $superAdminIds;
    private string $secret;

    public function __construct(private string $environment, string $configFile)
    {
        $config = !$this->isDemo() && is_file($configFile) ? require $configFile : [];
        $this->superAdminIds = $config['super_admin_ids'] ?? [];
        $this->secret = $config['bma_secret_key'] ?? '';
    }

    public function isDemo(): bool
    {
        return in_array($this->environment, ['dev', 'test'], true);
    }

    public function isSuperAdmin(string $actorId): bool
    {
        return in_array($actorId, $this->superAdminIds, true);
    }

    public function bmaSecret(): string
    {
        return $this->secret;
    }
}
