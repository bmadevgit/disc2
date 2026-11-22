# ดูแล Git และออกรุ่น DISC2

ใช้ Git กลางขององค์กรเมื่อมีปลายทางที่พร้อม หรือแลกเปลี่ยนงานด้วยไฟล์ Git bundle ผ่านช่องทางภายในที่ทีมใช้อยู่ ให้ผู้ดูแลถือสำเนา `main` และเป็นผู้รวมงาน

## หน้าที่ผู้ดูแลและจุดอนุมัติ

| จุด | ผู้รับผิดชอบและการอนุมัติ |
|---|---|
| ส่งโค้ดให้เริ่มงาน | ผู้ดูแลโครงการส่ง `disc2.bundle` ให้ผู้ได้รับมอบหมาย นักพัฒนาตั้งชื่อและอีเมล Git บนเครื่องได้เอง |
| ใช้ Codex | ผู้พัฒนาใช้บัญชี ChatGPT ของตน หาก Workspace ขององค์กรเป็นผู้กำหนดสิทธิ์ ให้ผู้ดูแล Workspace ตรวจสิทธิ์ใช้งาน Codex |
| รับงานที่แก้แล้ว | ผู้ดูแลโครงการตรวจคำอธิบาย ความต่างของโค้ด และผลทดสอบ ถ้าต้องแก้เพิ่ม ให้คืนงานไปที่ branch เดิม |
| รวมเข้า `main` | ผู้ดูแลโครงการรวม branch ที่ผ่านการตรวจ แล้วส่ง bundle ที่อัปเดตให้ทีม |
| ใช้ Git กลางแบบ private เมื่อเปิดใช้ | ผู้ดูแล repo เชิญบัญชี Git และให้สิทธิ์ส่ง branch จากนั้น reviewer ตรวจ Pull Request และ maintainer รวมเข้า `main` ตามกฎ repo |
| นำขึ้นเว็บ | ผู้ดูแลการออกรุ่นเลือกแท็ก สำรองระบบ และตรวจหลังนำขึ้น Server-A |

เมื่อเริ่มใช้งาน Git กลาง ให้เพิ่มหรือถอนสิทธิ์ตามบัญชีของผู้พัฒนาเป็นรายคน เมื่อสมาชิกออกจากทีมให้ถอดสิทธิ์ repo และแจ้งผู้รับผิดชอบ Workspace หากต้องปรับสิทธิ์ Codex ด้วย

[กลับไปดูแผนภาพและขั้นตอนฝั่งนักพัฒนา](README.md#แผนภาพการทำงาน)

## รับและรวมงานจาก Git bundle

1. ให้ผู้พัฒนาแจ้งชื่อ branch พร้อมส่งไฟล์ bundle และผลทดสอบ เก็บไฟล์ไว้ในตำแหน่งที่ผู้ดูแลเปิดอ่านได้
2. จากสำเนาของผู้ดูแล ตรวจไฟล์และนำ branch เข้า namespace สำหรับรีวิว ตัวอย่างนี้ใช้ branch `feature/case-filter`

   ```powershell
   git bundle verify ..\case-filter.bundle
   git bundle list-heads ..\case-filter.bundle
   git fetch ..\case-filter.bundle refs/heads/feature/case-filter:refs/remotes/contributions/feature/case-filter
   git log --oneline main..contributions/feature/case-filter
   git diff main...contributions/feature/case-filter
   ```

3. ทดสอบ branch ที่นำเข้าในสำเนาทดสอบ ตรวจการเปลี่ยนโครงสร้างฐานและสิทธิ์ตามงานนั้น เมื่อผ่านแล้วจึงรวมเข้า `main`

   ```powershell
   git switch main
   git merge --no-ff contributions/feature/case-filter
   ```

4. ออกไฟล์ฐานล่าสุดด้วย `git bundle create <path-ใหม่> HEAD main --tags` เก็บสำเนาของไฟล์รอบก่อนและแชร์ไฟล์ใหม่ผ่านช่องทางภายใน ผู้พัฒนาที่ clone อยู่แล้ววางไฟล์ใหม่แทนไฟล์ bundle ที่ `origin` ชี้อยู่ แล้วใช้ `git pull --ff-only origin main`

## ย้ายไป Git กลางแบบ private

1. เมื่อได้รับ URL และสิทธิ์ใน Git กลาง ให้สร้าง repo ว่างแบบ private สำหรับ DISC2 เพิ่มผู้พัฒนาเป็นรายบัญชี
2. นำ branch `main` และ tags ขึ้น repo ที่ได้รับอนุมัติจากสำเนาของผู้ดูแล

   ```powershell
   git remote add team <Git-URL>
   git push team main
   git push team --tags
   ```

3. ตั้งกฎคุ้มครอง `main` ให้ส่งงานผ่าน Pull Request และให้ผู้ดูแลรวมหลังรีวิว ทดสอบด้วยบัญชีนักพัฒนาว่าส่ง branch งานได้ และ Pull Request เข้า `main` ต้องผ่านผู้มีสิทธิ์รีวิว
4. ผู้ที่เคย clone จาก bundle เปลี่ยนปลายทางด้วย `git remote set-url origin <Git-URL>` แล้วใช้ `git fetch origin`

## นำรุ่นที่ผ่านการตรวจขึ้น Server-A

1. ผู้ดูแลติดแท็ก `v...` แล้วรัน `powershell -NoProfile -File .\tools\release.ps1 -Tag <tag> -Output <ไฟล์.zip>` ตรวจ SHA-256 และเนื้อหา ZIP
2. ก่อนนำ ZIP รุ่นแรกขึ้น ให้เตรียม `C:\inetpub\private\disc2-config.php` ให้คืนค่า `super_admin_ids` เป็นอาร์เรย์ของรหัสผู้ดูแลเดิมใน `auth.php` บน Server-A รักษาค่า `bma_secret_key` เดิม และเก็บไฟล์ตั้งค่าภายนอก webroot ค่า AI ใช้ไฟล์ตั้งค่าภายนอกเดิม
3. พักเฉพาะเส้นทาง DISC2 แล้วสำรองโค้ด ค่าตั้งเฉพาะเครื่อง และฐาน DISC2 ด้วย SQLite online backup จากจุดเดียวกัน ตรวจ `PRAGMA integrity_check` คัดเฉพาะไฟล์ของ ZIP แล้วรัน `php migrate.php` ด้วย PHP ของ IIS จากโฟลเดอร์ DISC2
4. ตรวจการเข้าสู่ระบบ BMA และ Admin การเปิดรายการและรายละเอียดสำนวน และ `PRAGMA foreign_key_check` ก่อนเปิดรับการบันทึก หากตรวจไม่ผ่าน ให้กู้โค้ด ค่าตั้ง และฐานจากจุดสำรองเดียวกัน
