<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Application\Auth\AuthSettings;
use App\Application\Auth\BmaApiDirectory;
use App\Tests\Integration\SyntheticDatabaseTestCase;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;

final class BmaDirectoryTest extends SyntheticDatabaseTestCase
{
    public function testDirectoryPostsExpectedFormAndReportsUpstreamErrors(): void
    {
        file_put_contents($this->directory.'/config.php', "<?php return ['bma_secret_key' => 'synthetic-test-key'];");
        file_put_contents($this->directory.'/index.php', <<<'PHP'
            <?php
            if (!isset($_GET['canonical'])) {
                header('Location: /index.php?canonical=1', true, 307);
                exit;
            }
            if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $_SERVER['CONTENT_TYPE'] !== 'application/x-www-form-urlencoded'
                || $_SERVER['HTTP_ACCEPT'] !== 'application/json' || $_POST !== ['secretkey' => 'synthetic-test-key', 'Email' => 'staff']) {
                http_response_code(400);
                exit;
            }
            echo json_encode(['LdapPersonalID' => 'synthetic-id']);
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
            $api = new BmaApiDirectory(new AuthSettings('prod', $this->directory.'/config.php'), $match[1].'/index.php');
            self::assertSame(['LdapPersonalID' => 'synthetic-id'], $api->lookup('staff'));
            $this->expectException(CustomUserMessageAuthenticationException::class);
            $this->expectExceptionMessage('ไม่สามารถเชื่อมต่อระบบ BMA ได้');
            $api->lookup('missing');
        } finally {
            proc_terminate($server);
            proc_close($server);
            fclose($log);
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
