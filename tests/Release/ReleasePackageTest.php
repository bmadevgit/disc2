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
        $db->exec("UPDATE uat_audit_log SET detail='different' WHERE id=2");
        [$status, $message] = $run();
        self::assertSame(1, $status);
        self::assertStringContainsString('Frozen records differ', $message);
        file_put_contents($this->temp.'/db.php', '<?php exit("Missing legacy database");');
        self::assertSame(1, $run()[0]);
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
