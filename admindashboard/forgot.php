<?php
// "Forgot password": creates a one-hour reset link for an active account.
require __DIR__ . '/init.php';
require __DIR__ . '/../db.php';
require __DIR__ . '/mailer.php';

csrfToken();
$message = '';
$error   = '';
$link    = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = postText('email');

    if (!csrfOk()) {
        $error = 'Session expired. Please try again.';
    } elseif (isBlocked($pdo, 'forgot', $email, 10, 3)) {
        $error = 'Too many requests. Please wait about 15 minutes and try again.';
    } else {
        recordAttempt($pdo, 'forgot', $email);
        usleep(400000);

        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $stmt = $pdo->prepare("SELECT id FROM admins WHERE email = ? AND status = 'active'");
            $stmt->execute([$email]);
            $id = $stmt->fetchColumn();

            if ($id) {
                [$token, $hash] = newToken();
                $pdo->prepare('UPDATE admins SET invite_token_hash = ?, invite_expires = DATE_ADD(NOW(), INTERVAL 1 HOUR) WHERE id = ?')
                    ->execute([$hash, (int) $id]);
                $url = setupLink($token);

                if (MAIL_MODE === 'screen') {
                    // Showing a reset link on screen is only safe on your own computer.
                    if (isLocalRequest()) {
                        $link = $url;
                    }
                } else {
                    deliverLink($email, $url, 'reset');
                }
            }
        }
        // The same answer whether or not the email has an account.
        $message = 'If that email belongs to an account, a reset link has been prepared.';
        if (MAIL_MODE === 'screen' && !isLocalRequest()) {
            $message .= ' Email sending is not switched on yet, so please ask the site owner for a new link.';
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
    <title>Forgot password | Vangaal Electricals</title>
    <link rel="stylesheet" href="admin.css">
</head>

<body class="login-page">
    <form class="login-box" method="post" autocomplete="off">
        <h1>Forgot password</h1>
        <p class="sub">Enter your account email.</p>
        <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
        <label for="email">Email</label>
        <input id="email" name="email" type="email" required autofocus>
        <?php if ($error): ?><p class="form-error"><?= e($error) ?></p><?php endif; ?>
        <?php if ($message): ?><p class="form-note"><?= e($message) ?></p><?php endif; ?>
        <?php if ($link): ?>
            <div class="link-box">Development mode: open this link to choose a new password (valid 1 hour, works once).
                <code><?= e($link) ?></code>
            </div>
        <?php endif; ?>
        <button class="btn btn-primary btn-block" type="submit">Get reset link</button>
        <p class="login-links"><a href="login.php">Back to login</a></p>
    </form>
</body>

</html>