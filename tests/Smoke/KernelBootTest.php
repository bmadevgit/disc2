<?php

declare(strict_types=1);

namespace App\Tests\Smoke;

use App\Kernel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class KernelBootTest extends TestCase
{
    public static function environments(): array
    {
        return [['dev', true], ['test', true], ['prod', false]];
    }

    #[DataProvider('environments')]
    public function testKernelBoots(string $environment, bool $debug): void
    {
        $kernel = new Kernel($environment, $debug);
        try {
            $kernel->boot();
            self::assertTrue($kernel->getContainer()->isCompiled());
            self::assertSame($environment, $kernel->getContainer()->getParameter('kernel.environment'));
        } finally {
            $kernel->shutdown();
        }
    }

    public function testRequiredExtensions(): void
    {
        foreach (['pdo_sqlite', 'curl', 'mbstring', 'intl'] as $extension) {
            self::assertTrue(extension_loaded($extension), "PHP requires ext-$extension");
        }
    }

    public function testIisApplicationMount(): void
    {
        $kernel = new Kernel('test', true);
        $request = Request::create('/exam/disc2/index.php?sample=1', 'GET', server: [
            'SCRIPT_FILENAME' => 'C:/inetpub/wwwroot/exam/disc2/public/index.php',
            'SCRIPT_NAME' => '/exam/disc2/index.php',
            'PHP_SELF' => '/exam/disc2/index.php',
            'IIS_WasUrlRewritten' => '1',
            'UNENCODED_URL' => '/exam/disc2/runtime-smoke?sample=1',
        ]);
        try {
            $response = $kernel->handle($request);
            self::assertSame(200, $response->getStatusCode());
            self::assertSame('runtime_smoke', $request->attributes->get('_route'));
            self::assertSame('1', $request->query->get('sample'));
            self::assertSame('/exam/disc2/runtime-smoke', $kernel->getContainer()->get('router')->generate('runtime_smoke'));
            $kernel->terminate($request, $response);
        } finally {
            $kernel->shutdown();
        }
    }

    public function testIisRulesPreserveApplicationRelativeRouting(): void
    {
        $config = simplexml_load_file(dirname(__DIR__, 2).'/public/web.config');
        self::assertNotFalse($config);
        $rules = $config->xpath('//rewrite/rules/rule');
        $front = end($rules);
        self::assertSame('Rewrite', (string) $front->action['type']);
        self::assertSame('index.php', (string) $front->action['url']);
        self::assertSame('true', (string) $front->action['appendQueryString']);
        self::assertSame(1, preg_match('~'.$front->match['url'].'~', ''));
        self::assertSame(1, preg_match('~'.$front->match['url'].'~', 'login.php'));
        foreach (['.sqlite', '.db', '.sql', '.log', '.env'] as $extension) {
            self::assertCount(1, $config->xpath("//fileExtensions/add[@fileExtension='$extension' and @allowed='false']"));
        }
        self::assertNotEmpty($config->xpath("//rules/rule[action[@type='None']]/conditions/add[@matchType='IsFile']"));
    }
}
