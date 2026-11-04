<?php
          // Sidebar shared by the admin pages. Needs $pdo and $active ('all', 'pending', 'attended' or 'users').
        if (!isset($pdo, $active, $_SESSION['admin_id'])) {
         http_response_code(403);
       exit;
       }

      $sbCounts = $pdo->query('SELECT COUNT(*) AS total, SUM(attended = 0) AS pending, SUM(attended = 1) AS done FROM contacts')->fetch();

      $sbRoleStmt = $pdo->prepare('SELECT role FROM admins WHERE id = ?');
        $sbRoleStmt->execute([(int) $_SESSION['admin_id']]);
        $sbRole = (string) $sbRoleStmt->fetchColumn();

         // The owner manages users. The developer only sees a link while the owner has not finished setup.
      $sbOwnerActive = (int) $pdo->query("SELECT COUNT(*) FROM admins WHERE role = 'owner' AND status = 'active'")->fetchColumn() > 0;
      $sbShowUsers   = $sbRole === 'owner' || ($sbRole === 'developer' && !$sbOwnerActive);
      $sbUsersLabel  = $sbRole === 'owner' ? 'Manage users' : 'Owner invite';
?>
  <aside class="sidebar">
    <div class="brand">Vangaal<span>Electricals</span></div>

    <nav class="menu">
      <a href="admin.php" class="<?= $active === 'all' ? 'active' : '' ?>">All messages <span class="pill"><?= (int) $sbCounts['total'] ?></span></a>
      <a href="admin.php?view=pending" class="<?= $active === 'pending' ? 'active' : '' ?>">Pending <span class="pill pill-amber"><?= (int) $sbCounts['pending'] ?></span></a>
      <a href="admin.php?view=attended" class="<?= $active === 'attended' ? 'active' : '' ?>">Attended <span class="pill pill-green"><?= (int) $sbCounts['done'] ?></span></a>
      <a href="admin.php?export=1">Export CSV</a>
      <?php if ($sbShowUsers): ?>
        <a href="users.php" class="<?= $active === 'users' ? 'active' : '' ?>"><?= e($sbUsersLabel) ?></a>
      <?php endif; ?>
    </nav>

    <div class="side-foot">
      <p>Logged in as <strong><?= e($_SESSION['admin_name']) ?></strong></p>
      <?php if (!empty($_SESSION['previous_login'])): ?>
        <p>Last login: <?= e(date('d M Y, g:i A', strtotime($_SESSION['previous_login']))) ?></p>
      <?php endif; ?>
      <form method="post" action="logout.php">
        <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
        <button class="btn btn-light btn-block" type="submit">Log out</button>
      </form>
    </div>
  </aside>