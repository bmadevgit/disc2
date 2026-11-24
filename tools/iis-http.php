<?php

declare(strict_types=1);

// Probe the IP selected from the local IIS bindings while preserving the certificate hostname and SNI.
if (PHP_SAPI !== 'cli' || $argc !== 3) {
    exit(1);
}
try {
    $base = rtrim($argv[1], '/').'/';
    $ip = $argv[2];
    $parts = parse_url($base);
    if (($parts['scheme'] ?? '') !== 'https' || ($parts['path'] ?? '') !== '/exam/disc2/' || !filter_var($ip, FILTER_VALIDATE_IP)) {
        throw new RuntimeException('Expected a mounted HTTPS URL and local binding IP.');
    }
    $host = trim($parts['host'], '[]');
    $port = $parts['port'] ?? 443;
    $manifest = json_decode(file_get_contents(dirname(__DIR__).'/release-manifest.json'), true, flags: JSON_THROW_ON_ERROR);
    $client = curl_init();
    curl_setopt_array($client, [CURLOPT_PROXY=>'', CURLOPT_FOLLOWLOCATION=>false, CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2,
        CURLOPT_COOKIEFILE=>'', CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>15, CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS]);
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        if (inet_pton($host) !== inet_pton($ip)) {
            throw new RuntimeException('IP literal differs from selected binding address.');
        }
    } else {
        $address = str_contains($ip, ':') ? '['.$ip.']' : $ip;
        curl_setopt($client, CURLOPT_RESOLVE, ["$host:$port:$address"]);
    }
    $request = static function (string $path, array $statuses, ?string $post = null) use ($client, $ip, $base): string {
        curl_setopt($client, CURLOPT_URL, $base.$path);
        if ($post === null) {
            curl_setopt($client, CURLOPT_HTTPGET, true);
        } else {
            curl_setopt($client, CURLOPT_POSTFIELDS, $post);
        }
        $body = curl_exec($client);
        $status = curl_getinfo($client, CURLINFO_RESPONSE_CODE);
        if (!is_string($body) || inet_pton(curl_getinfo($client, CURLINFO_PRIMARY_IP) ?: '0.0.0.0') !== inet_pton($ip) || !in_array($status, $statuses, true)) {
            throw new RuntimeException("Bound HTTPS request failed at $path ($status).");
        }
        if ($status === 302 && curl_getinfo($client, CURLINFO_REDIRECT_URL) !== $base.'login.php') {
            throw new RuntimeException('Unexpected mounted login redirect.');
        }
        return $body;
    };
    $login = $request('login.php?login_type=admin', [200]);
    if (!preg_match('/name="csrf" value="([^"]+)"/', $login, $token) || !str_contains($login, 'id="form-admin"') || !str_contains($login, '/exam/disc2/assets/login.css')) {
        throw new RuntimeException('Production login or asset mount failed.');
    }
    $cookies = curl_getinfo($client, CURLINFO_COOKIELIST);
    if (!array_filter($cookies, static function (string $cookie): bool {
        $fields = explode("\t", $cookie);
        return count($fields) === 7 && str_starts_with($fields[0], '#HttpOnly_') && $fields[2] === '/exam/disc2'
            && $fields[3] === 'TRUE' && $fields[5] === 'DISC2SFSESSID';
    })) {
        throw new RuntimeException('Check session cookie scope, Secure and HttpOnly flags.');
    }
    $request('case.php?id=1', [302]);
    $request('logout.php', [419], 'csrf=invalid');
    $request('logout.php', [302], http_build_query(['csrf'=>$token[1]]));
    foreach (['login.css','login.js','uat.css','workflow.js'] as $asset) {
        if (!hash_equals($manifest['files']['public/assets/'.$asset], hash('sha256', $request('assets/'.$asset, [200])))) {
            throw new RuntimeException("Static asset mismatch: $asset");
        }
    }
    foreach (['vendor/autoload.php','config/services.yaml','templates/base.html.twig','tools/iis-preflight.php','runtime/disc2-dev.sqlite','.env','var/log/prod.log','migrations/001_uat.sql','web.config'] as $private) {
        $request($private, [403,404]);
    }
    echo "Mounted HTTPS session, routes, assets and private paths verified.\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage()."\n");
    exit(1);
}
