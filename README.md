# DISC2 · ระบบสำนวนวินัย

PHP 8.5 และ SQLite สำหรับงานพัฒนาและทดสอบ DISC2 คู่มือนี้ใช้ได้ทั้งใน VS Code บนเครื่องและในหน้าต่าง VS Code Remote-SSH

## เริ่มงานจาก Dev VM

1. ต่อ LAN หรือ VPN องค์กร แล้วเลือก **Remote-SSH: Connect to Host…** ใน VS Code เข้าด้วยบัญชี Windows รายคน
2. ใช้ URL **SSH clone** จาก Git กลาง เปิดเทอร์มินัลในหน้าต่าง Remote และ clone ในโฟลเดอร์ส่วนตัว

   ```powershell
   Set-Location $env:USERPROFILE
   New-Item -ItemType Directory -Force dev | Out-Null
   Set-Location dev
   git clone <SSH-clone-URL>
   Set-Location disc2
   ```

   เลือก **File → Open Folder** เปิดโฟลเดอร์ `dev\disc2` นี้
3. ในเทอร์มินัล PowerShell ทดสอบ PHP แล้วสร้างฐานสมมติครั้งแรก

   ```powershell
   php -v
   php --ri pdo_sqlite
   php tools/init.php
   ```

4. เปิด `runtime/demo-accounts.json` เพื่อดูรหัสผ่านที่สร้างให้ `demo-admin`, `demo-owner` และ `demo-reader` บัญชีเจ้าของและหัวหน้ากลุ่มเห็นสำนวน `DEMO-001` ส่วนหัวหน้ากลุ่มมีสิทธิ์อ่าน
5. ใช้หมายเลขพอร์ตที่ผู้ดูแล VM จัดให้ เช่น 8760 แล้วเปิดเว็บทดสอบ

   ```powershell
   powershell -NoProfile -File .\tools\serve.ps1 -Port 8760
   ```

   ที่แถบ **Ports** ใน VS Code เลือก **Forward a Port** แล้วเปิด URL ที่ระบบแสดงในเบราว์เซอร์ PHP รับการเชื่อมต่อที่ loopback ของ VM และฐานทดสอบอยู่ในโฟลเดอร์ `runtime` ของสำเนานี้ กด Ctrl+C ในเทอร์มินัลเพื่อหยุดเซิร์ฟเวอร์
6. หากต้องใช้ migrations ใหม่กับฐานทดสอบเดิม ใช้คำสั่งนี้ใน PowerShell ภายในสำเนา Dev

   ```powershell
   $env:DISC2_DEV = '1'
   try { php migrate.php } finally { Remove-Item Env:\DISC2_DEV }
   ```

## ใช้ Codex ช่วยพัฒนา

ที่ **Extensions** ตรวจว่ามี Codex ในหน้าต่าง Remote ถ้า VS Code แสดง **Install in SSH** ให้เลือกตามนั้น เปิด Codex และลงชื่อเข้าใช้ด้วยบัญชีที่ได้รับสิทธิ์ของตนเอง `AGENTS.md` อธิบายวิธีรันทดสอบและกติกาของโครงการ ใช้โค้ดและสำนวนสมมติในการสนทนากับ AI

งานแรกที่ใช้ทดสอบได้คือ “อ่าน AGENTS.md แล้วอธิบายว่าแท็บ 2 คำนวณวันเริ่มนับที่ไหน พร้อมทดสอบด้วยข้อมูลสมมติ” ตรวจว่าไฟล์และเทอร์มินัลอยู่ภายในสำเนาของตน [Codex ใน VS Code](https://learn.chatgpt.com/docs/codex/ide) · [VS Code Remote-SSH](https://code.visualstudio.com/docs/remote/ssh)

## ส่งงานให้ทีม

```powershell
git switch main
git pull --ff-only
git switch -c feature/case-filter
# แก้และตรวจงานในสำเนาของตน
git diff
git add <ไฟล์ที่แก้>
git commit -m "ปรับตัวกรองสำนวน"
git push -u origin feature/case-filter
```

เปิด Pull Request เข้า `main` ระบุวิธีทดสอบและผลที่เห็น ใช้สำนวนสมมติหากแนบภาพหน้าจอ ผู้ดูแลทบทวนและรวมงาน ใน PowerShell ที่รากโครงการ ตรวจไวยากรณ์ PHP ได้ดังนี้

```powershell
Get-ChildItem -Recurse -Filter *.php -File | ForEach-Object {
    php -l $_.FullName
    if ($LASTEXITCODE -ne 0) { throw "Syntax check failed for $($_.Name)" }
}
```
