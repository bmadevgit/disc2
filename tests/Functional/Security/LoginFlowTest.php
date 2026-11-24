<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Application\Auth\AuthSettings;
use App\Application\Auth\BmaDirectory;
use App\Kernel;
use App\Security\UserProvider;
use App\Tests\Integration\SyntheticDatabaseTestCase;
use PDO;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\HttpFoundation\Response;

final class LoginFlowTest extends SyntheticDatabaseTestCase
{
    private KernelBrowser $client;
    private PDO $db;
    private array $passwords;

    protected function setUp(): void
    {
        parent::setUp();
        $this->copiedFixture();
        $this->db = new PDO('sqlite:'.$this->path, options: [PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $this->passwords = json_decode(file_get_contents($this->directory.'/legacy/demo-accounts.json'), true, flags: JSON_THROW_ON_ERROR);
        $kernel = new Kernel('test', true);
        $kernel->boot();
        $kernel->getContainer()->get('test.service_container')->set(PDO::class, $this->db);
        $this->client = new KernelBrowser($kernel, ['HTTP_USER_AGENT' => 'Synthetic browser', 'REMOTE_ADDR' => '192.0.2.5']);
        $this->client->disableReboot();
    }

    protected function tearDown(): void
    {
        $this->client->getKernel()->shutdown();
        parent::tearDown();
    }

    public function testDemoLoginAndLogout(): void
    {
        $token = $this->csrf('/login.php');
        $original = $this->client->getCookieJar()->all()[0]->getValue();
        $this->attempt($token, 'demo-admin', $this->passwords['demo-admin']);
        $this->redirects('/index.php');
        self::assertNotSame($original, $this->client->getCookieJar()->all()[0]->getValue());
        $this->client->request('GET', '/login.php');
        $this->redirects('/admin.php');
        $logout = $this->csrf('/logout.php');
        $this->client->request('POST', '/logout.php', ['csrf' => 'expired']);
        self::assertSame(419, $this->response()->getStatusCode());
        $this->client->request('GET', '/login.php');
        $this->redirects('/admin.php');
        $this->client->request('POST', '/logout.php', ['csrf' => $logout]);
        $this->redirects('/login.php');
        $this->client->request('GET', '/logout.php');
        $this->redirects('/login.php');
        $this->attempt($token, 'demo-admin', $this->passwords['demo-admin']);
        self::assertSame(419, $this->response()->getStatusCode());
        $row = $this->db->query('SELECT * FROM login_log')->fetch();
        self::assertSame(['demo-admin', 'ผู้ดูแลระบบสมมติ', 'admin', '192.0.2.5', 'Synthetic browser', 'success'],
            array_map(fn ($key) => $row[$key], ['username', 'user_name', 'user_role', 'ip', 'user_agent', 'status']));
    }

    public function testFiveFailuresAcrossAccountsPerIpWithinFifteenMinutes(): void
    {
        $token = $this->csrf('/login.php');
        for ($i = 0; $i < 5; ++$i) {
            $this->attempt($token, 'unknown-'.$i, 'wrong');
            self::assertSame(200, $this->response()->getStatusCode());
        }
        $this->db->exec("UPDATE login_log SET created_at=datetime('now','-14 minutes')");
        $this->attempt($token, 'demo-owner', $this->passwords['demo-owner']);
        self::assertStringContainsString('15 นาที', $this->response()->getContent());
        self::assertSame(5, $this->db->query('SELECT COUNT(*) FROM login_log')->fetchColumn());
        $this->client->setServerParameter('REMOTE_ADDR', '192.0.2.6');
        $this->attempt($token, 'demo-owner', $this->passwords['demo-owner']);
        $this->redirects('/index.php');
        $logout = $this->csrf('/logout.php');
        $this->client->request('POST', '/logout.php', ['csrf' => $logout]);
        $this->client->setServerParameter('REMOTE_ADDR', '192.0.2.5');
        $this->db->exec("UPDATE login_log SET created_at=datetime('now','-16 minutes') WHERE status='fail'");
        $this->attempt($this->csrf('/login.php'), 'demo-owner', $this->passwords['demo-owner']);
        $this->redirects('/index.php');
    }

    public function testIisMountCookiesAndFreshLogin(): void
    {
        $mount = '/exam/disc2';
        $this->client->getCookieJar()->set(new Cookie('disc2_uat', 'old-session', null, '/', 'localhost', true));
        $this->client->setServerParameter('SCRIPT_FILENAME', 'C:/inetpub/wwwroot/exam/disc2/public/index.php');
        $this->client->setServerParameter('SCRIPT_NAME', $mount.'/index.php');
        $this->csrf('https://localhost'.$mount.'/login.php');
        $cookies = $this->response()->headers->getCookies();
        $current = array_values(array_filter($cookies, fn ($c) => !$c->isCleared()))[0];
        self::assertNotSame('disc2_uat', $current->getName());
        self::assertSame($mount, $current->getPath());
        self::assertTrue($current->isSecure());
        self::assertTrue($current->isHttpOnly());
        self::assertSame('lax', $current->getSameSite());
        self::assertTrue((bool)array_filter($cookies, fn ($c) => $c->getName() === 'disc2_uat' && $c->isCleared() && $c->getPath() === '/'));
        foreach ([[], ['csrf' => 'pre-cutover-token'], ['csrf' => ['invalid']]] as $fields) {
            $this->client->request('POST', 'https://localhost'.$mount.'/login.php', $fields + ['username' => 'demo-admin', 'password' => 'unused']);
            self::assertSame(419, $this->response()->getStatusCode());
        }
        self::assertSame(0, $this->db->query('SELECT COUNT(*) FROM login_log')->fetchColumn());
    }

    public function testAuthorizationRunsBeforeProtectedController(): void
    {
        $this->client->request('GET', '/_test/security/1');
        $this->redirects('/login.php');
        $this->attempt($this->csrf('/login.php'), 'demo-owner', $this->passwords['demo-owner']);
        $this->redirects('/index.php');
        $token = $this->csrf('/logout.php');
        $this->client->request('POST', '/_test/security/1', ['csrf' => 'expired']);
        self::assertSame(419, $this->response()->getStatusCode());
        $this->client->request('POST', '/_test/security/1', ['csrf' => $token]);
        self::assertSame(200, $this->response()->getStatusCode());
        $this->db->exec('UPDATE uat_access_assignments SET active=0');
        foreach ([1, 9999] as $caseId) {
            $this->client->request('POST', '/_test/security/'.$caseId, ['csrf' => $token]);
            self::assertSame(403, $this->response()->getStatusCode());
            self::assertSame('ไม่มีสิทธิ์', $this->response()->getContent());
        }
    }

    public function testLiveAdminRestrictsLocalAccounts(): void
    {
        $this->liveSettings();
        $token = $this->csrf('/login.php');
        $this->attempt($token, 'demo-reader', $this->passwords['demo-reader'], 'admin');
        self::assertSame(200, $this->response()->getStatusCode());
        $this->attempt($token, 'demo-admin', $this->passwords['demo-admin'], 'admin');
        $this->redirects('/admin.php');
    }

    public function testBmaMappingAndStableUnitWithExternalSuperAdmin(): void
    {
        $this->liveSettings();
        $directory = new class implements BmaDirectory {
            public array $record = ['LdapPersonalID' => 'synthetic-id', 'TitleTH' => ' A ', 'FirstNameTH' => 'B', 'LastNameTH' => 'C', 'UnitLevel1' => 'Unit'];
            public array $lookups = [];
            public function lookup(string $username): array {
                $this->lookups[] = $username;
                return $this->record;
            }
        };
        $this->client->getContainer()->set(BmaDirectory::class, $directory);
        $token = $this->csrf('/login.php');
        $this->attempt($token, 'staff@BANGKOK.GO.TH', 'wrong', 'bma');
        self::assertSame(200, $this->response()->getStatusCode());
        $this->attempt($token, 'staff@BANGKOK.GO.TH', 'synthetic-id', 'bma');
        $this->redirects('/admin.php');
        self::assertSame(['staff', 'staff'], $directory->lookups);
        $loaded = $this->client->getContainer()->get(UserProvider::class)->loadUserByIdentifier('bma:synthetic-id');
        self::assertSame('A B C', $loaded->name);
        self::assertSame('Unit', $loaded->sessionUnit);
        self::assertTrue($loaded->scope->canAdminister());
        self::assertSame(['staff@bangkok.go.th', 'A B C', 'user', 'success'], $this->db->query('SELECT username,user_name,user_role,status FROM login_log ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_NUM));
        $this->client->request('POST', '/logout.php', ['csrf' => $this->csrf('/logout.php')]);
        $directory->record['UnitLevel1'] = '';
        $this->attempt($this->csrf('/login.php'), 'staff', 'synthetic-id', 'bma');
        $this->redirects('/admin.php');
        self::assertSame('Unit', $this->db->query('SELECT unit_level1 FROM user_unit_assignments')->fetchColumn());
        $this->client->request('POST', '/logout.php', ['csrf' => $this->csrf('/logout.php')]);
        $directory->record['LdapPersonalID'] = 'different-id';
        $this->attempt($this->csrf('/login.php'), 'second', 'different-id', 'bma');
        self::assertSame(200, $this->response()->getStatusCode());
        self::assertSame('fail', $this->db->query('SELECT status FROM login_log ORDER BY id DESC LIMIT 1')->fetchColumn());
    }

    private function liveSettings(): void
    {
        $file = $this->directory.'/settings.php';
        file_put_contents($file, "<?php return ['super_admin_ids' => ['synthetic-id']];");
        $this->client->getContainer()->set(AuthSettings::class, new AuthSettings('prod', $file));
    }

    private function csrf(string $url): string
    {
        $page = $this->client->request('GET', $url);
        self::assertSame(200, $this->response()->getStatusCode());
        self::assertTrue($this->response()->headers->hasCacheControlDirective('no-store'));
        return $page->filter('input[name="csrf"]')->first()->attr('value');
    }

    private function redirects(string $target): void
    {
        self::assertSame(302, $this->response()->getStatusCode());
        self::assertSame($target, parse_url($this->response()->headers->get('Location'), PHP_URL_PATH));
        self::assertTrue($this->response()->headers->hasCacheControlDirective('no-store'));
    }

    private function attempt(string $csrf, string $username, string $password, string $login_type = 'demo'): void
    {
        $this->client->request('POST', '/login.php', compact('csrf', 'username', 'password', 'login_type'));
    }

    private function response(): Response
    {
        return $this->client->getResponse();
    }
}
