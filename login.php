<?php
require __DIR__ . '/auth.php';
require __DIR__ . '/db.php';

// Already logged in
if (!empty($_SESSION['user_id'])) {
    header('Location: ' . appUrl((($_SESSION['user_role'] ?? '') === 'admin' || isUatSuperAdmin()) ? 'admin.php' : 'index.php'));
    exit;
}

if (disc2DevMode()) {
    require __DIR__.'/tools/dev-login.php';
    exit;
}
$uatConfig = disc2LiveConfig();

$error = '';
$tab = 'bma';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login_type = $_POST['login_type'] ?? 'bma';
    $tab = $login_type;

    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        $error = 'กรุณากรอกข้อมูลให้ครบถ้วน';
    } else {
        $rateLimitError = checkRateLimit($pdo);
        if ($rateLimitError) {
            $error = $rateLimitError;
        } elseif ($login_type === 'admin') {
            // ── Admin local login ──────────────────────────────────
            $stmt = $pdo->prepare("SELECT * FROM users WHERE username=? AND role='admin'");
            $stmt->execute([$username]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row && password_verify($password, $row['password_hash'])) {
                session_regenerate_id(true);
                $_SESSION['user_id']   = $row['id'];
                $_SESSION['user_name'] = $row['name'];
                $_SESSION['user_role'] = 'admin';
                $_SESSION['user_email']= $username;
                writeLoginLog($pdo, $username, $row['name'], 'admin', 'success');
                header('Location: ' . appUrl('admin.php'));
                exit;
            } else {
                writeLoginLog($pdo, $username, '', 'admin', 'fail');
                $error = 'Username หรือ Password ไม่ถูกต้อง';
            }
        } elseif (empty($uatConfig['bma_secret_key'])) {
            $error = 'ยังไม่ได้ตั้งค่าการเข้าสู่ระบบ BMA';
        } else {
            // ── BMA API login ──────────────────────────────────────
            $bmaUsername = preg_replace('/@bangkok\.go\.th$/i', '', $username);
            $ch = curl_init('https://register.bangkok.go.th/bmamailadmin/web/index.php?r=api/getuserinfo');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => http_build_query([
                    'secretkey' => $uatConfig['bma_secret_key'],
                    'Email'     => $bmaUsername,
                ]),
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/x-www-form-urlencoded',
                    'User-Agent: Mozilla/5.0',
                    'Accept: application/json',
                ],
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_FOLLOWLOCATION => true,
            ]);
            $resx  = curl_exec($ch);
            $errno = curl_errno($ch);

            if ($resx === false || $errno) {
                $error = 'ไม่สามารถเชื่อมต่อระบบ BMA ได้ กรุณาลองใหม่หรือใช้ล็อกอินแบบ Admin';
            } else {
                $obj = json_decode($resx);
                if ($obj && isset($obj->LdapPersonalID) && $obj->LdapPersonalID !== ''
                    && $password === $obj->LdapPersonalID)
                {
                    session_regenerate_id(true);
                    $displayName = trim(implode(' ', array_filter([
                        trim((string)($obj->TitleTH ?? '')),
                        trim((string)($obj->FirstNameTH ?? '')),
                        trim((string)($obj->LastNameTH ?? '')),
                    ])));
                    if ($displayName === '') {
                        if (!empty($obj->LdapDisplayName))       $displayName = $obj->LdapDisplayName;
                        elseif (!empty($obj->LdapFirstName))     $displayName = trim($obj->LdapFirstName . ' ' . ($obj->LdapLastName ?? ''));
                        else                                      $displayName = $username;
                    }

                    $personalId = (string)$obj->LdapPersonalID;
                    $email = $bmaUsername . '@bangkok.go.th';
                    $unitStmt = $pdo->prepare("SELECT unit_level1 FROM user_unit_assignments WHERE bma_personal_id=?");
                    $unitStmt->execute([$personalId]);
                    $assignedUnit = $unitStmt->fetchColumn();

                    if ($assignedUnit === false) {
                        $apiUnit = trim((string)($obj->UnitLevel1 ?? ''));
                        if ($apiUnit === '') {
                            writeLoginLog($pdo, $email, '', 'user', 'fail');
                            $error = 'ไม่พบข้อมูลหน่วยงาน (UnitLevel1) สำหรับบัญชีนี้';
                        } else {
                            $insertUnit = $pdo->prepare("INSERT OR IGNORE INTO user_unit_assignments (bma_personal_id, bma_email, unit_level1) VALUES (?,?,?)");
                            $insertUnit->execute([$personalId, $email, $apiUnit]);
                            $unitStmt->execute([$personalId]);
                            $assignedUnit = $unitStmt->fetchColumn();
                        }
                    }

                    if ($assignedUnit !== false && $assignedUnit !== '') {
                        $_SESSION['user_id']   = $obj->LdapPersonalID;
                        $_SESSION['user_name'] = trim($displayName);
                        $_SESSION['user_role'] = 'user';
                        $_SESSION['user_email']= $email;
                        $_SESSION['user_unit_level1'] = $assignedUnit;
                        $_SESSION['bma_data']  = (array)$obj; // เก็บข้อมูลทั้งหมดจาก API
                        writeLoginLog($pdo, $email, trim($displayName), 'user', 'success');
                        header('Location: ' . appUrl(isUatSuperAdmin() ? 'admin.php' : 'index.php'));
                        exit;
                    }
                } else {
                    writeLoginLog($pdo, $bmaUsername . '@bangkok.go.th', '', 'user', 'fail');
                    $error = 'Email หรือ เลขประจำตัวประชาชน ไม่ถูกต้อง';
                }
            }
        }
    }
}
?><!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex,nofollow">
  <title>เข้าสู่ระบบ · DISC UAT</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Thai:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
  * { box-sizing: border-box; }
  body {
    font-family: 'IBM Plex Sans Thai', system-ui, sans-serif;
    background: linear-gradient(135deg, #2c3e50 0%, #3498db 100%);
    min-height: 100vh; display: flex; align-items: center; justify-content: center;
    margin: 0; padding: 24px 16px;
  }
  .uat-banner { position:fixed; top:0; left:0; right:0; z-index:10; text-align:center; background:#fff3cd; color:#7a5400; font-weight:700; font-size:12px; padding:7px; }
  .card {
    background: #fff; border-radius: 16px; padding: 40px;
    width: 100%; max-width: 440px;
    box-shadow: 0 24px 60px -20px rgba(0,0,0,.45);
  }
  .logo { text-align: center; margin-bottom: 28px; }
  .logo .icon { width: 64px; height: 64px; display: grid; place-items: center; margin: 0 auto 16px; color: #fff; font-size: 30px; border-radius: 18px; background: linear-gradient(135deg,#10b981,#059669); box-shadow: 0 8px 24px -8px rgba(16,185,129,.4); }
  .logo h1 { font-size: 20px; font-weight: 600; color: #2c3e50; margin: 0 0 4px; }
  .logo p { font-size: 13px; color: #6c757d; margin: 0; }
  .field { margin-bottom: 18px; }
  label { display: block; font-weight: 500; font-size: 13px; color: #374151; margin-bottom: 7px; }
  input {
    width: 100%; border: 1px solid #ced4da; border-radius: 7px;
    padding: 11px 12px; font-size: 14px; font-family: inherit;
    transition: border-color 0.2s;
  }
  input:focus { outline: none; border-color: #3498db; box-shadow: 0 0 0 3px rgba(52,152,219,.18); }
  .email-group { display: flex; }
  .email-group input { border-radius: 7px 0 0 7px; min-width: 0; }
  .email-suffix { display: flex; align-items: center; padding: 0 11px; border: 1px solid #ced4da; border-left: 0; border-radius: 0 7px 7px 0; background: #f8f9fa; color: #6c757d; font-size: 12px; white-space: nowrap; }
  .field-note { color: #6c757d; font-size: 12px; margin: 6px 0 0; }
  .email-warning { display: none; color: #856404; background: #fff3cd; border: 1px solid #ffeeba; padding: 7px 10px; border-radius: 8px; font-size: 12px; margin-top: 8px; }
  .email-warning.show { display: block; }
  .btn {
    width: 100%; background: linear-gradient(135deg,#3498db,#2980b9); color: #fff; border: none;
    border-radius: 7px; padding: 11px 12px; font-size: 15px; font-family: inherit;
    cursor: pointer; font-weight: 600; margin-top: 2px; transition: background .2s, transform .2s;
  }
  .btn:hover { background: linear-gradient(135deg,#2980b9,#1f6391); }
  .alert { background: #f8d7da; border: 1px solid #f5c2c7; color: #842029;
    border-radius: 8px; padding: 10px 12px; font-size: 13px; margin-bottom: 18px; }
  .security-note { display: flex; gap: 9px; align-items: flex-start; margin-top: 18px; color: #047857; background: #ecfdf5; border: 1px solid #a7f3d0; padding: 10px; border-radius: 10px; font-size: 12px; line-height: 1.5; }
  .tab-bar { display: flex; margin: 0 0 22px; border-bottom: 1px solid #e5e7eb; }
  .tab { flex: 1; padding: 10px 5px; text-align: center; font-size: 13px; font-weight: 600;
    cursor: pointer; color: #6b7280; background: transparent; border: 0; border-bottom: 3px solid transparent; font-family: inherit; }
  .tab.active { color: #2980b9; border-bottom-color: #3498db; }
  .tab-content { display: none; }
  .tab-content.active { display: block; }
  .guide { border-top: 1px solid #e5e7eb; margin-top: 24px; padding-top: 6px; }
  .guide summary { cursor: pointer; padding: 12px 0; font-size: 14px; font-weight: 500; color: #374151; }
  .guide-content { color: #6c757d; font-size: 12px; line-height: 1.65; padding: 0 0 6px; }
  .guide-content ol { margin: 0 0 10px; padding-left: 20px; }
  .guide-content a { color: #2980b9; }
  @media (max-width: 480px) { .card { padding: 30px 22px; } }
</style>
</head>
<body>
<div class="uat-banner">⚠️ ระบบทดสอบ UAT — ไม่ใช่ระบบ Production</div>
<div class="card">
  <div class="logo">
    <div class="icon">⚖</div>
    <h1>ระบบสำนวนวินัย</h1>
    <p>เข้าสู่ระบบด้วย BMA Mail</p>
  </div>

  <?php if ($error): ?>
  <div class="alert">⚠️ <?= htmlspecialchars($error) ?></div>
  <?php endif ?>

  <div class="tab-bar">
    <button type="button" class="tab <?= $tab==='bma'?'active':'' ?>" onclick="switchTab('bma', this)">BMA Mail</button>
    <button type="button" class="tab <?= $tab==='admin'?'active':'' ?>" onclick="switchTab('admin', this)">ผู้ดูแลระบบ</button>
  </div>

  <form method="POST" id="form-bma" class="tab-content <?= $tab==='bma'?'active':'' ?>">
    <input type="hidden" name="login_type" value="bma">
    <div class="field">
      <label for="bma-username">✉ Email (BMA Mail Username)</label>
      <div class="email-group">
        <input id="bma-username" type="text" name="username" placeholder="ชื่อผู้ใช้" value="<?= $tab==='bma'?htmlspecialchars(str_replace('@bangkok.go.th', '', $_POST['username']??'')):'' ?>" autocomplete="username" autocapitalize="none" required>
        <span class="email-suffix">@bangkok.go.th</span>
      </div>
      <div id="email-warning" class="email-warning">ไม่ต้องพิมพ์ @bangkok.go.th ระบบจะเติมให้โดยอัตโนมัติ</div>
    </div>
    <div class="field">
      <label for="bma-password">▣ เลขบัตรประชาชน 13 หลัก</label>
      <input id="bma-password" type="password" name="password" inputmode="numeric" maxlength="13" placeholder="กรอกเลขบัตรประชาชน 13 หลัก" autocomplete="current-password" required>
      <p class="field-note">ใช้เลขประจำตัวประชาชนที่ผูกกับบัญชี BMA Mail</p>
    </div>
    <button type="submit" class="btn">เข้าสู่ระบบ</button>
    <div class="security-note"><span>🛡</span><span>ข้อมูลสำหรับเข้าสู่ระบบจะใช้เพื่อตรวจสอบสิทธิ์การใช้งานระบบสำนวนวินัยเท่านั้น</span></div>
    <details class="guide">
      <summary>ⓘ คู่มือการเข้าสู่ระบบ</summary>
      <div class="guide-content">
        <ol>
          <li>กรอกเฉพาะชื่อหน้าอีเมล BMA Mail เช่น <code>somchai</code></li>
          <li>กรอกเลขประจำตัวประชาชน 13 หลักของเจ้าของบัญชี</li>
          <li>กด “เข้าสู่ระบบ” ระบบจะตรวจสอบสิทธิ์ก่อนพาไปยังหน้าสำนวนวินัย</li>
        </ol>
        หากยังไม่มีบัญชี BMA Mail สามารถ <a href="https://register.bangkok.go.th/bmamailinfo/web/" target="_blank" rel="noopener">สมัครบัญชี BMA Mail</a>
      </div>
    </details>
  </form>

  <form method="POST" id="form-admin" class="tab-content <?= $tab==='admin'?'active':'' ?>">
    <input type="hidden" name="login_type" value="admin">
    <div class="field">
      <label>Username</label>
      <input type="text" name="username" placeholder="admin" value="<?= $tab==='admin'?htmlspecialchars($_POST['username']??''):'' ?>" autocomplete="username">
    </div>
    <div class="field">
      <label>Password</label>
      <input type="password" name="password" placeholder="••••••••" autocomplete="current-password">
    </div>
    <button type="submit" class="btn">เข้าสู่ระบบ</button>
  </form>

</div>

<script>
function switchTab(t, button) {
  document.querySelectorAll('.tab').forEach(el => el.classList.remove('active'));
  document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
  document.getElementById('form-' + t).classList.add('active');
  button.classList.add('active');
}

const usernameField = document.getElementById('bma-username');
const emailWarning = document.getElementById('email-warning');
usernameField.addEventListener('input', function () {
  if (this.value.includes('@')) {
    this.value = this.value.split('@')[0];
    emailWarning.classList.add('show');
    window.setTimeout(() => emailWarning.classList.remove('show'), 4000);
  }
});
</script>
</body>
</html>
