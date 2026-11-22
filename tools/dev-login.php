<?php
if (!function_exists('disc2DevMode') || !disc2DevMode() || PHP_SAPI !== 'cli-server') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__).'/ui.php';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $query = $pdo->prepare('SELECT id,username,name,role,password_hash FROM users WHERE username=?');
    $query->execute([$username]);
    $user = $query->fetch();
    if ($user && password_verify($password,$user['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION = [
            'user_id'=>(string)$user['id'], 'user_role'=>$user['role'],
            'user_name'=>$user['name'], 'user_email'=>$user['username'],
        ];
        header('Location: '.appUrl('index.php'));
        exit;
    }
    $error = 'ชื่อบัญชีหรือรหัสผ่านไม่ถูกต้อง';
}

pageHead('เข้าสู่ระบบทดสอบ DISC2');
?>
<section class="card" style="max-width:520px;margin:28px auto">
    <h1 class="section-title">เข้าสู่ระบบทดสอบ DISC2</h1>
    <p>ใช้บัญชีสมมติใน <code>runtime/demo-accounts.json</code> ของสำเนานี้</p>
    <?php if ($error !== ''): ?><p class="notice danger"><?=h($error)?></p><?php endif ?>
    <form method="post">
        <input type="hidden" name="csrf" value="<?=h(csrfToken())?>">
        <p><label>ชื่อบัญชี<input name="username" autocomplete="username" required></label></p>
        <p><label>รหัสผ่าน<input type="password" name="password" autocomplete="current-password" required></label></p>
        <button type="submit" class="btn green">เข้าสู่ระบบ</button>
    </form>
</section>
<?php pageFoot();
