<?php
require __DIR__ . '/init.php';
require __DIR__ . '/../db.php';
require __DIR__ . '/mailer.php';
requireLogin($pdo);
header('Cache-Control: no-store');
csrfToken();

// ---------- Who is allowed here ----------
// The owner manages users. The developer may only set up the owner's first invite,
// and only until the owner has finished setup.
$meId = (int) $_SESSION['admin_id'];
$roleStmt = $pdo->prepare('SELECT role FROM admins WHERE id = ?');
$roleStmt->execute([$meId]);
$myRole = (string) $roleStmt->fetchColumn();

$ownerActive = (int) $pdo->query("SELECT COUNT(*) FROM admins WHERE role = 'owner' AND status = 'active'")->fetchColumn() > 0;
$anyOwner    = (int) $pdo->query("SELECT COUNT(*) FROM admins WHERE role = 'owner'")->fetchColumn() > 0;

if (!($myRole === 'owner' || ($myRole === 'developer' && !$ownerActive))) {
    header('Location: admin.php');
    exit;
}

$inviteRoles = $myRole === 'owner' ? ['staff'] : ($anyOwner ? [] : ['owner']);

function canResend(string $me, array $t, bool $ownerActive): bool
{
    if ($me === 'owner') {
        return $t['role'] === 'staff';
    }
    return $me === 'developer' && !$ownerActive && $t['role'] === 'owner' && $t['status'] === 'invited';
}

function canToggle(string $me, array $t): bool
{
    return $me === 'owner'
        && in_array($t['role'], ['staff', 'developer'], true)
        && in_array($t['status'], ['active', 'disabled'], true);
}

function canRemove(string $me, array $t): bool
{
    if ($me === 'owner') {
        return $t['role'] === 'staff';
    }
    return $me === 'developer' && $t['role'] === 'owner' && $t['status'] === 'invited';
}

// ---------- Helpers ----------
function flash(string $type, string $text): void
{
    $_SESSION['flash'] = ['type' => $type, 'text' => $text];
}

function userFields(string $action, int $id = 0): string
{
    return '<input type="hidden" name="action" value="' . e($action) . '">'
        . '<input type="hidden" name="id" value="' . $id . '">'
        . '<input type="hidden" name="csrf" value="' . e($_SESSION['csrf']) . '">';
}

// Creates a fresh one-time link for a user and hands it over (on screen for now).
function issueLink(PDO $pdo, int $id, string $email, int $hours, string $kind): void
{
    [$token, $hash] = newToken();
    $pdo->prepare("UPDATE admins SET invite_token_hash = ?, invite_expires = DATE_ADD(NOW(), INTERVAL $hours HOUR) WHERE id = ?")
        ->execute([$hash, $id]);

    $link = deliverLink($email, setupLink($token), $kind);
    if ($link !== null) {
        $_SESSION['flash_link'] = ['email' => $email, 'link' => $link, 'hours' => $hours];
    } else {
        flash('ok', 'Link emailed to ' . $email . '.');
    }
}

             // validations
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfOk()) {
        flash('error', 'Session expired. Please try again.');
    } else {
        $action = postText('action');
        $id     = (int) ($_POST['id'] ?? 0);

        if ($action === 'invite') {
            $email = postText('email');
            $role  = postText('role');

            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 150) {
                flash('error', 'Enter a valid email address.');
            } elseif (!in_array($role, $inviteRoles, true)) {
                flash('error', 'You cannot invite that role.');
            } else {
                try {
                    $pdo->prepare("INSERT INTO admins (email, role, status, invited_by) VALUES (?, ?, 'invited', ?)")
                        ->execute([$email, $role, $meId]);
                    issueLink($pdo, (int) $pdo->lastInsertId(), $email, 48, 'invite');
                } catch (PDOException $ex) {
                    if ((string) $ex->getCode() === '23000') {
                        flash('error', 'That email already has an account or invitation.');
                    } else {
                        throw $ex;
                    }
                }
            }
        } else {
            $stmt = $pdo->prepare('SELECT id, email, role, status FROM admins WHERE id = ?');
            $stmt->execute([$id]);
            $t = $stmt->fetch();

            if (!$t || $id === $meId) {
                flash('error', 'User not found.');
            } elseif ($action === 'resend' && canResend($myRole, $t, $ownerActive) && !empty($t['email'])) {
                $isInvite = $t['status'] === 'invited';
                issueLink($pdo, $id, $t['email'], $isInvite ? 48 : 24, $isInvite ? 'invite' : 'reset');
            } elseif ($action === 'toggle' && canToggle($myRole, $t)) {
                $new = $t['status'] === 'active' ? 'disabled' : 'active';
                $pdo->prepare('UPDATE admins SET status = ? WHERE id = ?')->execute([$new, $id]);
                flash('ok', $new === 'disabled' ? 'Access disabled.' : 'Access restored.');
            } elseif ($action === 'remove' && canRemove($myRole, $t)) {
                $pdo->prepare('DELETE FROM admins WHERE id = ?')->execute([$id]);
                flash('ok', 'User removed.');
            } else {
                flash('error', 'You are not allowed to do that.');
            }
        }
    }
    header('Location: users.php');
    exit;
}

$flash     = $_SESSION['flash'] ?? null;
$flashLink = $_SESSION['flash_link'] ?? null;
unset($_SESSION['flash'], $_SESSION['flash_link']);

if ($myRole === 'owner') {
    $users = $pdo->query("SELECT * FROM admins ORDER BY FIELD(role, 'developer', 'owner', 'staff'), id")->fetchAll();
} else {
    $users = $pdo->query("SELECT * FROM admins WHERE role = 'owner' ORDER BY id")->fetchAll();
}

