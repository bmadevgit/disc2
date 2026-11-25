<?php

declare(strict_types=1);

namespace App\Security;

use Closure;
use InvalidArgumentException;
use Symfony\Component\DependencyInjection\EnvVarProcessorInterface;

/** Resolve the private deployment settings when the kernel boots, including on a warmed cache. */
final class RequestTrustSettings implements EnvVarProcessorInterface
{
    public static function getProvidedTypes(): array
    {
        return ['disc2_hosts'=>'string', 'disc2_proxies'=>'string'];
    }

    public function getEnv(string $prefix, string $name, Closure $getEnv): string
    {
        $file = $getEnv($name);
        $config = is_file($file) ? require $file : [];
        $key = $prefix === 'disc2_hosts' ? 'trusted_hosts' : 'trusted_proxies';
        if (!is_array($config)) {
            throw new InvalidArgumentException('External configuration must return an array.');
        }
        $values = array_key_exists($key, $config) ? $config[$key] : [];
        if (!is_array($values) || !array_is_list($values)) {
            throw new InvalidArgumentException($key.' must be a list.');
        }
        $trusted = [];
        foreach ($values as $value) {
            if (!is_string($value) || $value === '') {
                throw new InvalidArgumentException($key.' entries must be nonempty strings.');
            }
            if ($prefix === 'disc2_hosts') {
                $ipv6 = str_starts_with($value, '[') && str_ends_with($value, ']')
                    && filter_var(substr($value, 1, -1), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6);
                if (!$ipv6 && !filter_var($value, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
                    throw new InvalidArgumentException('trusted_hosts requires exact hostnames or IP literals.');
                }
                $trusted[] = '^'.preg_quote($value, '#').'$';
            } else {
                [$ip, $bits] = array_pad(explode('/', $value, 2), 2, null);
                if (!filter_var($ip, FILTER_VALIDATE_IP) || ($bits !== null
                    && (!ctype_digit($bits) || (int)$bits < 1 || (int)$bits > 8 * strlen(inet_pton($ip))))) {
                    throw new InvalidArgumentException('trusted_proxies requires IP addresses or nonzero, valid CIDR prefixes.');
                }
                $trusted[] = $value;
            }
        }
        return implode(',', $trusted);
    }
}
