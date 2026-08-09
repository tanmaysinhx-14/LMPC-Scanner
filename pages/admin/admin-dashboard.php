<?php
declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';
$bootstrapData = bootstrapAccounts(['required_roles' => ['admin']]);
extract($bootstrapData);

$viewer = sessionUser() ?? [];
$stats = getIssueStats($db instanceof PDO ? $db : null);
$feed = ['items' => [], 'pagination' => ['total' => 0]];
$userStats = ['total' => 0, 'active' => 0, 'citizen' => 0, 'worker' => 0, 'admin' => 0];
$aiStats = ['analysed' => 0, 'average_confidence' => 0];
$assignmentCount = 0;

if ($db instanceof PDO) {
  try {
    $feed = fetchIssueFeed($db, ['page' => 1, 'limit' => 10, 'sort' => 'hot']);
    $userRow = $db->query(
      "SELECT COUNT(*) AS total,
              SUM(is_active = 1) AS active,
              SUM(role = 'citizen') AS citizen,
              SUM(role = 'worker') AS worker,
              SUM(role = 'admin') AS admin
         FROM users"
    )->fetch(PDO::FETCH_ASSOC) ?: [];
    foreach ($userStats as $key => $value) $userStats[$key] = (int) ($userRow[$key] ?? 0);
    $aiStats = $db->query('SELECT COUNT(*) AS analysed, COALESCE(AVG(confidence), 0) AS average_confidence FROM issue_ai_analyses')->fetch(PDO::FETCH_ASSOC) ?: $aiStats;
    $assignmentCount = (int) $db->query('SELECT COUNT(*) FROM assignments WHERE completed_at IS NULL')->fetchColumn();
  } catch (Throwable $exception) {
    error_log('Admin dashboard data failed: ' . $exception->getMessage());
  }
}

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$statusOptions = ['pending', 'acknowledged', 'in_progress', 'resolved', 'rejected'];
$displayStatus = static fn (?string $status): string => $status === 'pending' || $status === 'acknowledged' ? 'Open' : issueStatusLabel($status);
$formatDate = static fn (?string $date): string => $date ? date('M d, Y · H:i', strtotime($date)) : 'Unknown date';
?>
<?php require_once __DIR__ . '/../../components/header.php'; ?>

