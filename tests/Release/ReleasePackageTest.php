<?php

declare(strict_types=1);

namespace App\Tests\Release;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use ZipArchive;

final class ReleasePackageTest extends TestCase
{
    private string $temp;

    protected function setUp(): void
    {
        $this->temp = sys_get_temp_dir().'/disc2-release-test-'.bin2hex(random_bytes(8));
        mkdir($this->temp);
        require_once dirname(__DIR__, 2).'/tools/release-package.php';
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->temp);
    }

    public function testProductionArchiveIsRepeatableAndVerified(): void
    {
        $files = $this->stage();
        $first = $this->temp.'/first.zip';
        $second = $this->temp.'/second.zip';
        $commit = str_repeat('1', 40);
        \Disc2Release::pack($files, $first, 'v1-test', $commit);
        \Disc2Release::pack(array_reverse($files, true), $second, 'v1-test', $commit);
        self::assertSame(hash_file('sha256', $first), hash_file('sha256', $second));
        $manifest = \Disc2Release::verify($first);
        self::assertSame($commit, $manifest['commit']);
        self::assertSame(hash_file('sha256', $files['composer.lock']), $manifest['files']['composer.lock']);
        foreach (['bin/console','config/routes.yaml','migrations/001_uat.sql','public/index.php','src/Kernel.php','templates/base.html.twig','vendor/autoload_runtime.php','tools/iis-smoke.ps1'] as $required) {
            self::assertArrayHasKey($required, $manifest['files']);
        }
        $zip = new ZipArchive();
        self::assertTrue($zip->open($first));
        $zip->addFromString('public/index.php', 'changed');
        $zip->close();
        $this->expectExceptionMessage('digest');
        \Disc2Release::verify($first);
    }

    public function testRejectsForbiddenEntriesIncludingMisplacedData(): void
    {
        foreach (['index.php','public/case.php','tests/X.php','runtime/db','config/.env.prod','config/secrets/prod/key.php','src/backup.sqlite-wal','vendor/pkg/test/data.db','var/log/prod.log','vendor/foo/bar/Tests/X.php','tools/init.php','vendor/phpunit/phpunit/phpunit','../config.php','public/index.php:ads'] as $name) {
            self::assertFalse(\Disc2Release::allowed($name, ['acme/pkg']), $name);
        }
    }

    public function testLockedVendorSuitesAreExcludedAndTwigRuntimeTestsRemain(): void
    {
        $lock = json_decode(file_get_contents(dirname(__DIR__, 2).'/composer.lock'), true, flags: JSON_THROW_ON_ERROR);
        $packages = array_column($lock['packages'], 'name');
        foreach (['symfony/service-contracts/Test/ServiceLocatorTest.php', 'symfony/translation-contracts/Test/TranslatorTest.php',
            'symfony/framework-bundle/Test/KernelTestCase.php', 'monolog/monolog/src/Monolog/Test/MonologTestCase.php',
            'twig/twig/src/Test/IntegrationTestCase.php', 'symfony/clock/Tests/Example.php'] as $path) {
            self::assertFalse(\Disc2Release::allowed('vendor/'.$path, $packages), $path);
        }
        self::assertTrue(\Disc2Release::allowed('vendor/twig/twig/src/Node/Expression/Test/DefinedTest.php', $packages));
    }

    public function testBundledPickerMatchesPinnedUpstreamDistribution(): void
    {
        $hashes = [
            'public/assets/flatpickr-4.6.13.min.css'=>'1b34a42552c96f10e4dfaaa4a367276b03868aacff63c1ac42ffe331352bc754',
            'public/assets/flatpickr-4.6.13.min.js'=>'1eeab1cb779471a0b0aaa93dd91c2eb1aa537d696f01ab05ea9dabc55e8525a1',
            'public/assets/flatpickr-4.6.13-th.js'=>'d720863a64b19a01fa5c3e7d1004bd89accfdaad5ccff7f3b2d146ef64428df5',
            'tools/licenses/flatpickr-LICENSE.txt'=>'5fd57afc3905651175947ae44818e91ffcf5b605e4c88fac42d9700243bd9095',
        ];
        foreach ($hashes as $entry => $digest) {
            self::assertContains($entry, \Disc2Release::REQUIRED);
            self::assertSame($digest, hash_file('sha256', dirname(__DIR__,2).'/'.$entry));
        }
    }

    public function testMissingRuntimeEntryFails(): void
    {
        $files = $this->stage();
        unset($files['vendor/autoload_runtime.php']);
        $this->expectExceptionMessage('vendor/autoload_runtime.php');
        \Disc2Release::pack($files, $this->temp.'/missing.zip', 'v1-test', str_repeat('1', 40));
    }

    public function testInstalledDevelopmentPackageFails(): void
    {
        $files = $this->stage();
        file_put_contents($files['vendor/composer/installed.json'], json_encode(['dev'=>false, 'packages'=>[['name'=>'dev/tests','version'=>'1.0']]]));
        $this->expectExceptionMessage('lock');
        \Disc2Release::pack($files, $this->temp.'/dev.zip', 'v1-test', str_repeat('1', 40));
    }

    public function testRollbackUsesOldConnectionAndDetectsChangedAuditRows(): void
    {
        $live = $this->temp.'/live.db';
        $db = new \PDO('sqlite:'.$live);
        $db->exec("CREATE TABLE uat_audit_log(id INTEGER PRIMARY KEY, detail TEXT); INSERT INTO uat_audit_log VALUES (1, 'cutover'), (2, 'post-cutover')");
        $db->exec('VACUUM INTO '.$db->quote($this->temp.'/frozen.db'));
        file_put_contents($this->temp.'/db.php', '<?php $pdo = new PDO("sqlite:".__DIR__."/live.db");');
        $proof = [PHP_BINARY, dirname(__DIR__, 2).'/tools/rollback-proof.php', $this->temp, $this->temp.'/frozen.db', '1'];
        $run = static function () use ($proof): array {
            $process = proc_open($proof, [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes);
            $output = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            return [proc_close($process), $output];
        };
        [$status, $message] = $run();
        self::assertSame(0, $status, $message);
        self::assertStringContainsString('1 post-cutover audits', $message);
        foreach ([
            'INDEX ix_audit_detail ON uat_audit_log(detail)' => 'INDEX ix_audit_detail',
            'VIEW audit_view AS SELECT detail FROM uat_audit_log' => 'VIEW audit_view',
            "TRIGGER audit_trigger AFTER INSERT ON uat_audit_log BEGIN SELECT 1; END" => 'TRIGGER audit_trigger',
        ] as $definition => $object) {
            $db->exec('CREATE '.$definition);
            [$status, $message] = $run();
            self::assertSame(1, $status, $object);
            self::assertStringContainsString('schema', $message);
            $db->exec('DROP '.$object);
        }
        $db->exec("UPDATE uat_audit_log SET detail='different' WHERE id=2");
        [$status, $message] = $run();
        self::assertSame(1, $status);
        self::assertStringContainsString('Frozen records differ', $message);
        file_put_contents($this->temp.'/db.php', '<?php exit("Missing legacy database");');
        self::assertSame(1, $run()[0]);
    }

    public function testOptionalAiConfigurationPreflightVerifiesSelectedPathAndRecoveryCopy(): void
    {
        $root = dirname(__DIR__, 2);
        foreach (['release/tools','release/vendor','private','data','recovery'] as $dir) {
            mkdir($this->temp.'/'.$dir, 0700, true);
        }
        $release = $this->temp.'/release';
        copy($root.'/tools/iis-preflight.php', $release.'/tools/iis-preflight.php');
        file_put_contents($release.'/release-manifest.json', '{"php_min":"8.2","extensions":[]}');
        file_put_contents($release.'/vendor/autoload.php', '<?php require '.var_export($root.'/vendor/autoload.php', true).';');
        $config = $this->temp.'/private/main.php';
        $database = $this->temp.'/data/cases.db';
        (new \PDO('sqlite:'.$database))->exec('CREATE TABLE cases(id INTEGER PRIMARY KEY)');
        $endpoint = 'https://directory.example/synthetic';
        file_put_contents($config, '<?php return '.var_export(['database_path'=>$database, 'bma_endpoint'=>$endpoint,
            'bma_secret_key'=>'fixture', 'super_admin_ids'=>[]], true).';');
        $run = function (string $selected = '', string ...$args) use ($release,$config,$database,$endpoint): int {
            $env = array_merge(getenv(), ['APP_ENV'=>'prod','APP_DEBUG'=>'0','APP_SECRET'=>'fixture','DISC2_CONFIG_FILE'=>$config,
                'DISC2_AI_CONFIG_FILE'=>$selected]);
            $proc = proc_open([PHP_BINARY, $release.'/tools/iis-preflight.php', $config, $database, $endpoint, 'staging', ...$args],
                [1=>['pipe','w'],2=>['redirect',1]], $pipes, $this->temp, $env);
            stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            return proc_close($proc);
        };
        $ai = $this->temp.'/private/assistant.php';
        $backup = $this->temp.'/recovery/assistant.php';
        self::assertSame(0, $run(), 'AI disabled');
        self::assertSame(1, $run($ai), 'explicit missing file');
        file_put_contents($ai, "<?php return ['base_url'=>'https://ai.example','api_key'=>'synthetic','model'=>'local'];");
        copy($ai, $backup);
        self::assertSame(1, $run($ai), 'missing recovery evidence');
        self::assertSame(0, $run($ai, $ai, $backup), 'selected file and independent matching copy');
        self::assertSame(1, $run('private/assistant.php', $ai, $backup), 'relative selected file');
        self::assertSame(1, $run($ai, $backup, $ai), 'selected file differs from CLI');
        self::assertSame(1, $run($ai, $ai, $ai), 'same file');
        $alias = $this->temp.'/recovery/same-inode.php';
        if (link($ai, $alias)) {
            self::assertSame(1, $run($ai, $ai, $alias), 'hard link to live file');
        }
        foreach ([$release.'/assistant.php',$this->temp.'/data/assistant.php'] as $unsafe) {
            copy($ai, $unsafe);
            self::assertSame(1, $run($unsafe, $unsafe, $backup), 'unsafe selected location');
            self::assertSame(1, $run($ai, $ai, $unsafe), 'unsafe copy location');
        }
        file_put_contents($backup, '<?php return [];');
        self::assertSame(1, $run($ai, $ai, $backup), 'different contents');
    }

    private function stage(): array
    {
        $files = [];
        foreach (\Disc2Release::REQUIRED as $name) {
            $file = $this->temp.'/'.str_replace('/', '_', $name);
            file_put_contents($file, $name);
            $files[$name] = $file;
        }
        $pkg = ['name'=>'acme/pkg', 'version'=>'1.0','dist'=>['reference'=>'abc']];
        file_put_contents($files['composer.lock'], json_encode(['packages'=>[$pkg], 'packages-dev'=>[['name'=>'dev/tests']]]));
        file_put_contents($files['vendor/composer/installed.json'], json_encode(['dev'=>false,'packages'=>[$pkg]]));
        $files['vendor/acme/pkg/composer.json'] = $files['composer.json'];
        $files['vendor/acme/pkg/Library.php'] = __FILE__;
        return $files;
    }
}
