<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class ExposureTest extends TestCase
{
    private const INTERNAL = ['auth.php', 'case_comment2.php', 'case_discipline.php', 'case_listing.php', 'case_page4.php',
        'db.php', 'migrate.php', 'save_workflow.php', 'settings.php', 'ui.php', 'workflow.php'];

    public function testSourceDataAndConfigurationPathsAreDenied(): void
    {
        $root = dirname(__DIR__, 2);
        $log = tmpfile();
        $server = proc_open([PHP_BINARY, '-S', '127.0.0.1:0', '-t', 'public', 'tools/dev-router.php'],
            [1=>$log, 2=>$log], $pipes, $root, array_merge(getenv(), ['APP_ENV'=>'prod', 'APP_DEBUG'=>'0', 'APP_SECRET'=>'synthetic-exposure']));
        self::assertIsResource($server);
        try {
            for ($attempt = 0; $attempt < 100; ++$attempt) {
                if (preg_match('~Development Server \((http://127\.0\.0\.1:\d+)\) started~', file_get_contents(stream_get_meta_data($log)['uri']), $match)) {
                    break;
                }
                usleep(20000);
            }
            self::assertArrayHasKey(1, $match);
            $client = curl_init();
            curl_setopt_array($client, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>10]);
            foreach ([...self::INTERNAL, 'src/Kernel.php', 'config/services.yaml', 'config/bundles.php', 'templates/base.html.twig',
                'vendor/autoload.php', 'tools/init.php', 'tools/base-schema.sql', 'bin/console', 'tests/Legacy/run.php',
                'migrations/001_uat.sql', 'runtime/disc2-dev.sqlite', 'runtime/disc2-dev.sqlite-wal', 'runtime/demo-accounts.json',
                'private/disc2-ai.php', 'var/log/prod.log', '.git/config', '.env', 'composer.lock', 'web.config',
                'assets/database.sqlite', 'assets/config.php', '_test/security/1', 'runtime-smoke'] as $path) {
                curl_setopt($client, CURLOPT_URL, $match[1].'/'.$path);
                self::assertIsString(curl_exec($client), $path);
                self::assertSame(404, curl_getinfo($client, CURLINFO_RESPONSE_CODE), $path);
            }
            foreach (glob($root.'/public/assets/*') as $asset) {
                curl_setopt($client, CURLOPT_URL, $match[1].'/assets/'.basename($asset));
                self::assertSame(file_get_contents($asset), curl_exec($client), basename($asset));
                self::assertSame(200, curl_getinfo($client, CURLINFO_RESPONSE_CODE));
            }
        } finally {
            proc_terminate($server);
            proc_close($server);
            fclose($log);
        }
    }

    public function testSinglePublicEntryAndNoProceduralImports(): void
    {
        $root = dirname(__DIR__, 2);
        $entries = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/public')) as $file) {
            if ($file->isFile()) {
                self::assertFalse($file->isLink(), $file->getPathname());
                $entries[] = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($root.'/public/')));
            }
        }
        sort($entries);
        self::assertSame(['assets/login.css','assets/login.js','assets/uat.css','assets/workflow.js','index.php','web.config'], $entries);
        // These are the complete runtime imports, including indirect expressions.
        $imports = [
            'bin/console'=>["dirname(__DIR__).'/vendor/autoload_runtime.php'"],
            'public/index.php'=>["dirname(__DIR__).'/vendor/autoload_runtime.php'"],
            'src/Application/Auth/AuthSettings.php'=>['$configFile'],
            'src/Application/Query/DashboardAssistant.php'=>['$this->configFile'],
            'src/Infrastructure/Persistence/ConnectionFactory.php'=>['$this->configFile'],
            'tools/dev-router.php'=>["dirname(__DIR__).'/public/index.php'"],
            'tools/init.php'=>["dirname(__DIR__).'/vendor/autoload.php'"],
        ];
        $files = [$root.'/bin/console'];
        foreach (['src','config','public','tools'] as $dir) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$root/$dir")) as $file) {
                if ($file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }
        foreach ($files as $file) {
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($file, strlen($root) + 1));
            $actual = [];
            $expression = null;
            foreach (token_get_all(file_get_contents($file)) as $token) {
                if (is_array($token) && in_array($token[0], [T_INCLUDE,T_REQUIRE,T_INCLUDE_ONCE,T_REQUIRE_ONCE], true)) {
                    $expression = '';
                } elseif ($expression !== null) {
                    if ($token === ';' || $token === ':') {
                        $actual[] = $expression;
                        $expression = null;
                    } elseif (!is_array($token) || !in_array($token[0], [T_WHITESPACE,T_COMMENT,T_DOC_COMMENT], true)) {
                        $expression .= is_array($token) ? $token[1] : $token;
                    }
                }
            }
            self::assertSame($imports[$relative] ?? [], $actual, $relative);
        }
        self::assertSame([], glob($root.'/*.php'), 'Procedural runtime still present');
    }
}
