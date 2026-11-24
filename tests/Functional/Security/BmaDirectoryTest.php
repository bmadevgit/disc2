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
    public function testExternalEndpointIsOptionalAndRejectsUnsafeDestinations(): void
    {
        $config = $this->directory.'/external.php';
        self::assertSame('https://register.bangkok.go.th/bmamailadmin/web/index.php?r=api/getuserinfo', (new AuthSettings('prod', $config))->bmaEndpoint());
        foreach (['http://stage.example/lookup', 'https://name:pw@stage.example/', 'https://stage.example/#key', "https://stage.example/\\other", "https://stage.example/\nheader"] as $url) {
            file_put_contents($config, '<?php return '.var_export(['bma_endpoint'=>$url], true).';');
            try {
                new AuthSettings('prod', $config);
                self::fail('Unsafe external endpoint accepted');
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('bma_endpoint', $e->getMessage());
            }
        }
    }

    public function testDirectoryContractAndHttpLoginFailureLimit(): void
    {
        $certFile = $this->directory.'/localhost.pem';
        $keyFile = $this->directory.'/localhost.key';
        $ini = $this->directory.'/openssl.cnf';
        file_put_contents($ini, "[req]\ndistinguished_name=dn\n[dn]\n[extensions]\nsubjectAltName=IP:127.0.0.1\nbasicConstraints=CA:TRUE\n");
        $options = ['config'=>$ini, 'digest_alg'=>'sha256', 'private_key_type'=>OPENSSL_KEYTYPE_RSA, 'private_key_bits'=>2048];
        $key = openssl_pkey_new($options);
        $csr = openssl_csr_new(['commonName'=>'localhost'], $key, $options);
        openssl_x509_export_to_file(openssl_csr_sign($csr, null, $key, 1, $options + ['x509_extensions'=>'extensions']), $certFile);
        openssl_pkey_export_to_file($key, $keyFile, null, $options);
        $server = proc_open([PHP_BINARY, dirname(__DIR__, 2).'/Fixtures/bma-https-server.php', $certFile, $keyFile], [1=>['pipe','w'], 2=>STDERR], $pipes);
        self::assertIsResource($server);
        try {
            stream_set_timeout($pipes[1], 5);
            $endpoint = trim(fgets($pipes[1])).'/index.php';
            self::assertStringStartsWith('https://127.0.0.1:', $endpoint);
            file_put_contents($this->directory.'/config.php', '<?php return '.var_export(['bma_secret_key'=>'synthetic-test-key', 'bma_endpoint'=>$endpoint], true).';');
            $settings = new AuthSettings('prod', $this->directory.'/config.php');
            $api = new BmaApiDirectory($settings, $certFile);
            self::assertSame(['LdapPersonalID' => 'synthetic-id', 'UnitLevel1' => 'Synthetic unit'], $api->lookup('staff'));
            $this->checkHttpFailureLimit($settings, $api);
            $this->expectException(CustomUserMessageAuthenticationException::class);
            $this->expectExceptionMessage('ไม่สามารถเชื่อมต่อระบบ BMA ได้');
            $api->lookup('outage');
        } finally {
            proc_terminate($server);
            fclose($pipes[1]);
            proc_close($server);
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
        $api = new BmaApiDirectory(new AuthSettings('prod', $this->directory.'/absent.php'));
        $this->expectException(CustomUserMessageAuthenticationException::class);
        $this->expectExceptionMessage('ยังไม่ได้ตั้งค่าการเข้าสู่ระบบ BMA');
        $api->lookup('staff');
    }
}
