<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Application\Auth\AuthSettings;
use App\Application\Auth\BmaApiDirectory;
use App\Application\Auth\BmaDirectory;
use App\Kernel;
use App\Tests\Integration\SyntheticDatabaseTestCase;
use PDO;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;

final class BmaDirectoryTest extends SyntheticDatabaseTestCase
{
    public function testDirectoryContractAndHttpLoginFailureLimit(): void
    {
        file_put_contents($this->directory.'/config.php', "<?php return ['bma_secret_key' => 'synthetic-test-key'];");
        file_put_contents($this->directory.'/index.php', <<<'PHP'
            <?php
            if (!isset($_GET['canonical'])) {
                header('Location: /index.php?canonical=1', true, 307);
                exit;
            }
            if ($_POST['Email'] === 'outage') {
                http_response_code(503);
                exit;
            }
            if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $_SERVER['CONTENT_TYPE'] !== 'application/x-www-form-urlencoded'
                || $_SERVER['HTTP_ACCEPT'] !== 'application/json' || $_POST !== ['secretkey' => 'synthetic-test-key', 'Email' => 'staff']) {
                http_response_code(400);
                exit;
            }
            echo json_encode(['LdapPersonalID' => 'synthetic-id', 'UnitLevel1' => 'Synthetic unit']);
            PHP);
        $log = tmpfile();
        $server = proc_open([PHP_BINARY, '-S', '127.0.0.1:0', '-t', $this->directory], [1 => $log, 2 => $log], $pipes);
        self::assertIsResource($server);
        try {
            for ($i = 0; $i < 100; ++$i) {
                if (preg_match('~Development Server \((http://127\.0\.0\.1:\d+)\) started~', file_get_contents(stream_get_meta_data($log)['uri']), $match)) {
                    break;
                }
                usleep(20000);
            }
            self::assertArrayHasKey(1, $match);
            $settings = new AuthSettings('prod', $this->directory.'/config.php');
            $api = new BmaApiDirectory($settings, $match[1].'/index.php');
            self::assertSame(['LdapPersonalID' => 'synthetic-id', 'UnitLevel1' => 'Synthetic unit'], $api->lookup('staff'));
            $this->checkHttpFailureLimit($settings, $api);
            $this->expectException(CustomUserMessageAuthenticationException::class);
            $this->expectExceptionMessage('ไม่สามารถเชื่อมต่อระบบ BMA ได้');
            $api->lookup('outage');
        } finally {
            proc_terminate($server);
            proc_close($server);
            fclose($log);
        }
    }

    private function checkHttpFailureLimit(AuthSettings $settings, BmaApiDirectory $api): void
    {
        $this->copiedFixture();
        $db = new PDO('sqlite:'.$this->path, options: [PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $kernel = new Kernel('test', true);
        $kernel->boot();
        try {
            $client = new KernelBrowser($kernel, ['REMOTE_ADDR' => '192.0.2.10']);
            $client->disableReboot();
            $client->getContainer()->set(PDO::class, $db);
            $client->getContainer()->set(AuthSettings::class, $settings);
            $client->getContainer()->set(BmaDirectory::class, $api);
            $page = $client->request('GET', '/login.php');
            $csrf = $page->filter('input[name="csrf"]')->first()->attr('value');
            $attempt = static function (string $username) use ($client, $csrf): string {
                $client->request('POST', '/login.php', [
                    'login_type' => 'bma', 'username' => $username, 'password' => 'synthetic-id', 'csrf' => $csrf,
                ]);
                return $client->getResponse()->getContent();
            };
            self::assertStringContainsString('ไม่สามารถเชื่อมต่อระบบ BMA ได้', $attempt('outage'));
            self::assertSame(0, $db->query('SELECT COUNT(*) FROM login_log')->fetchColumn());
            for ($i = 0; $i < 5; ++$i) {
                self::assertStringContainsString('ชื่อบัญชีหรือรหัสผ่านไม่ถูกต้อง', $attempt('missing'));
            }
            self::assertStringContainsString('15 นาที', $attempt('staff'));
            self::assertSame(5, $db->query("SELECT COUNT(*) FROM login_log WHERE username='missing@bangkok.go.th' AND ip='192.0.2.10' AND status='fail'")->fetchColumn());
            $client->setServerParameter('REMOTE_ADDR', '192.0.2.11');
            $attempt('staff');
            self::assertSame(302, $client->getResponse()->getStatusCode());
        } finally {
            $kernel->shutdown();
        }
    }

    public function testMissingSecretFailsBeforeNetworkAccess(): void
    {
        $api = new BmaApiDirectory(new AuthSettings('prod', $this->directory.'/absent.php'), 'http://127.0.0.1:1');
        $this->expectException(CustomUserMessageAuthenticationException::class);
        $this->expectExceptionMessage('ยังไม่ได้ตั้งค่าการเข้าสู่ระบบ BMA');
        $api->lookup('staff');
    }
}