<body class="app-body">
  <div class="app-shell">
    <aside id="adminSidebar" class="app-sidebar" data-app-sidebar aria-label="Administrator navigation">
      <div class="app-sidebar-brand">
        <span class="app-brand-mark"><i class="fas fa-city"></i></span>
        <span>CivicConnect<small>Administrator console</small></span>
        <button type="button" class="app-sidebar-close" data-sidebar-close aria-label="Close navigation"><i class="fas fa-xmark"></i></button>
      </div>
      <div class="app-sidebar-label">Platform</div>
      <nav class="nav flex-column gap-1">
        <a class="nav-link active" href="admin-dashboard.php"><i class="fas fa-chart-pie fa-fw"></i><span>Dashboard</span></a>
        <a class="nav-link" href="../citizen/public-feed.php"><i class="fas fa-layer-group fa-fw"></i><span>Community feed</span></a>
        <a class="nav-link" href="../heatmap/"><i class="fas fa-map-location-dot fa-fw"></i><span>City pulse</span></a>
        <a class="nav-link" href="work-management.php"><i class="fas fa-briefcase fa-fw"></i><span>Work management</span><span class="nav-count"><?= $assignmentCount ?></span></a>
        <a class="nav-link" href="analytics.php"><i class="fas fa-chart-line fa-fw"></i><span>Analytics</span></a>
        <a class="nav-link" href="../citizen/public-feed.php?sort=new"><i class="fas fa-bolt fa-fw"></i><span>Latest issues</span><span class="nav-count"><?= (int) ($stats['reported'] ?? 0) ?></span></a>
        <a class="nav-link" href="../account/profile.php"><i class="fas fa-user fa-fw"></i><span>Profile</span></a>
      </nav>
      <div class="app-sidebar-spacer"></div>
      <div class="app-sidebar-user"><span class="app-avatar"><?= $e(strtoupper(substr((string) ($viewer['name'] ?? 'A'), 0, 1))) ?></span><div><strong><?= $e($viewer['name'] ?? 'Administrator') ?></strong><small>Full platform access</small></div></div>
      <a class="nav-link text-danger mt-2" href="../logout/"><i class="fas fa-arrow-right-from-bracket fa-fw"></i><span>Sign out</span></a>
    </aside>
    <div class="app-sidebar-backdrop" data-sidebar-backdrop="adminSidebar"></div>

    <main class="app-content">
      <header class="app-topbar">
        <div class="d-flex align-items-center gap-3"><button class="app-menu-toggle" data-sidebar-toggle="adminSidebar" aria-controls="adminSidebar" aria-expanded="true" aria-label="Toggle navigation"><i class="fas fa-bars"></i></button><div><div class="eyebrow">Platform overview</div><h1>Admin dashboard</h1></div></div>
        <div class="d-flex align-items-center gap-2"><a class="btn btn-sm btn-outline-secondary" href="../citizen/public-feed.php"><i class="fas fa-eye me-1"></i>Public view</a><a class="btn btn-sm btn-primary" href="../citizen/public-feed.php?sort=hot"><i class="fas fa-traffic-light me-1"></i>Issue oversight</a></div>
      </header>

      <div class="container-fluid px-3 px-lg-4 py-4">
        <section class="dashboard-hero mb-4"><div><span class="hero-kicker"><i class="fas fa-lock me-1"></i>Administrator access</span><h2>One view of the whole city.</h2><p>Monitor participation, issue resolution, AI processing, and the work still waiting for action.</p></div><div class="hero-art"><span></span><span></span><span></span></div></section>

        <div class="row g-3 mb-4">
          <div class="col-6 col-xl-3"><div class="metric-card metric-primary"><div class="metric-icon"><i class="fas fa-users"></i></div><div><span class="metric-label">Total users</span><strong><?= formatNumber($userStats['total']) ?></strong><small><?= formatNumber($userStats['active']) ?> active accounts</small></div></div></div>
          <div class="col-6 col-xl-3"><div class="metric-card metric-danger"><div class="metric-icon"><i class="fas fa-road"></i></div><div><span class="metric-label">Tracked issues</span><strong><?= formatNumber((int) ($stats['total'] ?? 0)) ?></strong><small><?= formatNumber((int) ($stats['open'] ?? 0)) ?> currently open</small></div></div></div>
          <div class="col-6 col-xl-3"><div class="metric-card metric-success"><div class="metric-icon"><i class="fas fa-chart-line"></i></div><div><span class="metric-label">Resolution rate</span><strong><?= (int) ($stats['total'] ?? 0) > 0 ? number_format(((int) $stats['resolved'] / (int) $stats['total']) * 100, 0) : 0 ?>%</strong><small><?= formatNumber((int) ($stats['resolved'] ?? 0)) ?> issues resolved</small></div></div></div>
          <div class="col-6 col-xl-3"><div class="metric-card metric-warning"><div class="metric-icon"><i class="fas fa-robot"></i></div><div><span class="metric-label">AI analyses</span><strong><?= formatNumber((int) ($aiStats['analysed'] ?? 0)) ?></strong><small><?= number_format((float) ($aiStats['average_confidence'] ?? 0) * 100, 0) ?>% average confidence</small></div></div></div>
        </div>

        <div class="row g-4 mb-4">
          <div class="col-12 col-xl-8"><section class="data-card h-100"><div class="data-card-header"><div><span class="eyebrow">Cross-platform activity</span><h2>Highest priority issues</h2></div><span class="count-badge"><?= (int) ($feed['pagination']['total'] ?? 0) ?> total</span></div><?php if ($feed['items'] === []): ?><div class="empty-state"><i class="fas fa-inbox"></i><h3>No issues have been reported</h3><p>Issue activity will appear here as citizens submit reports.</p></div><?php else: ?><div class="staff-issue-list"><?php foreach ($feed['items'] as $issue): ?><article class="staff-issue-row" data-issue-row="<?= (int) $issue['id'] ?>"><div class="staff-issue-priority"><span><?= number_format((float) $issue['priority_score'], 0) ?></span><small>priority</small></div><div class="staff-issue-main"><div class="d-flex flex-wrap gap-2 align-items-center"><span class="pill pill-category"><?= $e(issueCategoryLabel($issue['category'])) ?></span><span class="pill pill-<?= $e(issueStatusClass($issue['status'])) ?>" data-issue-status><?= $e($displayStatus($issue['status'])) ?></span></div><h3><?= $e($issue['title'] ?: issueCategoryLabel($issue['category'])) ?></h3><p><i class="fas fa-location-dot me-1"></i><?= $e($issue['address'] ?: 'Location recorded') ?> <span class="mx-1">·</span> <?= $e($formatDate($issue['created_at'])) ?></p><small><?= (int) $issue['report_count'] ?> reports · <?= (int) $issue['image_count'] ?> photos · <?= (int) $issue['upvote_count'] ?> upvotes</small></div><div class="staff-issue-actions"><select class="form-select form-select-sm" data-status-select="<?= (int) $issue['id'] ?>" aria-label="Change issue status"><?php foreach ($statusOptions as $option): ?><option value="<?= $e($option) ?>" <?= $issue['status'] === $option ? 'selected' : '' ?>><?= $e(issueStatusLabel($option)) ?></option><?php endforeach; ?></select><button class="btn btn-sm btn-primary" type="button" data-status-save="<?= (int) $issue['id'] ?>">Save</button><a class="btn btn-sm btn-link" href="../citizen/public-feed.php?issue=<?= (int) $issue['id'] ?>">Details</a></div></article><?php endforeach; ?></div><?php endif; ?></section></div>
          <div class="col-12 col-xl-4"><section class="data-card h-100"><div class="data-card-header"><div><span class="eyebrow">People</span><h2>Account distribution</h2></div><i class="fas fa-users text-primary"></i></div><div class="stacked-list"><div class="stacked-list-row"><span class="app-avatar avatar-soft"><i class="fas fa-user"></i></span><div><strong>Citizens</strong><small>People reporting issues</small></div><strong class="ms-auto"><?= formatNumber($userStats['citizen']) ?></strong></div><div class="stacked-list-row"><span class="app-avatar avatar-soft"><i class="fas fa-helmet-safety"></i></span><div><strong>Workers</strong><small>Field operations</small></div><strong class="ms-auto"><?= formatNumber($userStats['worker']) ?></strong></div><div class="stacked-list-row"><span class="app-avatar avatar-soft"><i class="fas fa-user-shield"></i></span><div><strong>Administrators</strong><small>Platform owners</small></div><strong class="ms-auto"><?= formatNumber($userStats['admin']) ?></strong></div></div><div class="data-card-footer"><i class="fas fa-briefcase me-1"></i><?= $assignmentCount ?> active assignments across the platform.</div></section></div>
        </div>

        <section class="data-card"><div class="data-card-header"><div><span class="eyebrow">Governance signal</span><h2>What this data means</h2></div></div><div class="row g-3"><div class="col-12 col-md-4"><div class="insight-card"><i class="fas fa-map-location-dot"></i><strong>Density is geographic</strong><p>Use the public heatmap to spot clusters instead of counting duplicate citizen reports as separate incidents.</p></div></div><div class="col-12 col-md-4"><div class="insight-card"><i class="fas fa-robot"></i><strong>AI stays observable</strong><p>Every stored analysis contributes to the count and confidence signal shown above for later review.</p></div></div><div class="col-12 col-md-4"><div class="insight-card"><i class="fas fa-arrows-rotate"></i><strong>Status changes are audited</strong><p>Updates are written to status history so the public and staff can trace the lifecycle of an issue.</p></div></div></div></section>
        <section class="data-card mt-4"><div class="data-card-header"><div><span class="eyebrow">Priority transparency</span><h2>How the queue is scored</h2></div><a class="btn btn-sm btn-outline-primary" href="analytics.php">Open analytics</a></div><p class="small text-muted mb-0"><code>(severity x 2) + ln(report_count + 1) + ln(upvote_count + 1) + recency_decay</code>. Issues that recur within 30 days receive a 15% multiplier.</p></section>
      </div>
    </main>
  </div>

  <script>
    const adminCsrfToken = <?= json_encode(csrfToken(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    document.querySelectorAll('[data-status-save]').forEach((button) => button.addEventListener('click', async () => {
      const issueId = Number(button.dataset.statusSave);
      const select = document.querySelector(`[data-status-select="${issueId}"]`);
      button.disabled = true;
      try {
        const response = await fetch('../../api/issues/status.php', {method: 'POST', headers: {'Content-Type': 'application/json', 'X-CSRF-Token': adminCsrfToken}, body: JSON.stringify({issue_id: issueId, status: select.value})});
        const result = await response.json();
        if (!response.ok) throw new Error(result.message || 'Unable to update status.');
        const status = document.querySelector(`[data-issue-row="${issueId}"] [data-issue-status]`);
        status.textContent = select.options[select.selectedIndex].text;
        status.className = 'pill pill-' + (select.value === 'resolved' ? 'success' : (select.value === 'in_progress' ? 'warning' : (select.value === 'rejected' ? 'secondary' : 'danger')));
        button.textContent = 'Saved';
        setTimeout(() => { button.textContent = 'Save'; }, 1400);
      } catch (error) { window.alert(error.message || 'Unable to update status.'); } finally { button.disabled = false; }
    }));
  </script>
  <?php require_once __DIR__ . '/../../components/footer.php'; ?>
</body>
</html>
