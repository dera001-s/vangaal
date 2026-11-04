<?php
require __DIR__ . '/init.php';

if (!empty($_SESSION['admin_id'])) {
    header('Location: admin.php');
    exit;
}

require __DIR__ . '/../db.php';
csrfToken();

$error  = '';
$notice = '';
if (isset($_SESSION['login_notice'])) {
    $notice = (string) $_SESSION['login_notice'];
    unset($_SESSION['login_notice']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = postText('username');
    $password = postRaw('password');

    if (!csrfOk()) {
        $error = 'Session expired. Please try again.';
    } elseif (isBlocked($pdo, 'login', $username, 20, 5)) {
        // Counted in the database, so clearing cookies or opening a new browser does not reset it.
        $error = 'Too many attempts. Please wait about 15 minutes and try again.';
    } else {
        // Log in with the username or the email. Only active accounts can log in.
        $stmt = $pdo->prepare(
            "SELECT id, username, password_hash, last_login, auth_version FROM admins
             WHERE status = 'active' AND (username = ? OR email = ?)"
        );
        $stmt->execute([$username, $username]);
        $admin = $stmt->fetch();

        $stored = $admin ? $admin['password_hash'] : null;
        $ok     = false;

        if (is_string($stored) && $stored !== '') {
            $ok = password_verify($password, $stored);
        } else {
            // No account, or no stored password: login can never succeed here.
            // Spend about the same time anyway, so the answer does not reveal whether the account exists.
            password_hash($password, PASSWORD_DEFAULT);
        }

        if ($ok) {
            session_regenerate_id(true);
            $_SESSION['admin_id']       = (int) $admin['id'];
            $_SESSION['admin_name']     = $admin['username'];
            $_SESSION['auth_version']   = (int) $admin['auth_version'];
            $_SESSION['previous_login'] = $admin['last_login'];
            $_SESSION['last_active']    = time();
            $_SESSION['csrf']           = bin2hex(random_bytes(32));
            clearAttempts($pdo, 'login', $username);
            $pdo->prepare('UPDATE admins SET last_login = NOW() WHERE id = ?')->execute([(int) $admin['id']]);
            header('Location: admin.php');
            exit;
        }

        recordAttempt($pdo, 'login', $username);
        usleep(500000);
        $error = 'Wrong username or password.';
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex">
  <title>Admin Login | Vangaal Electricals</title>
  <link rel="stylesheet" href="admin.css">
</head>
<body class="login-page">
  <form class="login-box" method="post" autocomplete="off">
    <h1>Admin Login</h1>
    <p class="sub">Vangaal Electricals</p>
    <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
    <label for="username">Username or email</label>
    <input id="username" name="username" required autofocus>
    <label for="password">Password</label>
    <input id="password" name="password" type="password" required>
    <?php if ($notice): ?><p class="form-note"><?= e($notice) ?></p><?php endif; ?>
    <?php if ($error): ?><p class="form-error"><?= e($error) ?></p><?php endif; ?>
    <button class="btn btn-primary btn-block" type="submit">Log in</button>
    <p class="login-links"><a href="forgot.php">Forgot password?</a></p>
  </form>
</body>
</html>