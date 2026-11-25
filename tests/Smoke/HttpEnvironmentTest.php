<?php

declare(strict_types=1);

namespace App\Tests\Smoke;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HttpEnvironmentTest extends TestCase
{
    public static function processEnvironments(): array
    {
        return [
            'test' => ['test', 200],
            'empty' => ['', 404],
            'unset' => [null, 404],
        ];
    }

    #[DataProvider('processEnvironments')]
    public function testFrontControllerWithGpcs(?string $environment, int $expectedStatus): void
    {
        $env = getenv();
        unset($env['APP_ENV']);
        if ($environment !== null) {
            $env['APP_ENV'] = $environment;
        }
        $env['APP_SECRET'] = 'synthetic-http-regression';

        $log = tmpfile();
        self::assertIsResource($log);
        $server = proc_open(
            [PHP_BINARY, '-d', 'variables_order=GPCS', '-S', '127.0.0.1:0', '-t', 'public', 'tools/dev-router.php'],
            [1 => $log, 2 => $log],
            $pipes,
            dirname(__DIR__, 2),
            $env
        );
        self::assertIsResource($server);
        try {
            $logPath = stream_get_meta_data($log)['uri'];
            for ($attempt = 0; $attempt < 100; ++$attempt) {
                if (preg_match('~Development Server \((http://127\.0\.0\.1:\d+)\) started~', file_get_contents($logPath), $match)) {
                    break;
                }
                if (!proc_get_status($server)['running']) {
                    break;
                }
                usleep(20000);
            }
            self::assertArrayHasKey(1, $match, 'PHP server did not start');

            $request = curl_init($match[1].'/runtime-smoke');
            curl_setopt_array($request, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
            $body = curl_exec($request);
            self::assertIsString($body, curl_error($request));
            self::assertSame($expectedStatus, curl_getinfo($request, CURLINFO_RESPONSE_CODE));
            if ($environment === 'test') {
                self::assertStringContainsString('<title>DISC2</title>', $body);
            }
        } finally {
            proc_terminate($server);
            proc_close($server);
            fclose($log);
        }
    }
}
