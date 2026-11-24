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
            'หยุดการเขียน', 'การตั้งค่า IIS เดิม', 'สำรองไฟล์ config ภายนอก',
            'ตรวจว่า SHA-256 ของสำเนา config ตรงกับต้นฉบับ',
            'บันทึกพาธฐาน SQLite และเลข audit ID สูงสุดก่อนสลับรุ่น', 'ทำ online backup ที่รวมข้อมูลที่ commit ใน WAL',
            'เปิดสำเนาแยกเพื่อตรวจว่าเลข audit ID ตรงกัน', 'PRAGMA integrity_check', 'PRAGMA foreign_key_check',
            'บันทึก SHA-256 และตำแหน่งสำเนา', 'ผู้ดูแลต้องบันทึกผลครบทั้งสามข้อ',
            'เปลี่ยน Physical Path', 'ตั้ง `APP_ENV=prod`', 'กำหนด `database_path`', 'ให้ตั้ง `DISC2_CONFIG_FILE`',
            'ให้ตั้ง `bma_endpoint`', '-ApplyMigrations', 'ทดสอบเข้าสู่ระบบ',
        ]];
        yield 'staging checklist' => ['docs/rewrite/cutover-checklist.md', '## Before scheduling', '## During', [
            'Freeze staging writers', 'original IIS mapping', 'private copy of its external configuration',
            "Verify the copy's SHA-256 against the original", 'Record the authoritative SQLite path and original maximum audit ID',
            'Take a SQLite online backup of the frozen database', 'Open the backup independently, verify the same audit watermark',
            'PRAGMA integrity_check', 'PRAGMA foreign_key_check', 'record the backup SHA-256', 'Pre-switch gate',
            'Deploy the verified ZIP', 'Set the approved non-production BMA HTTPS endpoint and staging key',
            '-ApplyMigrations', 'Test fresh local-admin',
        ]];
        yield 'production checklist' => ['docs/rewrite/cutover-checklist.md', '## During the approved production window', '## If rollback', [
            'freezes HTTP', 'original IIS mapping', 'snapshot its external configuration',
            "Verify the snapshot's SHA-256 against the original file", 'Record the authoritative database path and audit watermark',
            'Take a fresh SQLite online backup', 'Open it independently, confirm the same watermark',
            'PRAGMA integrity_check', 'PRAGMA foreign_key_check', 'record the backup SHA-256', 'Pre-switch gate',
            'Switch to the clean release', 'compatible external config', 'explicit migration', 'Confirm fresh sign-in',
        ]];
        yield 'IIS runbook' => ['docs/IIS-DEPLOYMENT.md', '## Stage the IIS application', '## Production rollback', [
            'Block public traffic', 'original IIS mapping', 'Save a private copy of the previous external configuration',
            'verify its SHA-256 against the original', 'SELECT COALESCE(MAX(id),0) FROM uat_audit_log',
            'Use the SQLite CLI `.backup` to take a fresh protected online backup',
            'Open it independently and verify the same audit watermark', 'PRAGMA integrity_check', 'PRAGMA foreign_key_check',
            "Record the backup's location and SHA-256", 'Pre-switch gate', 'Configure IIS application',
            'Set `APP_ENV=prod`', 'Configure `super_admin_ids`, `bma_secret_key` and an absolute `database_path`',
            'set `bma_endpoint`', '-ApplyMigrations', 'Verify local Admin',
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
