<?php

declare(strict_types=1);

namespace App\Tests\Release;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CutoverInstructionsTest extends TestCase
{
    public static function operatorPaths(): iterable
    {
        yield 'Thai README' => ['README.md', '### IIS Server-A', '## 4.', [
            'หยุดการเขียน', 'การตั้งค่า IIS เดิม', 'SHA-256 ของสำเนา config', 'PRAGMA foreign_key_check',
            'เปลี่ยน Physical Path', 'ตั้ง `APP_ENV=prod`', '-ApplyMigrations', 'ทดสอบเข้าสู่ระบบ',
        ]];
        yield 'staging checklist' => ['docs/rewrite/cutover-checklist.md', '## Before scheduling', '## During', [
            'Freeze staging writers', 'original IIS mapping', "copy's SHA-256", 'maximum audit ID',
            'PRAGMA foreign_key_check', 'Pre-switch gate', 'Deploy the verified ZIP', '-ApplyMigrations', 'Test fresh local-admin',
        ]];
        yield 'production checklist' => ['docs/rewrite/cutover-checklist.md', '## During the approved production window', '## If rollback', [
            'freezes HTTP', 'original IIS mapping', "snapshot's SHA-256", 'audit watermark', 'PRAGMA foreign_key_check',
            'Pre-switch gate', 'Switch to the clean release', 'explicit migration', 'Confirm fresh sign-in',
        ]];
        yield 'IIS runbook' => ['docs/IIS-DEPLOYMENT.md', '## Stage the IIS application', '## Production rollback', [
            'Block public traffic', 'original IIS mapping', 'verify its SHA-256 against the original', 'MAX(id)',
            'PRAGMA foreign_key_check', 'Pre-switch gate', 'Configure IIS application', '-ApplyMigrations', 'Verify local Admin',
        ]];
    }

    #[DataProvider('operatorPaths')]
    public function testBaselinePrecedesCandidateOperations(string $file, string $start, string $end, array $steps): void
    {
        $document = file_get_contents(dirname(__DIR__, 2).'/'.$file);
        $begin = strpos($document, $start);
        self::assertNotFalse($begin, $start);
        $finish = strpos($document, $end, $begin);
        self::assertNotFalse($finish, $end);
        $section = substr($document, $begin, $finish - $begin);

        // Check the first occurrence so an early switch cannot be hidden by a later repeated instruction.
        $previous = -1;
        foreach ($steps as $step) {
            $position = strpos($section, $step);
            self::assertNotFalse($position, "Missing step in $file: $step");
            self::assertGreaterThan($previous, $position, "Out-of-order step in $file: $step");
            $previous = $position;
        }
    }
}
