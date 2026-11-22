# ติดตั้ง Git กลางและ VS Code Remote สำหรับ DISC2

ใช้ Windows Dev VM ที่เข้าถึงผ่าน LAN/VPN องค์กร Git กลางใช้ Gitea บน VM นี้เพื่อให้บัญชีนักพัฒนามีสิทธิ์ส่ง branch และให้ maintainer รวมงานผ่าน Pull Request สิทธิ์เขียนที่เก็บไฟล์ Git โดยตรงอยู่ที่บัญชีบริการ

## เตรียม VM และบัญชี Windows

1. ติดตั้ง Git for Windows และ PHP 8.5.12 x64 พร้อม `pdo_sqlite` และ `curl` เพิ่ม Git และ PHP ใน PATH ของผู้ใช้ ใช้ Windows OpenSSH Server สำหรับ VS Code Remote-SSH [OpenSSH บน Windows](https://learn.microsoft.com/en-us/windows-server/administration/openssh/openssh_install_firstuse)
2. สร้างบัญชี Windows แบบผู้ใช้ทั่วไปให้แต่ละคน เพิ่มเข้ากลุ่มที่ได้รับอนุญาตใช้ OpenSSH และเพิ่ม SSH public key ของคนนั้นใน `%USERPROFILE%\.ssh\authorized_keys` ตามวิธีของ Microsoft ตรวจสิทธิ์ไฟล์ให้ผู้ใช้และผู้ดูแลที่กำหนดเท่านั้นเข้าถึง ทดสอบ public key จากเครื่องลูกข่ายก่อนปิด password authentication [จัดการ SSH key บน Windows](https://learn.microsoft.com/en-us/windows-server/administration/openssh/openssh_keymanagement)
3. จำกัดกฎขาเข้า TCP 22 ให้รับจาก CIDR ของ LAN/VPN องค์กรบน VM ตรวจและปิดกฎ OpenSSH แบบกว้างเมื่อกฎที่จำกัดแล้วใช้งานได้ เปิด local TCP forwarding สำหรับ Remote-SSH และให้แต่ละบัญชีทำงานในโฟลเดอร์ส่วนตัว จัดพอร์ตเว็บทดสอบแยกรายคน เริ่มจาก 8760 และ 8761

## ตั้ง Gitea บน VM

1. สร้างบัญชีบริการ Windows สำหรับ Gitea และให้สิทธิ์อ่านเขียนเฉพาะ `C:\Git` กับไฟล์ตั้งค่าและข้อมูลใต้ `C:\Gitea` ติดตั้ง Gitea Windows รุ่นที่องค์กรอนุมัติและตรวจ checksum ของผู้เผยแพร่ไว้ที่ `C:\Gitea\gitea.exe` ใช้ไฟล์ `tools/gitea.app.ini.example` เป็นต้นแบบสำหรับ `C:\Gitea\custom\conf\app.ini` ตั้ง `RUN_USER` ให้ตรงบัญชีบริการ ก่อนเริ่มบริการ [ติดตั้งเป็นบริการ Windows](https://docs.gitea.com/installation/windows-service/) · [ค่าตั้ง Gitea](https://docs.gitea.com/administration/config-cheat-sheet/)
2. เปิดพอร์ต 3000 ผ่าน **Ports** ของ VS Code Remote โดยให้ local port เป็น 3000 แล้วเข้า `http://127.0.0.1:3000/` ทำขั้นตอนติดตั้งครั้งแรกและสร้างบัญชีผู้ดูแล ตั้งให้ปิดการสมัครเอง สร้างบัญชีรายคนใน Gitea และเพิ่ม SSH public key สำหรับ Git จากโปรไฟล์ Windows บน VM แยกจากคีย์ที่ใช้ Remote-SSH จากเครื่องลูกข่าย Gitea ฟังเฉพาะ loopback ที่ 3000 และ 2222
3. สร้างองค์กร `disc2-team` และ repo ส่วนตัวเปล่า `disc2` โดยข้ามการสร้าง README ระบบจะเก็บ bare repository ที่ `C:\Git\disc2-team\disc2.git` สร้างทีม `Contributors` ให้สิทธิ์ Write และทีม `Maintainers` สำหรับรีวิวและรวมงาน
4. คัดลอก `disc2.bundle` ขึ้น VM แล้วนำเข้าจากเทอร์มินัลภายใน VM ด้วยบัญชีผู้ดูแล Git

   ```powershell
   git clone .\disc2.bundle disc2-import
   Set-Location disc2-import
   git remote set-url origin ssh://git@127.0.0.1:2222/disc2-team/disc2.git
   git push -u origin main
   git push origin --tags
   ```

5. เปิด **Settings → Branches** เพิ่มกฎ `main` เลือก Disable push และ Disable force push จำกัดการรวม Pull Request ให้ทีม Maintainers กำหนดอย่างน้อย 1 approval จากผู้มีสิทธิ์รีวิว ตั้งให้ทบทวนใหม่เมื่อโค้ดเปลี่ยน และให้ผู้ดูแลปฏิบัติตามกฎเดียวกัน ตั้ง protected tag `v*` ให้เฉพาะ maintainer ออกรุ่น [กฎสาขา](https://docs.gitea.com/usage/access-control/protected-branches/) · [กฎสำหรับแท็ก](https://docs.gitea.com/usage/access-control/protected-tags/)
6. สำรอง config, SQLite ของ Gitea และ Git repositories ออกไปยังที่เก็บสำรองขององค์กรนอก VM ตามรอบดูแลระบบ ทดสอบกู้คืนบนเครื่องแยก [สำรองและกู้ Gitea](https://docs.gitea.com/administration/backup-and-restore/)

## ตรวจรับบน Dev VM

- ให้ผู้พัฒนาสองคน Remote-SSH ผ่าน LAN/VPN และ clone คนละสำเนา ให้แต่ละคนลงชื่อเข้า Codex ในหน้าต่าง Remote แล้วตรวจว่า Codex อ่าน `AGENTS.md` และรันทดสอบจากสำเนาของคนนั้น
- ให้ทั้งสองเปิด preview พร้อมกันผ่าน Ports ทดสอบสร้างสำนวนสมมติหนึ่งรายการและตรวจว่าอีกสำเนาเห็นเฉพาะข้อมูลของตน
- ให้บัญชีนักพัฒนาลองส่ง branch งานและยืนยันว่า push ตรงเข้า `main` ถูกปฏิเสธ จากนั้นให้ maintainer รีวิวและรวมหนึ่ง PR ทดสอบ

## นำรุ่นที่ผ่านการตรวจขึ้น Server-A

1. ผู้ดูแลติดแท็ก `v...` แล้วสร้าง ZIP ด้วย `powershell -NoProfile -File .\tools\release.ps1 -Tag <tag> -Output <ไฟล์.zip>` ตรวจ SHA-256 และเนื้อหา ZIP
2. ก่อนนำ ZIP รุ่นแรกขึ้น ให้เตรียม `C:\inetpub\private\disc2-config.php` ให้คืนค่า `super_admin_ids` เป็นอาร์เรย์ของรหัสผู้ดูแลเดิมใน `auth.php` บน Server-A โดยรักษาค่า `bma_secret_key` เดิม ใช้ค่าจาก Server-A ภายใต้สิทธิ์ผู้ดูแล และเก็บไฟล์ตั้งค่านี้นอก webroot ค่า AI ใช้ไฟล์ตั้งค่าภายนอกเดิมของระบบ
3. พักเฉพาะเส้นทาง DISC2 สำรองโค้ด ตั้งค่าเฉพาะเครื่อง และใช้ SQLite online backup สำหรับฐาน DISC2 บันทึกจุดกู้คืนเดียวกัน ตรวจ `PRAGMA integrity_check` ก่อนลงรุ่น คัดเฉพาะไฟล์ของ ZIP แล้วรัน `php migrate.php` ด้วย PHP ของ IIS จากโฟลเดอร์ DISC2
4. ตรวจการเข้าสู่ระบบ BMA และ Admin การเปิดรายการและรายละเอียดสำนวน และ `PRAGMA foreign_key_check` ก่อนเปิดรับการบันทึก หากตรวจไม่ผ่าน ให้กู้โค้ด การตั้งค่า และฐานจากจุดสำรองเดียวกัน
