<?php

declare(strict_types=1);

namespace App\Tests\Functional\Write;

use App\Kernel;
use App\Security\UserProvider;
use App\Tests\Integration\SyntheticDatabaseTestCase;
use PDO;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;

abstract class WriteTestCase extends SyntheticDatabaseTestCase
{
    protected KernelBrowser $client;
    protected PDO $db;
    protected string $csrf;

    protected function setUp(): void
    {
        parent::setUp();
        $this->copiedFixture();
        $this->db = new PDO('sqlite:'.$this->path, options: [PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $this->db->exec('PRAGMA foreign_keys=ON');
        $kernel = new Kernel('test', true);
        $kernel->boot();
        $kernel->getContainer()->get('test.service_container')->set(PDO::class, $this->db);
        $this->client = new KernelBrowser($kernel);
        $this->client->disableReboot();
    }

    protected function tearDown(): void
    {
        $this->client->getKernel()->shutdown();
        parent::tearDown();
    }

    protected function login(string $name): void
    {
        $query = $this->db->prepare('SELECT * FROM users WHERE username=?');
        $query->execute([$name]);
        $services = $this->client->getKernel()->getContainer()->get('test.service_container');
        $this->client->loginUser($services->get(UserProvider::class)->fromLocal($query->fetch()));
        $page = $this->client->request('GET', '/logout.php');
        $this->csrf = $page->filter('input[name="csrf"]')->attr('value');
    }

    protected function post(array $fields, int $expected, string $path = '/save_case.php'): Response
    {
        $this->client->request('POST', $path, $fields + ['csrf'=>$this->csrf]);
        $response = $this->client->getResponse();
        self::assertSame($expected, $response->getStatusCode());
        return $response;
    }

    protected function rows(string $sql): array
    {
        return $this->db->query($sql)->fetchAll();
    }

    protected function state(): array
    {
        $state = [];
        foreach (['cases','uat_case_meta','uat_case_owners','uat_subjects','uat_subject_status_history','uat_access_assignments','uat_audit_log'] as $table) {
            $state[$table] = $this->rows("SELECT * FROM $table ORDER BY rowid");
        }
        return $state;
    }

    protected function failAudit(): void
    {
        $this->db->exec("CREATE TRIGGER fail_audit BEFORE INSERT ON uat_audit_log BEGIN SELECT RAISE(ABORT,'synthetic audit failure'); END");
    }
}
