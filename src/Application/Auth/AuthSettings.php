<?php

declare(strict_types=1);

namespace App\Application\Auth;

final readonly class AuthSettings
{
    public const BMA_DEFAULT_ENDPOINT = 'https://register.bangkok.go.th/bmamailadmin/web/index.php?r=api/getuserinfo';
    private string $endpoint;
    private array $superAdminIds;
    private string $secret;

    public function __construct(private string $environment, string $configFile)
    {
        $config = !$this->isDemo() && is_file($configFile) ? require $configFile : [];
        $this->superAdminIds = $config['super_admin_ids'] ?? [];
        $this->secret = $config['bma_secret_key'] ?? '';
        $endpoint = array_key_exists('bma_endpoint', $config) ? $config['bma_endpoint'] : self::BMA_DEFAULT_ENDPOINT;
        $parts = is_string($endpoint) ? parse_url($endpoint) : false;
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || !filter_var($endpoint, FILTER_VALIDATE_URL) || preg_match('~[\\x00-\\x20\\x7f\\\\]~', $endpoint)) {
            throw new \InvalidArgumentException('bma_endpoint must be an absolute HTTPS URL without credentials or a fragment.');
        }
        $this->endpoint = $endpoint;
    }

    public function isDemo(): bool
    {
        return in_array($this->environment, ['dev', 'test'], true);
    }

    public function isSuperAdmin(string $actorId): bool
    {
        return in_array($actorId, $this->superAdminIds, true);
    }

    public function bmaEndpoint(): string
    {
        return $this->endpoint;
    }

    public function bmaSecret(): string
    {
        return $this->secret;
    }
}
