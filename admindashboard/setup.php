<?php
// Opened from the one-time link. The person chooses their username (first time) and password.
require __DIR__ . '/init.php';
require __DIR__ . '/../db.php';

$token = $_GET['token'] ?? ($_POST['token'] ?? '');
$row   = false;

if (is_string($token) && strlen($token) === 64 && ctype_xdigit($token)) {
    $stmt = $pdo->prepare(
        "SELECT id, username, email FROM admins
         WHERE invite_token_hash = ? AND invite_expires > NOW() AND status IN ('invited', 'active')"
    );
    $stmt->execute([hash('sha256', $token)]);
    $row = $stmt->fetch();
}

csrfToken();
$error = '';

if ($row && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $hasName  = $row['username'] !== null && $row['username'] !== '';
    $username = $hasName ? $row['username'] : postText('username');
    $password = postRaw('password');
    $confirm  = postRaw('confirm');

    if (!csrfOk()) {
        $error = 'Session expired. Please try again.';
    } elseif (!validUsername($username)) {
        $error = 'Username must be 3-50 characters: letters, numbers, dot, dash or underscore.';
    } elseif (strlen($password) < 10) {
        $error = 'Password must be at least 10 characters.';
    } elseif ($password !== $confirm) {
        $error = 'The two passwords do not match.';
    } elseif (strtolower($password) === strtolower($username)) {
        $error = 'Password must not be the same as the username.';
    } else {
        try {
            $upd = $pdo->prepare(
                "UPDATE admins
                 SET username = ?, password_hash = ?, status = 'active', invite_token_hash = NULL, invite_expires = NULL,
                     auth_version = auth_version + 1
                 WHERE id = ? AND invite_token_hash = ?"
            );
            $upd->execute([$username, password_hash($password, PASSWORD_DEFAULT), (int) $row['id'], hash('sha256', $token)]);

            if ($upd->rowCount() === 1) {
                $_SESSION['login_notice'] = 'Your account is ready. Please log in.';
                header('Location: login.php');
                exit;
            }
            $error = 'This link has already been used.';
        } catch (PDOException $ex) {
            if ((string) $ex->getCode() === '23000') {
                $error = 'That username is already taken. Please choose another.';
            } else {
                throw $ex;
            }
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex">
  <title>Set up your account | Vangaal Electricals</title>
  <link rel="stylesheet" href="admin.css">
</head>
<body class="login-page">
<?php if (!$row): ?>
  <div class="login-box">
    <h1>Link not valid</h1>
    <p class="sub">This link is invalid, has expired, or has already been used.</p>
    <p class="login-links"><a href="login.php">Go to the login page</a></p>
  </div>
<?php else: ?>
  <form class="login-box" method="post" autocomplete="off">
    <h1>Set up your account</h1>
    <p class="sub">Vangaal Electricals<?= $row['email'] ? ' &middot; ' . e($row['email']) : '' ?></p>
    <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
    <input type="hidden" name="token" value="<?= e($token) ?>">

    <?php if ($row['username'] !== null && $row['username'] !== ''): ?>
      <p class="sub">Choose a new password for <strong><?= e($row['username']) ?></strong>.</p>
    <?php else: ?>
      <label for="username">Choose a username</label>
      <input id="username" name="username" required autofocus value="<?= e(postText('username')) ?>">
    <?php endif; ?>

    <label for="password">Password (10+ characters)</label>
    <input id="password" name="password" type="password" required autocomplete="new-password">
    <label for="confirm">Confirm password</label>
    <input id="confirm" name="confirm" type="password" required autocomplete="new-password">

    <?php if ($error): ?><p class="form-error"><?= e($error) ?></p><?php endif; ?>
    <button class="btn btn-primary btn-block" type="submit">Save and continue</button>
  </form>
<?php endif; ?>
</body>
</html>