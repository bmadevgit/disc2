# ดูแล GitHub และออกรุ่น DISC2

[GitHub ของโครงการ · bmadevgit/disc2](https://github.com/bmadevgit/disc2) เป็น repository แบบ private แยกสำหรับ DISC2 สาขาหลักคือ `main` นักพัฒนาเปิดสำเนาบนเครื่องตนเอง ส่วนผู้ดูแลออกรุ่นที่ `C:\inetpub\wwwroot\exam\disc2` บน Server-A

[แผนภาพและขั้นตอนฝั่งนักพัฒนา](README.md) · [คู่มือ PDF](docs/DISC2-Git-Codex.pdf)

## เชิญนักพัฒนาเข้า GitHub

1. ขอ **GitHub username** ของนักพัฒนา เปิด [Settings → Collaborators ของ DISC2](https://github.com/bmadevgit/disc2/settings/access) ด้วยบัญชีเจ้าของ repo แล้วกด **Add people**
2. เลือก username ให้ตรงและส่งคำเชิญ นักพัฒนากด **Accept invitation** ด้วยบัญชีเดียวกัน เช็กว่าเข้า README ของ repo ได้
3. ให้ผู้พัฒนาลองสร้าง branch ของตนและ push เพื่อยืนยันสิทธิ์ เมื่อพ้นหน้าที่ให้ถอนสิทธิ์ใน **Collaborators** ส่วนสิทธิ์ Codex เป็นของบัญชี ChatGPT หรือ Workspace แยกต่างหาก

[วิธีเชิญ Collaborator ของ GitHub](https://docs.github.com/en/repositories/managing-your-repositorys-settings-and-features/repository-access-and-collaboration/inviting-collaborators-to-a-personal-repository)

## รีวิวและรวมเข้า main

1. เปิด **Pull requests** และตรวจว่า base branch เป็น `main` และ compare เป็น branch ของงาน อ่านคำอธิบาย วิธีทดสอบ และหน้า **Files changed**
2. ตรวจไฟล์และข้อมูลที่ส่ง ทดสอบบนฐานสมมติ ถ้าต้องแก้ ให้ใช้ **Request changes** นักพัฒนา commit และ push branch เดิม ถ้าผ่าน ให้ผู้ดูแลเลือก **Approve** แล้วรวม PR ตามวิธีของทีม
3. แจ้งทีมให้ดึง `main` ล่าสุด การอนุมัติ PR และการออกรุ่นไปเว็บเป็นคนละขั้น

**กติกาปัจจุบัน** GitHub แจ้งว่าแผนของบัญชีนี้ยังเปิด Branch Protection สำหรับ repo private ไม่ได้ ทีมจึงใช้การรีวิวและ merge ผ่าน Pull Request เป็นข้อตกลง โดยให้ผู้ดูแลเป็นคนรวมเข้า `main` เมื่อแผนรองรับให้ตั้ง **Settings → Branches** เพื่อบังคับ Pull Request และกำหนดผู้อนุมัติอย่างน้อย 1 คน [ข้อกำหนด Branch Protection](https://docs.github.com/en/repositories/configuring-branches-and-merges-in-your-repository/managing-protected-branches/about-protected-branches)

## นำรุ่นที่ผ่านการตรวจขึ้น Server-A

ผู้ดูแลใช้แท็ก Git ที่ลงนาม ตรวจ fingerprint ของผู้ลงนามและ SHA-256 ของ ZIP แล้วซ้อมผ่าน IIS staging ที่ `/exam/disc2` ก่อนอนุมัติขึ้น Server-A ใช้ `php bin/console app:migrate --env=prod --no-debug` หลังหยุดรับการเขียนและตรวจ SQLite online backup แล้ว

เมื่อย้อนรุ่น production ให้เก็บฐานปัจจุบัน สำรองอีกครั้งหลังหยุดการเขียน แล้วเปลี่ยนเฉพาะโค้ดและค่าตั้งที่เข้ากันได้ ตรวจผ่านรุ่นเก่าว่าอ่านข้อมูลและ audit หลัง cutover ครบ ส่วน demo สามารถกู้ฐานก่อน cutover ได้ [ขั้นตอน build, deployment และ rollback](docs/IIS-DEPLOYMENT.md)

## รับ Git bundle สำหรับทีมที่ยังไม่ได้ย้ายงานเดิม

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
