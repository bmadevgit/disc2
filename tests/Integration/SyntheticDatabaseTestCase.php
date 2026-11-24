<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

abstract class SyntheticDatabaseTestCase extends TestCase
{
    protected string $root;
    protected string $directory;
    protected string $path;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        $this->directory = sys_get_temp_dir().'/disc2-persistence-'.bin2hex(random_bytes(8));
        mkdir($this->directory.'/runtime', 0700, true);
        $this->path = $this->directory.'/runtime/disc2-dev.sqlite';
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
    }

    protected function base(): PDO
    {
        $db = new PDO('sqlite:'.$this->path);
        $db->exec(file_get_contents($this->root.'/tools/base-schema.sql'));
        return $db;
    }

    protected function legacyRoot(): string
    {
        $root = getenv('DISC2_LEGACY_ROOT');
        if (!$root) {
            self::markTestSkipped('Set DISC2_LEGACY_ROOT to the exported procedural release for the rollback rehearsal.');
        }
        self::assertFileExists($root.'/auth.php', 'DISC2_LEGACY_ROOT must contain the procedural release.');
        return $root;
    }

    protected function initializeFixture(string $root): void
    {
        $source = $this->directory.'/legacy';
        $process = proc_open([PHP_BINARY, $root.'/tools/init.php', $source], [1 => ['pipe', 'w']], $pipes);
        stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        self::assertSame(0, proc_close($process));
    }

    protected function copiedFixture(): void
    {
        $this->initializeFixture($this->root);
        $source = $this->directory.'/legacy';
        copy($source.'/disc2-dev.sqlite', $this->path);
    }
}
