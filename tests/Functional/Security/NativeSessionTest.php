<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Tests\Integration\SyntheticDatabaseTestCase;

/** Exercise native PHP sessions with the production front controller and a disposable external configuration. */
final class NativeSessionTest extends SyntheticDatabaseTestCase
{
    public function testFreshNativeSessionAndLogout(): void
    {
        $this->copiedFixture();
        $config = $this->directory.'/config.php';
        file_put_contents($config, '<?php return '.var_export(['database_path' => $this->path], true).';');
        $env = array_merge(getenv(), [
            'APP_ENV' => 'prod', 'APP_DEBUG' => '0', 'APP_SECRET' => 'synthetic-native-session',
            'DISC2_CONFIG_FILE' => $config,
        ]);
        $log = tmpfile();
        $server = proc_open([PHP_BINARY, '-S', '127.0.0.1:0', '-t', 'public', 'tools/dev-router.php'],
            [1 => $log, 2 => $log], $pipes, $this->root, $env);
        self::assertIsResource($server);
        try {
            for ($i = 0; $i < 100; ++$i) {
                if (preg_match('~Development Server \((http://127\.0\.0\.1:\d+)\) started~', file_get_contents(stream_get_meta_data($log)['uri']), $address)) {
                    break;
                }
                usleep(20000);
            }
            self::assertArrayHasKey(1, $address);
            $client = curl_init();
            curl_setopt_array($client, [CURLOPT_COOKIEFILE => '', CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 5]);
            $send = function (string $path, ?array $fields = null) use ($address, $client): string {
                curl_setopt($client, CURLOPT_URL, $address[1].$path);
                if ($fields === null) {
                    curl_setopt($client, CURLOPT_HTTPGET, true);
                } else {
                    curl_setopt($client, CURLOPT_POSTFIELDS, http_build_query($fields));
                }
                $result = curl_exec($client);
                self::assertIsString($result, curl_error($client));
                return $result;
            };
            $form = $send('/login.php');
            self::assertSame(200, curl_getinfo($client, CURLINFO_RESPONSE_CODE));
            self::assertMatchesRegularExpression('~Set-Cookie: DISC2SFSESSID=[^;]+; path=/; httponly; samesite=lax~i', $form);
            self::assertSame(1, preg_match('~name="csrf" value="([^"]+)"~', $form, $csrf));
            $passwords = json_decode(file_get_contents($this->directory.'/legacy/demo-accounts.json'), true, flags: JSON_THROW_ON_ERROR);
            $login = $send('/login.php', ['csrf' => $csrf[1], 'username' => 'demo-admin', 'password' => $passwords['demo-admin'], 'login_type' => 'admin']);
            self::assertSame(302, curl_getinfo($client, CURLINFO_RESPONSE_CODE));
            self::assertStringContainsString('Location: /admin.php', $login);
            self::assertStringContainsString('Location: /admin.php', $send('/login.php'));
            self::assertSame(1, preg_match('~name="csrf" value="([^"]+)"~', $send('/logout.php'), $logout));
            self::assertStringContainsString('Location: /login.php', $send('/logout.php', ['csrf' => $logout[1]]));
            $send('/login.php', ['csrf' => $csrf[1]]);
            self::assertSame(419, curl_getinfo($client, CURLINFO_RESPONSE_CODE));
        } finally {
            proc_terminate($server);
            proc_close($server);
            fclose($log);
        }
    }
}
