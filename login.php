<?php
// Log in page. Security: prepared statements, password_verify (bcrypt),
// new session ID after login, 5 wrong tries = 15 minutes block, security log.
session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict']);
session_start();
require 'db.php';

// Log out
if (isset($_GET['logout'])) {
    $_SESSION = [];
    session_destroy();
    header('Location: index.php');
    exit;
}

$message = '';
$now = time();
$_SESSION['fails']        = $_SESSION['fails'] ?? 0;
$_SESSION['locked_until'] = $_SESSION['locked_until'] ?? 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($now < $_SESSION['locked_until']) {
        // After 5 wrong tries the login is blocked for 15 minutes
        $message = 'Too many failed attempts. Try again in 15 minutes.';
    } else {
        $email = strtolower(trim($_POST['email'] ?? ''));
        $pass  = $_POST['password'] ?? '';

        // Prepared statement: user input is never put into the SQL text
        $stmt = $pdo->prepare('SELECT user_id, full_name, password_hash, role
                               FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && password_verify($pass, $user['password_hash'])) {
            session_regenerate_id(true); // new session ID after login
            $_SESSION['user_id'] = $user['user_id'];
            $_SESSION['name']    = $user['full_name'];
            $_SESSION['role']    = $user['role'];
            $_SESSION['fails']   = 0;
            security_log('LOGIN_OK', 'user_id=' . $user['user_id']);
            header('Location: index.php'); // go to the main page
            exit;
        } else {
            $_SESSION['fails']++;
            if ($_SESSION['fails'] >= 5) {
                $_SESSION['locked_until'] = $now + 15 * 60;
                $_SESSION['fails'] = 0;
            }
            // The e-mail is not written in clear text, only a short hash
            security_log('LOGIN_FAIL', 'email_hash=' . substr(hash('sha256', $email), 0, 12));
            $message = 'Wrong e-mail or password.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Вход · AITU Lost &amp; Found</title>
<style>
 * { box-sizing: border-box; margin: 0; padding: 0; font-family: -apple-system, "Segoe UI", Roboto, Arial, sans-serif; }
 body { background: linear-gradient(180deg, #eaf1fb 0%, #f4f5f7 320px); display: flex; justify-content: center; padding: 60px 20px; min-height: 100vh; }
 .box { background: #fff; padding: 28px; border-radius: 16px; width: 100%; max-width: 380px; height: fit-content; box-shadow: 0 6px 24px rgba(0,0,0,.07); }
 h2 { font-size: 22px; margin-bottom: 16px; text-align: center; }
 label { font-size: 13px; font-weight: 600; color: #555; }
 input { width: 100%; padding: 11px; margin: 6px 0 14px; border: 1px solid #e1e3e6; border-radius: 10px; font-size: 15px; outline: none; }
 input:focus { border-color: #0071e3; box-shadow: 0 0 0 3px rgba(0,113,227,.12); }
 button { width: 100%; padding: 12px; background: #0071e3; color: #fff; border: none; border-radius: 10px; font-size: 15px; font-weight: 600; cursor: pointer; }
 button:hover { background: #005bb5; }
 .msg { margin-bottom: 14px; color: #b00020; background: #fdecee; padding: 10px 12px; border-radius: 10px; font-size: 14px; }
 p { margin-top: 16px; text-align: center; font-size: 14px; }
 a { color: #0071e3; text-decoration: none; }
</style>
</head>
<body>
<div class="box">
<?php if (isset($_SESSION['user_id'])): ?>
  <h2>Вы уже вошли</h2>
  <p><a href="index.php">На главную</a> · <a href="login.php?logout=1">Выйти</a></p>
<?php else: ?>
  <h2>Вход</h2>
  <?php if ($message): ?><div class="msg"><?= htmlspecialchars($message) ?></div><?php endif; ?>
  <form method="post">
    <label>Почта AITU</label>
    <input name="email" type="email" placeholder="123456@astanait.edu.kz" required>
    <label>Пароль</label>
    <input name="password" type="password" required>
    <button type="submit">Войти</button>
  </form>
  <p><a href="register.php">Нет аккаунта? Регистрация</a> · <a href="index.php">На главную</a></p>
<?php endif; ?>
</div>
</body>
</html>
