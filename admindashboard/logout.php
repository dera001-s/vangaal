<?php
require __DIR__ . '/init.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrfOk()) {
    $_SESSION = [];
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 3600, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    session_destroy();
}

header('Location: login.php');
exit;