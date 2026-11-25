# DISC2 Symfony Rewrite Summary

วันที่สรุป: 25 พฤศจิกายน 2026

## วัตถุประสงค์

เขียน DISC2 ใหม่ทั้งหมดด้วย Symfony 7.4 LTS แทน procedural PHP โดยไม่ใช้ legacy runtime bridge และยังรองรับทั้ง Docker/WSL สำหรับพัฒนาและ IIS Server-A สำหรับ production

## โครงสร้างและ Branch

- Branch สำหรับเก็บประวัติการพัฒนา: `rewrite/symfony`
- Branch สำหรับส่งงาน: `feature/symfony-rewrite`
- Worktree ประวัติ: `/home/administrator/disc/rewrite2symfony`
- Worktree ส่งงาน: `/home/administrator/disc/feature-symfony-rewrite`
- Base branch: `main`

## ขั้นตอนที่ดำเนินการ

1. เก็บ route, role, workflow และพฤติกรรมระบบเดิมด้วย characterization tests และข้อมูลสมมติ
2. สร้าง Symfony 7.4 ใหม่ พร้อม Docker bind mount และ IIS `public/` document root
3. แยก Domain rules สำหรับสูตรวัน 30/60/120/180 วัน สิทธิ์สำนวน และการปกปิดตัวบุคคล
4. สร้าง PDO/SQLite repositories, transaction manager และ migration command
5. ย้าย login, BMA authentication, session, CSRF, rate limit และ voters
6. ย้ายหน้าจออ่านทั้งหมดเป็น Controller/Twig พร้อม URL `.php` เดิม
7. ย้ายการเขียนสำนวน ผู้ถูกกล่าวหา และสิทธิ์ พร้อม transaction/audit
8. ย้าย workflow, คณะกรรมการ, การขยายเวลา และผลรายบุคคล
9. ลบ procedural PHP runtime และตรวจ route/exposure contract
10. สร้าง deterministic IIS release ZIP, SHA-256, ACL/preflight และ rollback tools
11. ทดสอบ clean-clone, offline assets, browser workflows และ rollback ทั้ง Demo/Production
12. แก้ผล final review เรื่อง concurrency, committee appointment, migration sequence, malformed dates, trusted proxy, offline calendar และ rollback schema verification

## ผลลัพธ์สำคัญ

- procedural PHP entry points ถูกแทนด้วย Symfony controllers/routes
- คง SQLite schema และรองรับการอ่านข้อมูลใหม่ด้วย release เดิมสำหรับ production rollback
- คง URL เดิม เช่น `/index.php`, `/case.php`, `/save_case.php`
- เฉพาะ admin/super-admin เห็นตัวบุคคลจริง
- ทุก write ตรวจ authentication, CSRF, case scope, transaction และ audit
- Docker/WSL แก้ source แล้วเห็นผลใน container โดยไม่ rebuild
- Flatpickr และ Thai locale ถูกเก็บใน release ใช้งานแบบ offline
- Release ZIP ตรวจไฟล์ต้องห้ามและเปรียบเทียบ schema objects ทั้งหมด

## การตรวจสอบล่าสุด

- PHPUnit: 206 tests ผ่าน
- Assertions: 2,903
- Rollback verification: 45 schema objects และข้อมูล 24 tables ตรงกัน
- Production rollback: เก็บ audit หลัง cutover ครบ 18 รายการ
- Demo rollback: คืนฐานก่อน cutover สำเร็จ
- Candidate commit: `0db4d5a7002b75679bd6edde3ea2e30711325378`
- Evidence commit: `66c9419ebe985e4a369ada440325c60691d50da4`
- Candidate ZIP SHA-256: `c501c8016ee9b3fd4a5c68a1a9a96f4691069a5bff09bb634c4c1a4db4de16f3`

## ข้อกำหนดก่อนขึ้น Production

สถานะ production ยังเป็น **HOLD** จนกว่าจะผ่าน:

- Windows IIS/FastCGI staging จริง
- NTFS/AccessChk และ App Pool permissions
- BMA non-production endpoint ที่ได้รับอนุมัติ
- rollback rehearsal กับ previous Server-A package จริง
- operator review และ sign-off

ห้ามเปิด production writes ก่อน checklist ใน `docs/rewrite/cutover-checklist.md` ผ่านครบ
