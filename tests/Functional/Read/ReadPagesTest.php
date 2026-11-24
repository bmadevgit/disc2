<?php

declare(strict_types=1);

namespace App\Tests\Functional\Read;

use App\Application\Auth\AuthSettings;
use App\Kernel;
use App\Security\UserProvider;
use App\Tests\Integration\SyntheticDatabaseTestCase;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

final class ReadPagesTest extends SyntheticDatabaseTestCase
{
    private KernelBrowser $client;
    private PDO $db;

    protected function setUp(): void
    {
        parent::setUp();
        $this->copiedFixture();
        $this->db = new PDO('sqlite:'.$this->path, options: [PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $this->db->exec("UPDATE uat_case_meta SET unit_level1='A',unit_level2='G',responsible_unit='Office A',stage='discipline';
            UPDATE uat_access_assignments SET unit_level1='A',unit_level2='G';
            INSERT INTO cases(id,เลขที่สำนวน) VALUES(2,'SECOND'),(3,'OUTSIDE');
            INSERT INTO uat_case_meta(case_id,unit_level1,unit_level2,responsible_unit,stage) VALUES(2,'A','H','Office B','closed'),(3,'B','G','Outside unit','closed');
            UPDATE uat_subjects SET title_th='HiddenTitle',first_name_th='HiddenName',last_name_th='HiddenSurname',national_id='0000000000000',position_name='HiddenPosition';
            UPDATE uat_subjects SET reference_code='' WHERE id=2;
            INSERT INTO uat_tracks(id,case_id,track_type,duration_days,first_meeting_date,start_date,due_date)
            VALUES(1,1,'fact',30,'2024-01-01','2024-01-01','2024-01-30'),(2,1,'discipline_serious',180,'2024-01-01','2024-01-01','2024-06-28');
            INSERT INTO uat_track_subjects(track_id,subject_id) VALUES(1,1),(2,1);
            INSERT INTO uat_subject_findings(track_id,subject_id,finding) VALUES(2,1,'guilty');
            INSERT INTO uat_extensions(track_id,sequence_no,requested_days,new_due_date) VALUES(2,1,2,'2024-06-30');
            INSERT INTO uat_committee_orders(track_id,event_type,order_number) VALUES(2,'appoint','ORDER-7')");
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

    private function actor(string $role): void
    {
        $name = $role === 'admin' ? 'demo-admin' : 'demo-owner';
        $services = $this->client->getKernel()->getContainer()->get('test.service_container');
        if (in_array($role, ['super','fallback'], true)) {
            $this->db->exec('UPDATE uat_access_assignments SET active=0');
            file_put_contents($this->directory.'/config.php', $role === 'super' ? "<?php return ['super_admin_ids'=>['2']];" : "<?php return [];");
            $services->set(AuthSettings::class, new AuthSettings('prod', $this->directory.'/config.php'));
            // Exercise the production BMA session refresh, including directory unit lookup.
            $this->db->exec("INSERT INTO user_unit_assignments(bma_personal_id,bma_email,unit_level1) VALUES('2','super','A')");
            $user = $services->get(UserProvider::class)->fromBma('2','Super','super','A');
        } else {
            if ($role === 'none') {
                $this->db->exec('UPDATE uat_access_assignments SET active=0');
            } elseif ($role !== 'admin') {
                $assignment = in_array($role, ['former','other'], true) ? 'case_owner' : $role;
                $this->db->prepare('UPDATE uat_access_assignments SET access_role=? WHERE bma_personal_id=2')->execute([$assignment]);
                if ($role === 'former' || $role === 'other') {
                    $this->db->exec("UPDATE uat_case_owners SET ended_at='2024-01-01'");
                }
                if ($role === 'other') {
                    $this->db->exec("INSERT INTO uat_case_owners(case_id,bma_personal_id,owner_name) VALUES(3,'2','Other')");
                }
            }
            $query = $this->db->prepare('SELECT * FROM users WHERE username=?');
            $query->execute([$name]);
            $user = $services->get(UserProvider::class)->fromLocal($query->fetch());
        }
        $this->client->loginUser($user);
    }

    private function page(string $url, int $status = 200): Crawler
    {
        $page = $this->client->request('GET', $url);
        $response = $this->client->getResponse();
        self::assertSame($status, $response->getStatusCode(), $url);
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        return $page;
    }

    private function redirect(string $url, string $destination): void
    {
        $this->page($url, 302);
        self::assertSame($destination, parse_url($this->client->getResponse()->headers->get('Location'), PHP_URL_PATH)
            .(str_contains($destination,'?') ? '?'.parse_url($this->client->getResponse()->headers->get('Location'), PHP_URL_QUERY) : ''));
    }

    public static function readers(): iterable
    {
        foreach (['admin','super','executive','unit_head','group_head','case_owner','fallback','former','other','none'] as $role) {
            yield $role => [$role];
        }
    }

    #[DataProvider('readers')]
    public function testScopeAcrossScreens(string $role): void
    {
        $this->actor($role);
        $admin = in_array($role, ['admin','super'], true);
        $aggregate = in_array($role, ['executive','unit_head'], true);
        $read = $admin || in_array($role, ['group_head','case_owner','fallback'], true);
        if ($aggregate) {
            $this->redirect('/index.php', '/admin.php');
        } else {
            $index = $this->page('/');
            self::assertCount((int)$read, $index->filter('td b')->reduce(fn ($c) => $c->text() === 'DEMO-001'));
            self::assertCount((int)($admin || $role === 'other'), $index->filter('td b')->reduce(fn ($c) => $c->text() === 'OUTSIDE'));
            self::assertCount((int)$admin, $index->filter('td b')->reduce(fn ($c) => $c->text() === 'SECOND'));
            self::assertCount((int)($admin || in_array($role, ['case_owner','fallback','former','other'], true)), $index->filter('#new-case-form'));
            self::assertCount(1, $index->filter('.nav-shell .profile-menu summary'));
            self::assertSame($role === 'admin' ? 'admin' : 'user', $index->filter('.profile-meta span')->text());
            if ($role === 'none') self::assertStringContainsString('ยังไม่ได้กำหนดสิทธิ์', $index->text());
        }
        if ($aggregate || $admin) {
            $dashboard = $this->page('/admin.php');
            self::assertSame($role === 'unit_head' ? ['2','1','1','2'] : ['3','1','2','2'], $dashboard->filter('.metric b')->each(fn ($c) => $c->text()));
            self::assertCount((int)$admin, $dashboard->filter('#ai-dashboard-button'));
            self::assertSame($admin, str_contains($dashboard->text(),'2024-06-30'));
        } else {
            $this->redirect('/admin.php', '/index.php');
        }
        $this->page('/roles.php', $admin ? 200 : 403);
        if ($admin) {
            $this->redirect('/users.php', '/roles.php');
        } else {
            $this->page('/users.php', 403);
        }
        $this->page('/case.php?id=2', $admin ? 200 : 403);
        $this->page('/case.php?id=3', $admin || $role === 'other' ? 200 : 403);
        $urls = ['/subject.php?case_id=1','/subject.php?case_id=1&subject_id=1','/track.php?case_id=1&type=discipline_serious','/discipline_results.php?case_id=1&type=discipline_serious'];
        foreach ([1,2,3,4] as $tab) $urls[] = '/case.php?id=1&page='.$tab;
        foreach ($urls as $url) {
            $page = $this->page($url, $read ? 200 : 403);
            if (!$admin) {
                foreach (['HiddenTitle','HiddenName','HiddenSurname','0000000000000','HiddenPosition'] as $secret) {
                    self::assertStringNotContainsString($secret, $this->client->getResponse()->getContent(), $url);
                }
            }
            if ($read && str_starts_with($url, '/subject.php')) {
                self::assertStringContainsString($admin ? 'HiddenName' : 'REF-DEMO-1', $page->text());
                if (!$admin) self::assertStringContainsString('ผู้ถูกกล่าวหารายที่ 2', $page->text());
            }
            if ($url === '/case.php?id=1&page=1' && ($admin || in_array($role, ['case_owner', 'fallback'], true))) {
                foreach (['title', 'first', 'last', 'position', 'national_id'] as $field) {
                    $input = $page->filter('#subjects input[name="subjects[0]['.$field.']"]');
                    self::assertSame(!$admin, $input->getNode(0)->hasAttribute('disabled'), $field);
                    self::assertSame($admin, $input->attr('value') !== '', $field);
                }
            }
            if ($role === 'group_head' && $read) self::assertCount(0, $page->filter('form[method=post]'));
        }
    }

    public function testAccessibleFiltersAndWorkflowPreviews(): void
    {
        $this->actor('case_owner');
        $index = $this->page('/index.php?q=ref-demo&unit=Office+A&type='.urlencode('การเงินและบัญชี').'&stage=discipline&finding=guilty');
        self::assertSame('DEMO-001', $index->filter('td b')->text());
        self::assertSame('ผิดวินัย 1 ราย', $index->filter('.workflow-alert')->text());
        self::assertCount(2, $index->filter('select[name=unit] option'));
        foreach (['q=HiddenName','unit=Missing','type=Missing','stage=closed','finding=ground'] as $query) {
            self::assertCount(0, $this->page('/index.php?'.$query)->filter('td b'));
        }
        $page = $this->page('/case.php?id=1&page=90&saved=1');
        self::assertStringContainsString('4 คำสั่ง', $page->filter('.tabs .active')->text());
        self::assertStringContainsString('บันทึกข้อมูล UAT แล้ว', $page->text());
        $track = $this->page('/track.php?case_id=1&type=discipline_serious');
        self::assertSame('180', $track->filter('input[name=duration_days][readonly]')->attr('value'));
        self::assertSame('2024-06-28', $track->filter('input[name=preview_due][readonly]')->attr('value'));
        foreach (['ORDER-7','2024-01-02','2024-04-30','2024-05-02'] as $text) self::assertStringContainsString($text, $track->text());
    }

    public function testUnscheduledSeriousDraftOffersConversionUntilAnExtensionExists(): void
    {
        $this->actor('case_owner');
        $this->db->exec("INSERT INTO uat_tracks(id,case_id,track_type,set_no,duration_days)
            VALUES(3,1,'discipline_serious',2,180)");
        $url = '/track.php?case_id=1&type=discipline_serious&track_id=3';
        $page = $this->page($url);
        $confirmation = $page->filter('details form');
        self::assertCount(1, $confirmation);
        self::assertSame('convert_serious_deadline', $confirmation->filter('input[name=action]')->attr('value'));
        self::assertSame('3', $confirmation->filter('input[name=track_id]')->attr('value'));
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $confirmation->filter('input[name=revision]')->attr('value'));
        self::assertCount(1, $confirmation->filter('input[name=reason][required]'));
        self::assertSame(['วันเริ่มนับ ยังไม่ระบุ → ยังไม่ระบุ', 'วันครบกำหนด ยังไม่ระบุ → ยังไม่ระบุ'],
            $page->filter('details p')->each(fn ($line) => $line->text()));
        self::assertStringNotContainsString('ไม่สามารถคำนวณกำหนดเวลา', $page->text());

        $this->db->exec("INSERT INTO uat_extensions(track_id,sequence_no,requested_days) VALUES(3,1,2)");
        $page = $this->page($url);
        self::assertCount(0, $page->filter('input[value=convert_serious_deadline]'));
        self::assertStringContainsString('ไม่สามารถคำนวณกำหนดเวลา', $page->text());
    }

    public function testStoredSeriousRoundRemainsReadableWhenConversionPreviewIsUnavailable(): void
    {
        $this->actor('case_owner');
        // Omitting requested_days reproduces the zero-day default in the original schema.
        $this->db->exec("INSERT INTO uat_extensions(track_id,sequence_no,new_due_date,reason)
            VALUES(2,2,'2024-06-30','Archived extension')");
        $page = $this->page('/track.php?case_id=1&type=discipline_serious&track_id=2');
        self::assertSame('2024-01-01', $page->filter('input[name=start_date]')->attr('value'));
        self::assertSame('180', $page->filter('input[name=duration_days]')->attr('value'));
        self::assertSame('2024-06-28', $page->filter('input[name=preview_due]')->attr('value'));
        self::assertStringContainsString('ORDER-7', $page->text());
        $record = $page->filter('tbody tr')->last();
        self::assertSame(['2','0','','Archived extension','2024-06-30'], $record->filter('td')->each(fn ($cell) => $cell->text()));
        self::assertStringContainsString('ไม่สามารถคำนวณกำหนดเวลา 120 วัน', $page->text());
        self::assertCount(0, $page->filter('input[value=convert_serious_deadline]'));
    }

    public function testPriorRoundDetailsAndScopedCommitteeChoices(): void
    {
        $this->actor('case_owner');
        $this->db->exec("INSERT INTO uat_tracks(id,case_id,track_type,set_no) VALUES(3,1,'discipline_nonserious',1),(4,1,'discipline_serious',2),(5,2,'discipline_serious',1);
            INSERT INTO uat_track_subjects(track_id,subject_id,ended_at) VALUES(3,1,'2024-01-03'),(3,2,NULL);
            INSERT INTO uat_subject_findings(track_id,subject_id,finding,recommend_serious,committee_opinion) VALUES(3,2,'guilty',1,'Recommend');
            INSERT INTO uat_committee_orders(track_id,event_type,order_number) VALUES(4,'appoint','Actual appointment'),(5,'appoint','Outside appointment');
            INSERT INTO uat_subject_status_history(subject_id,status_name,effective_date) VALUES(1,'Retired','2023-12-31')");
        $snapshot = ['finding'=>['committee_opinion'=>'Previous opinion'], 'decisions'=>[['commander_decision'=>'Previous decision',
            'reports'=>[['sequence_no'=>1,'report_date'=>'2024-02-01','letter_number'=>'Archived report','resolution_text'=>'','resolution_action'=>'','note'=>'']]]],
            'orders'=>[['event_type'=>'appoint','order_number'=>'Old appointment','order_date'=>'2024-01-02','detail'=>'']]];
        $this->db->prepare("INSERT INTO uat_escalations(subject_id,from_track_id,to_track_id,effective_date,reference,snapshot,actor_id,actor_name)
            VALUES(1,3,2,'2024-01-03','Transfer ref',?,'2','Owner')")->execute([json_encode($snapshot, JSON_THROW_ON_ERROR)]);
        $history = $this->page('/subject?case_id=1&subject_id=1')->text();
        foreach (['Previous decision','Archived report','Previous opinion','Old appointment','Transfer ref','Retired'] as $text) {
            self::assertStringContainsString($text, $history);
        }
        $results = $this->page('/discipline_results?case_id=1&type=discipline_nonserious');
        self::assertCount(0, $results->filter('article')->first()->filter('form'));
        self::assertStringContainsString('สิ้นสุดรอบนี้แล้ว', $results->filter('article')->first()->text());
        $select = $results->filter('select[name=target_track]');
        self::assertStringContainsString('Actual appointment', $select->text());
        self::assertStringNotContainsString('Outside appointment', $select->text());
        self::assertSame(['','2','4','new'], $select->filter('option')->each(fn ($option) => $option->attr('value')));
    }

    public function testDeadlineWindowUsesLatestExtensionAndBangkokCalendarDays(): void
    {
        $this->actor('admin');
        $today = new \DateTimeImmutable('today', new \DateTimeZone('Asia/Bangkok'));
        $update = $this->db->prepare('UPDATE uat_extensions SET new_due_date=? WHERE track_id=2');
        $update->execute([$today->modify('+31 days')->format('Y-m-d')]);
        self::assertCount(1, $this->page('/admin')->filter('td.status'));
        foreach ([30=>'เหลือ 30 วัน', 0=>'เหลือ 0 วัน', -1=>'เลย 1 วัน'] as $days=>$expected) {
            $update->execute([$today->modify(sprintf('%+d days', $days))->format('Y-m-d')]);
            self::assertSame($expected, $this->page('/admin')->filter('td.status')->last()->text());
        }
    }

    public function testMalformedBaseAndLatestExtensionAreIsolatedOnDashboard(): void
    {
        $this->actor('admin');
        $this->db->exec("UPDATE uat_tracks SET due_date='missing-date' WHERE id=1;
            INSERT INTO uat_extensions(track_id,sequence_no,new_due_date) VALUES(2,2,'2024-02-31');
            INSERT INTO uat_tracks(case_id,track_type,due_date) VALUES(2,'fact','2024-02-29')");
        $page = $this->page('/admin.php');
        self::assertSame(['3','1','2','2'], $page->filter('.metric b')->each(fn ($cell) => $cell->text()));
        self::assertSame(['2024-02-29'], $page->filter('tbody tr td:nth-child(3)')->each(fn ($cell) => $cell->text()));
    }

    public function testCalendarDistributionUsesOnlyMountedLocalAssets(): void
    {
        $this->actor('case_owner');
        $mount = '/exam/disc2';
        $this->client->setServerParameter('SCRIPT_FILENAME', 'C:/inetpub/wwwroot/exam/disc2/public/index.php');
        $this->client->setServerParameter('SCRIPT_NAME', $mount.'/index.php');
        $page = $this->page($mount.'/case.php?id=1&page=2');
        $resources = $page->filter('link[rel=stylesheet],script[src]')->each(fn ($tag) => $tag->attr('href') ?? $tag->attr('src'));
        foreach ($resources as $url) {
            self::assertStringStartsWith($mount.'/assets/', $url);
        }
        foreach (['flatpickr-4.6.13.min.css', 'flatpickr-4.6.13.min.js', 'flatpickr-4.6.13-th.js'] as $asset) {
            self::assertContains($mount.'/assets/'.$asset, $resources);
        }
    }

    public function testInvalidCaseIdentifiersAndLegacyTabDefaults(): void
    {
        $this->actor('admin');
        foreach (['case.php?id=oops','subject.php?case_id=oops','track.php?case_id=oops&type=discipline_serious',
            'discipline_results.php?case_id=oops&type=discipline_serious'] as $path) {
            $this->page('/'.$path, 403);
        }
        $tab = $this->page('/case?id=1&page=unknown');
        self::assertSame('1 ข้อมูลสำนวน', $tab->filter('.tabs .active')->text());
        $this->db->exec('DELETE FROM uat_case_meta WHERE case_id=3');
        $legacy = $this->page('/case.php?id=3');
        self::assertStringContainsString('ข้อมูลเดิมยังไม่มีผู้ถูกกล่าวหา', $legacy->text());
    }

    public function testAnonymousMountedAliasesAndErrors(): void
    {
        $this->client->setServerParameter('SCRIPT_FILENAME', 'C:/inetpub/wwwroot/exam/disc2/public/index.php');
        $this->client->setServerParameter('SCRIPT_NAME', '/exam/disc2/index.php');
        $mount = '/exam/disc2';
        $this->redirect($mount.'/i.php', $mount.'/index.php');
        foreach (['index.php','admin.php','case.php','subject.php','track.php','discipline_results.php','profile.php','edit.php','view_case.php','users.php','roles.php'] as $path) {
            $this->redirect($mount.'/'.$path, $mount.'/login.php');
        }
        $accounts = json_decode(file_get_contents($this->directory.'/legacy/demo-accounts.json'), true, flags: JSON_THROW_ON_ERROR);
        $login = $this->page($mount.'/login.php');
        $this->client->submit($login->filter('form')->form(['username'=>'demo-admin', 'password'=>$accounts['demo-admin']]));
        foreach (['edit.php','view_case.php'] as $path) $this->redirect($mount.'/'.$path.'?id=7', $mount.'/case.php?id=7');
        $this->redirect($mount.'/profile.php', $mount.'/index.php');
        $this->redirect($mount.'/users.php', $mount.'/roles.php');
        foreach (['case.php'=>403,'case.php?id=999'=>404,'subject.php?case_id=1&subject_id=999'=>404,'track.php?case_id=1&type=fact'=>403,
            'discipline_results.php?case_id=1&type=discipline_serious&track_id=1'=>404] as $path=>$code) $this->page($mount.'/'.$path,$code);
        $index = $this->page($mount.'/index.php?q=HiddenName');
        self::assertCount(1, $index->filter('td b'));
        self::assertSame($mount.'/assets/uat.css?v=profile-menu', $index->filter('link[rel=stylesheet]')->first()->attr('href'));
        self::assertSame($mount.'/index.php', $index->filter('a.brand')->attr('href'));
    }
}
