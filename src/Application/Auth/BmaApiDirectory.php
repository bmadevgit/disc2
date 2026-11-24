<?php

declare(strict_types=1);

namespace App\Application\Auth;

use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;

final readonly class BmaApiDirectory implements BmaDirectory
{
    public function __construct(
        private AuthSettings $settings,
        private string $endpoint = 'https://register.bangkok.go.th/bmamailadmin/web/index.php?r=api/getuserinfo',
    ) {
    }

    public function lookup(string $username): array
    {
        $secret = $this->settings->bmaSecret();
        if ($secret === '') {
            throw new CustomUserMessageAuthenticationException('ยังไม่ได้ตั้งค่าการเข้าสู่ระบบ BMA');
        }
        $request = curl_init($this->endpoint);
        curl_setopt_array($request, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query(['secretkey' => $secret, 'Email' => $username]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
            CURLOPT_USERAGENT => 'Mozilla/5.0',
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        // Follow the directory's own redirects without forwarding the secret to another origin.
        $deadline = hrtime(true) + 15_000_000_000;
        for ($redirects = 0; $redirects <= 5; ++$redirects) {
            $remaining = intdiv($deadline - hrtime(true), 1_000_000);
            if ($remaining <= 0) {
                break;
            }
            curl_setopt($request, CURLOPT_TIMEOUT_MS, $remaining);
            $body = curl_exec($request);
            $status = curl_getinfo($request, CURLINFO_RESPONSE_CODE);
            if ($body === false || !in_array($status, [301, 302, 303, 307, 308], true)) {
                break;
            }
            $next = curl_getinfo($request, CURLINFO_REDIRECT_URL);
            if (!$next || $this->origin($next) !== $this->origin($this->endpoint)) {
                break;
            }
            curl_setopt($request, CURLOPT_URL, $next);
            if (in_array($status, [301, 302, 303], true)) {
                curl_setopt($request, CURLOPT_HTTPGET, true);
            }
        }
        if (!is_string($body ?? null) || $status !== 200) {
            throw new CustomUserMessageAuthenticationException('ไม่สามารถเชื่อมต่อระบบ BMA ได้ กรุณาลองใหม่หรือใช้ล็อกอินแบบ Admin');
        }
        $record = json_decode($body, true);
        if (!is_array($record)) {
            return [];
        }
        // The authentication contract accepts string fields only.
        return array_filter($record, 'is_string');
    }

    private function origin(string $url): array
    {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        return [$scheme, strtolower($parts['host'] ?? ''), $parts['port'] ?? ($scheme === 'https' ? 443 : 80)];
    }
}
