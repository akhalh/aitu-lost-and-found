<?php
// Registration page. Only AITU e-mail, password at least 8 characters,
// password is saved only as a bcrypt hash, all queries are prepared statements.
require 'db.php';

$message = '';
$ok = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name  = trim($_POST['full_name'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    $pass  = $_POST['password'] ?? '';

    // Server-side input checks
    if ($name === '' || mb_strlen($name) > 100) {
        $message = 'Please enter your full name.';
    } elseif (!preg_match('/^[a-z0-9._%+-]+@astanait\.edu\.kz$/', $email)) {
        $message = 'Only AITU e-mail addresses (@astanait.edu.kz) can register.';
    } elseif (strlen($pass) < 8) {
        $message = 'The password must have at least 8 characters.';
    } else {
        // Is this e-mail already registered? (prepared statement)
        $check = $pdo->prepare('SELECT user_id FROM users WHERE email = ?');
        $check->execute([$email]);
        if ($check->fetch()) {
            $message = 'This e-mail is already registered.';
        } else {
            $hash = password_hash($pass, PASSWORD_BCRYPT);   // the password becomes a hash
            $stmt = $pdo->prepare('INSERT INTO users (full_name, email, password_hash, consent_at) VALUES (?, ?, ?, NOW())');
            $stmt->execute([$name, $email, $hash]);           // data is sent separately from SQL
            security_log('REGISTER', 'user_id=' . $pdo->lastInsertId());
            $ok = true;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Регистрация · AITU Lost &amp; Found</title>
<style>
 * { box-sizing: border-box; margin: 0; padding: 0; font-family: -apple-system, "Segoe UI", Roboto, Arial, sans-serif; }
 body { background: linear-gradient(180deg, #eaf1fb 0%, #f4f5f7 320px); display: flex; justify-content: center; padding: 60px 20px; min-height: 100vh; }
 .box { background: #fff; padding: 28px; border-radius: 16px; width: 100%; max-width: 380px; height: fit-content; box-shadow: 0 6px 24px rgba(0,0,0,.07); }
 h2 { font-size: 22px; margin-bottom: 16px; text-align: center; }
 label { font-size: 13px; font-weight: 600; color: #555; }
 input { width: 100%; padding: 11px; margin: 6px 0 14px; border: 1px solid #e1e3e6; border-radius: 10px; font-size: 15px; outline: none; }
 input:focus { border-color: #0071e3; box-shadow: 0 0 0 3px rgba(0,113,227,.12); }
 button { width: 100%; padding: 12px; background: #34c759; color: #fff; border: none; border-radius: 10px; font-size: 15px; font-weight: 600; cursor: pointer; }
 button:hover { background: #2db14e; }
 .msg { margin-bottom: 14px; color: #b00020; background: #fdecee; padding: 10px 12px; border-radius: 10px; font-size: 14px; }
 .ok  { margin-bottom: 14px; color: #1e7b3c; background: #e8f7ed; padding: 10px 12px; border-radius: 10px; font-size: 14px; }
 p { margin-top: 16px; text-align: center; font-size: 14px; }
 a { color: #0071e3; text-decoration: none; }
</style>
</head>
<body>
<div class="box">
  <h2>Регистрация</h2>
  <?php if ($ok): ?>
    <div class="ok">Аккаунт создан. Теперь можно войти.</div>
    <p><a href="login.php">Войти</a></p>
  <?php else: ?>
    <?php if ($message): ?><div class="msg"><?= htmlspecialchars($message) ?></div><?php endif; ?>
    <form method="post">
      <label>Имя и фамилия</label>
      <input name="full_name" maxlength="100" required>
      <label>Почта AITU</label>
      <input name="email" type="email" placeholder="123456@astanait.edu.kz" required>
      <label>Пароль (минимум 8 символов)</label>
      <input name="password" type="password" minlength="8" required>
<label style="display:block;margin:10px 0;font-size:14px">
  <input type="checkbox" name="consent" value="1" required>
  I agree to the processing of my personal data (name, AITU e-mail) for the Lost &amp; Found service, according to Law of RK No. 94-V.
</label>
      <button type="submit">Создать аккаунт</button>
    </form>
    <p><a href="login.php">Уже есть аккаунт? Войти</a> · <a href="index.php">На главную</a></p>
  <?php endif; ?>
</div>
</body>
</html>
