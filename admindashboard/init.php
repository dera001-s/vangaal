<?php

// Strict session mode: PHP refuses session IDs it did not create itself (blocks session fixation).
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.use_trans_sid', '0');

session_name('vangaal_admin');
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'httponly' => true,
    'samesite' => 'Strict',
    'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
]);
session_start();

// Makes text safe to print inside HTML, so a customer can never inject code.
function e($text): string
{
    return strtr((string) $text, [
        '&' => '&amp;',
        '<' => '&lt;',
        '>' => '&gt;',
        '"' => '&quot;',
        "'" => '&#039;',
    ]);
}

// Reads a text field from a form (trimmed) or a raw one (passwords are never trimmed).
function postText(string $key): string
{
    $v = $_POST[$key] ?? '';
    return is_string($v) ? trim($v) : '';
}

function postRaw(string $key): string
{
    $v = $_POST[$key] ?? '';
    return is_string($v) ? $v : '';
}

// Secret token that proves a form was sent from our own pages.
function csrfToken(): string
{
    if (empty($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

// The session must already hold a token, and the form must send exactly the same one.
function csrfOk(): bool
{
    $known = $_SESSION['csrf'] ?? '';
    $sent  = $_POST['csrf'] ?? '';
    return is_string($known) && $known !== '' && is_string($sent) && hash_equals($known, $sent);
}

// Usernames: 3-50 characters, letters, numbers, dot, dash or underscore.
function validUsername(string $name): bool
{
    $len = strlen($name);
    if ($len < 3 || $len > 50) {
        return false;
    }
    foreach (str_split($name) as $char) {
        if (!ctype_alnum($char) && !in_array($char, ['.', '_', '-'], true)) {
            return false;
        }
    }
    return true;
}

//Rate limiting that lives in the database (clearing cookies does not reset it
function clientIp(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

function attemptKey(string $identifier): string
{
    return substr(strtolower(trim($identifier)), 0, 150);
}

// True when this visitor (by IP) or this account/email has failed too often in the last 15 minutes.
function isBlocked(PDO $pdo, string $bucket, string $identifier, int $maxPerIp, int $maxPerIdentifier): bool
{
    $byIp = $pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE bucket = ? AND ip = ? AND attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)');
    $byIp->execute([$bucket, clientIp()]);
    if ((int) $byIp->fetchColumn() >= $maxPerIp) {
        return true;
    }

    $byId = $pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE bucket = ? AND identifier = ? AND attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)');
    $byId->execute([$bucket, attemptKey($identifier)]);
    return (int) $byId->fetchColumn() >= $maxPerIdentifier;
}

function recordAttempt(PDO $pdo, string $bucket, string $identifier): void
{
    $pdo->prepare('INSERT INTO login_attempts (bucket, ip, identifier) VALUES (?, ?, ?)')
        ->execute([$bucket, clientIp(), attemptKey($identifier)]);

    if (random_int(1, 20) === 1) { // tidy up old rows now and then
        $pdo->exec('DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 1 DAY)');
    }
}

function clearAttempts(PDO $pdo, string $bucket, string $identifier): void
{
    $pdo->prepare('DELETE FROM login_attempts WHERE bucket = ? AND identifier = ?')
        ->execute([$bucket, attemptKey($identifier)]);
}

// Sends visitors who are not logged in (or idle for 2 hours) to the login page.
// It also checks the account is still active and that its password has not changed since login,
// so a removed, disabled or password-reset account loses access at once.
function requireLogin(PDO $pdo): void
{
    $ok = !empty($_SESSION['admin_id']) && time() - ($_SESSION['last_active'] ?? 0) <= 7200;

    if ($ok) {
        $stmt = $pdo->prepare("SELECT auth_version FROM admins WHERE id = ? AND status = 'active'");
        $stmt->execute([(int) $_SESSION['admin_id']]);
        $version = $stmt->fetchColumn();
        $ok = $version !== false && (int) $version === (int) ($_SESSION['auth_version'] ?? -1);
    }

    if (!$ok) {
        session_unset();
        session_destroy();
        header('Location: login.php');
        exit;
    }
    $_SESSION['last_active'] = time();
}