$roleLabel   = ['developer' => 'Developer', 'owner' => 'Owner', 'staff' => 'Staff'];
$roleBadge   = ['developer' => 'badge-green', 'owner' => 'badge-blue', 'staff' => 'badge-grey'];
$statusLabel = ['active' => 'Active', 'invited' => 'Invited', 'disabled' => 'Disabled'];
$statusBadge = ['active' => 'badge-green', 'invited' => 'badge-amber', 'disabled' => 'badge-red'];
$pageTitle   = $myRole === 'owner' ? 'Manage users' : 'Owner invite';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex">
  <title><?= e($pageTitle) ?> | Vangaal Electricals</title>
  <link rel="stylesheet" href="admin.css">
</head>
<body>
<div class="layout">

  <?php $active = 'users'; require __DIR__ . '/sidebar.php'; ?>

  <main class="content">
    <header class="page-head">
      <h1><?= e($pageTitle) ?></h1>
    </header>

    <?php if ($flash): ?>
      <p class="flash <?= $flash['type'] === 'ok' ? 'flash-ok' : 'flash-error' ?>"><?= e($flash['text']) ?></p>
    <?php endif; ?>

    <?php if ($flashLink): ?>
      <div class="link-box">
        Setup link for <strong><?= e($flashLink['email']) ?></strong> (valid <?= (int) $flashLink['hours'] ?> hours, works once).
        Email sending is switched off, so copy this link and send it to them yourself. It will not be shown again.
        <code><?= e($flashLink['link']) ?></code>
      </div>
    <?php endif; ?>

    <?php if ($inviteRoles): ?>
      <section class="panel">
        <h2><?= $myRole === 'owner' ? 'Invite someone' : 'Invite the owner' ?></h2>
        <form method="post" autocomplete="off">
          <?= userFields('invite') ?>
          <div class="form-grid">
            <div class="field">
              <label for="invite-email">Email address</label>
              <input id="invite-email" name="email" type="email" required>
            </div>
            <div class="field">
              <label for="invite-role">Role</label>
              <select id="invite-role" name="role">
                <?php foreach ($inviteRoles as $r): ?>
                  <option value="<?= e($r) ?>"><?= e($roleLabel[$r]) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <button class="btn btn-primary" type="submit">Create invite link</button>
          </div>
          <p class="hint">The person opens the link, chooses their own username and password, and can then log in. Nobody else ever sees their password.<?= $myRole === 'owner' ? ' Staff can work with messages but cannot delete them or manage users.' : ' Once the owner has finished setup, this page closes to you.' ?></p>
        </form>
      </section>
    <?php elseif ($myRole === 'developer'): ?>
      <p class="hint">The owner has been invited. You can send a new link below if the first one expires.</p>
    <?php endif; ?>

    <?php foreach ($users as $u):
        $uid     = (int) $u['id'];
        $isMe    = $uid === $meId;
        $role    = $u['role'];
        $state   = $u['status'];
        $expired = $state === 'invited' && !empty($u['invite_expires']) && strtotime($u['invite_expires']) < time();
    ?>
      <article class="card user-card">
        <div class="card-head">
          <h3><?= e($u['username'] ?: 'Not set up yet') ?><?= $isMe ? ' (you)' : '' ?></h3>
          <span>
            <span class="badge <?= e($roleBadge[$role] ?? 'badge-grey') ?>"><?= e($roleLabel[$role] ?? $role) ?></span>
            <span class="badge <?= e($statusBadge[$state] ?? 'badge-grey') ?>"><?= e($statusLabel[$state] ?? $state) ?></span>
          </span>
        </div>
        <p class="date">
          <?= e($u['email'] ?: 'No email on file') ?>
          &middot; Added <?= e(date('d M Y, g:i A', strtotime($u['created_at']))) ?>
          <?php if ($state === 'invited'): ?>
            &middot; <?= $expired ? 'Invite link expired' : 'Waiting for setup, link expires ' . e(date('d M Y, g:i A', strtotime($u['invite_expires']))) ?>
          <?php else: ?>
            &middot; Last login: <?= !empty($u['last_login']) ? e(date('d M Y, g:i A', strtotime($u['last_login']))) : 'never' ?>
          <?php endif; ?>
        </p>

        <?php if (!$isMe): ?>
          <div class="card-actions user-actions">
            <?php if (canResend($myRole, $u, $ownerActive) && !empty($u['email'])): ?>
              <form method="post">
                <?= userFields('resend', $uid) ?>
                <button class="btn btn-outline" type="submit"><?= $state === 'invited' ? 'Send new link' : 'Send reset link' ?></button>
              </form>
            <?php endif; ?>

            <?php if (canToggle($myRole, $u)): ?>
              <form method="post" onsubmit="return confirm('<?= $state === 'active' ? 'Disable' : 'Restore' ?> access for this user?');">
                <?= userFields('toggle', $uid) ?>
                <button class="btn btn-outline" type="submit"><?= $state === 'active' ? 'Disable access' : 'Restore access' ?></button>
              </form>
            <?php endif; ?>

            <?php if (canRemove($myRole, $u)): ?>
              <form method="post" onsubmit="return confirm('Remove this user permanently?');">
                <?= userFields('remove', $uid) ?>
                <button class="btn btn-danger" type="submit">Remove</button>
              </form>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </article>
    <?php endforeach; ?>
  </main>

</div>
</body>
</html>