<?php
require __DIR__ . '/init.php';
require __DIR__ . '/../db.php';
requireLogin($pdo);
header('Cache-Control: no-store');

csrfToken();

// Only the owner may delete messages. The role is read from the database on every request.
$roleStmt = $pdo->prepare('SELECT role FROM admins WHERE id = ?');
$roleStmt->execute([(int) $_SESSION['admin_id']]);
$isOwner = $roleStmt->fetchColumn() === 'owner';

// ---------- Helpers ----------
function digitsOnly(string $text): string
{
  $out = '';
  foreach (str_split($text) as $char) {
    if (ctype_digit($char)) {
      $out .= $char;
    }
  }
  return $out;
}

function csvSafe($value, bool $allowPlus = false): string
{
  $value = (string) $value;
  $first = $value === '' ? '' : $value[0];
  $risky = ['=', '-', '@', "\t", "\r"];
  if (!$allowPlus) {
    $risky[] = '+';
  }
  return in_array($first, $risky, true) ? "'" . $value : $value;
}

function backUrl(string $view, string $q, int $page): string
{
  $parts = [];
  if ($view !== 'all') {
    $parts['view'] = $view;
  }
  if ($q !== '') {
    $parts['q'] = $q;
  }
  if ($page > 1) {
    $parts['page'] = $page;
  }
  return 'admin.php' . ($parts ? '?' . http_build_query($parts) : '');
}

function hiddenFields(int $id, string $action, string $view, string $q, int $page): string
{
  return '<input type="hidden" name="action" value="' . e($action) . '">'
    . '<input type="hidden" name="id" value="' . $id . '">'
    . '<input type="hidden" name="csrf" value="' . e($_SESSION['csrf']) . '">'
    . '<input type="hidden" name="view" value="' . e($view) . '">'
    . '<input type="hidden" name="q" value="' . e($q) . '">'
    . '<input type="hidden" name="page" value="' . $page . '">';
}

$views = ['all' => 'All messages', 'pending' => 'Pending messages', 'attended' => 'Attended messages'];

function readView(array $source, array $views): string
{
  $v = $source['view'] ?? 'all';
  return is_string($v) && isset($views[$v]) ? $v : 'all';
}

function readText(array $source, string $key): string
{
  $v = $source[$key] ?? '';
  return is_string($v) ? trim($v) : '';
}

// ---------- Actions: mark attended / mark pending / delete ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (csrfOk()) {
    $id     = (int) ($_POST['id'] ?? 0);
    $action = $_POST['action'] ?? '';

    if ($action === 'delete' && $isOwner) {
      $pdo->prepare('DELETE FROM contacts WHERE id = ?')->execute([$id]);
    } elseif ($action === 'attend') {
      $pdo->prepare('UPDATE contacts SET attended = 1, attendedAt = NOW(), attendedBy = ?, updatedAt = NOW() WHERE id = ?')
        ->execute([$_SESSION['admin_name'], $id]);
    } elseif ($action === 'pending') {
      $pdo->prepare('UPDATE contacts SET attended = 0, attendedAt = NULL, attendedBy = NULL, updatedAt = NOW() WHERE id = ?')
        ->execute([$id]);
    }
  }
  header('Location: ' . backUrl(readView($_POST, $views), readText($_POST, 'q'), (int) ($_POST['page'] ?? 1)));
  exit;
}

// ---------- Export to CSV (opens in Excel) ----------
if (isset($_GET['export'])) {
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="messages-' . date('Y-m-d') . '.csv"');
  $out = fopen('php://output', 'w');
  fwrite($out, "\xEF\xBB\xBF");
  fputcsv($out, ['ID', 'Name', 'Email', 'Phone', 'Message', 'Status', 'Attended by', 'Attended at', 'Received']);
  $all = $pdo->query('SELECT id, name, email, phone, message, attended, attendedBy, attendedAt, createdAt FROM contacts ORDER BY id DESC');
  foreach ($all as $r) {
    fputcsv($out, [
      $r['id'],
      csvSafe($r['name']),
      csvSafe($r['email']),
      csvSafe($r['phone'], true),
      csvSafe($r['message']),
      $r['attended'] ? 'Attended' : 'Pending',
      csvSafe($r['attendedBy']),
      $r['attendedAt'],
      $r['createdAt'],
    ]);
  }
  exit;
}

// ---------- Counts for the sidebar and stat cards ----------
$counts   = $pdo->query('SELECT COUNT(*) AS total, SUM(attended = 0) AS pending, SUM(attended = 1) AS done FROM contacts')->fetch();
$cTotal   = (int) $counts['total'];
$cPending = (int) $counts['pending'];
$cDone    = (int) $counts['done'];

// ---------- List: filter, search, pages ----------
$view    = readView($_GET, $views);
$q       = readText($_GET, 'q');
$perPage = 20;
$conds   = [];
$params  = [];

