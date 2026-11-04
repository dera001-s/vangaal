<?php
// Where setup and reset links go.
//   'screen' = no email service yet: the link is shown on screen to the person who created it.
//   Later we switch to real sending (Gmail or the hosting email) inside deliverLink().
const MAIL_MODE = 'screen';

// Set this to your live address before launch, e.g. 'https://vangaalelectricals.com/admindashboard'
// Left empty, the address is worked out from the current request (fine on your own computer only).
const SITE_URL = '';

// A random one-time code. We email/show the code, but store only its hash in the database.
function newToken(): array
{
    $token = bin2hex(random_bytes(32));
    return [$token, hash('sha256', $token)];
}

function setupLink(string $token): string
{
    if (SITE_URL !== '') {
        return rtrim(SITE_URL, '/') . '/setup.php?token=' . $token;
    }
    $https = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $dir   = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
    return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $dir . '/setup.php?token=' . $token;
}

// True only when you are on your own computer. Once SITE_URL is set (live site), this is always false,
// and a visitor arriving through a proxy is never treated as local.
function isLocalRequest(): bool
{
    return SITE_URL === ''
        && empty($_SERVER['HTTP_X_FORWARDED_FOR'])
        && in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
}

// Returns the link when it must be shown on screen, or null once it has been emailed.
function deliverLink(string $email, string $link, string $kind): ?string
{
    if (MAIL_MODE === 'screen') {
        return $link;
    }
    if (SITE_URL === '') {
        throw new RuntimeException('Set SITE_URL in mailer.php before sending emails.');
    }
    throw new RuntimeException('Email sending is not set up yet.');
}