if ($view === 'pending') {
  $conds[] = 'attended = 0';
} elseif ($view === 'attended') {
  $conds[] = 'attended = 1';
}
if ($q !== '') {
  $conds[] = '(name LIKE ? OR email LIKE ? OR phone LIKE ? OR message LIKE ?)';
  $like = '%' . $q . '%';
  array_push($params, $like, $like, $like, $like);
}
$where = $conds ? 'WHERE ' . implode(' AND ', $conds) : '';

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM contacts $where");
$countStmt->execute($params);
$found  = (int) $countStmt->fetchColumn();
$pages  = max(1, (int) ceil($found / $perPage));
$page   = min(max(1, (int) ($_GET['page'] ?? 1)), $pages);
$offset = ($page - 1) * $perPage;

$stmt = $pdo->prepare("SELECT * FROM contacts $where ORDER BY id DESC LIMIT $perPage OFFSET $offset");
$stmt->execute($params);
$rows = $stmt->fetchAll();
?>
<!doctype html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex">
  <title><?= e($views[$view]) ?> | Vangaal Electricals</title>
  <link rel="stylesheet" href="admin.css">
</head>

<body>
  <div class="layout">

    <?php $active = $view;
    require __DIR__ . '/sidebar.php'; ?>

    <main class="content">
      <header class="page-head">
        <h1><?= e($views[$view]) ?></h1>
        <form class="search" method="get">
          <?php if ($view !== 'all'): ?><input type="hidden" name="view" value="<?= e($view) ?>"><?php endif; ?>
          <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search name, email, phone or message">
          <button class="btn btn-primary" type="submit">Search</button>
        </form>
      </header>

      <section class="stats">
        <div class="stat"><span class="stat-num"><?= $cTotal ?></span><span class="stat-label">Total messages</span></div>
        <div class="stat stat-amber"><span class="stat-num"><?= $cPending ?></span><span class="stat-label">Pending</span></div>
        <div class="stat stat-green"><span class="stat-num"><?= $cDone ?></span><span class="stat-label">Attended</span></div>
      </section>

      <p class="count"><?= $found ?> message<?= $found === 1 ? '' : 's' ?><?= $q !== '' ? ' found' : '' ?></p>

      <?php if (!$rows): ?>
        <p class="empty">No messages here.</p>
      <?php endif; ?>

      <?php foreach ($rows as $r):
        $done   = (int) $r['attended'] === 1;
        $digits = digitsOnly($r['phone']);
        $wa     = str_starts_with($digits, '0') ? '234' . substr($digits, 1) : $digits;
        $tel    = (str_starts_with($r['phone'], '+') ? '+' : '') . $digits;
        $id     = (int) $r['id'];
      ?>
        <article class="card <?= $done ? 'is-done' : 'is-pending' ?>">
          <div class="card-head">
            <h3><?= e($r['name']) ?></h3>
            <span class="badge <?= $done ? 'badge-green' : 'badge-amber' ?>"><?= $done ? 'Attended' : 'Pending' ?></span>
          </div>
          <p class="date">Received <?= e(date('d M Y, g:i A', strtotime($r['createdAt']))) ?></p>

          <div class="contact">
            <a href="tel:<?= e($tel) ?>"><?= e($r['phone']) ?></a>
            <a href="mailto:<?= e($r['email']) ?>"><?= e($r['email']) ?></a>
            <a href="https://wa.me/<?= e($wa) ?>" target="_blank" rel="noopener">WhatsApp</a>
          </div>

          <p class="message"><?= e($r['message']) ?></p>

          <?php if ($done): ?>
            <p class="attended-by">Attended by <strong><?= e($r['attendedBy']) ?></strong><?= $r['attendedAt'] ? ' on ' . e(date('d M Y, g:i A', strtotime($r['attendedAt']))) : '' ?></p>
          <?php endif; ?>

          <div class="card-actions">
            <form method="post">
              <?= hiddenFields($id, $done ? 'pending' : 'attend', $view, $q, $page) ?>
              <button class="btn <?= $done ? 'btn-outline' : 'btn-success' ?>" type="submit"><?= $done ? 'Mark as pending' : 'Mark as attended' ?></button>
            </form>
            <?php if ($isOwner): ?>
              <form method="post" onsubmit="return confirm('Delete this message permanently?');">
                <?= hiddenFields($id, 'delete', $view, $q, $page) ?>
                <button class="btn btn-danger" type="submit">Delete</button>
              </form>
            <?php endif; ?>
          </div>
        </article>
      <?php endforeach; ?>

      <?php if ($pages > 1): ?>
        <nav class="pager">
          <?php if ($page > 1): ?><a class="btn btn-outline" href="<?= e(backUrl($view, $q, $page - 1)) ?>">&larr; Newer</a><?php endif; ?>
          <span>Page <?= $page ?> of <?= $pages ?></span>
          <?php if ($page < $pages): ?><a class="btn btn-outline" href="<?= e(backUrl($view, $q, $page + 1)) ?>">Older &rarr;</a><?php endif; ?>
        </nav>
      <?php endif; ?>
    </main>

  </div>
</body>

</html>