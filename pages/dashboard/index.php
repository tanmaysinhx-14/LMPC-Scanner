<?php
declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';
$bootstrapData = bootstrapAccounts(['required_roles' => ['citizen', 'worker', 'admin']]);
extract($bootstrapData);

$viewer = sessionUser() ?? [];
$role = (string) ($viewer['role'] ?? 'citizen');
$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$displayStatus = static fn (?string $status): string => $status === 'pending' || $status === 'acknowledged' ? 'Open' : issueStatusLabel($status);
$dashboard = ['stats' => ['total_reports' => 0, 'resolved' => 0, 'active' => 0, 'upvotes' => 0], 'rank' => 0, 'activity' => []];
$community = ['items' => [], 'pagination' => ['total' => 0]];
$assignments = [];
$availableIssues = [];
$workRequests = [];
$assignmentStats = ['active' => 0, 'completed' => 0];
$stats = getIssueStats($db instanceof PDO ? $db : null);
$feed = ['items' => [], 'pagination' => ['total' => 0]];
$userStats = ['total' => 0, 'active' => 0, 'citizen' => 0, 'worker' => 0, 'admin' => 0];
$aiStats = ['analysed' => 0, 'average_confidence' => 0];
$assignmentCount = 0;

if ($db instanceof PDO) {
  try {
    if ($role === 'citizen') {
      $dashboard = fetchCitizenDashboardData($db, (int) $viewer['id']);
      $community = fetchIssueFeed($db, ['limit' => 4, 'sort' => 'hot', 'viewer_id' => (int) $viewer['id'], 'ward' => $viewer['ward_id'] ?? null]);
      if ($community['items'] === [] && ($viewer['ward_id'] ?? null) !== null) {
        $community = fetchIssueFeed($db, ['limit' => 4, 'sort' => 'hot', 'viewer_id' => (int) $viewer['id']]);
      }
    } elseif ($role === 'worker') {
      $stmt = $db->prepare(
        'SELECT a.id AS assignment_id, a.issue_id, a.notes, a.assigned_at, a.completed_at,
                i.title, i.description, i.category, i.severity, i.status, i.address,
                i.lat, i.lng, i.priority_score, i.created_at, i.upvote_count, i.is_recurring,
                (SELECT COUNT(*) FROM issue_reports ir WHERE ir.issue_id = i.id) AS report_count,
                (SELECT COUNT(*) FROM issue_images ii WHERE ii.issue_id = i.id) AS image_count
           FROM assignments a
           INNER JOIN issues i ON i.id = a.issue_id
          WHERE a.worker_id = ?
          ORDER BY a.completed_at IS NULL DESC, a.assigned_at DESC, a.id DESC'
      );
      $stmt->execute([(int) $viewer['id']]);
      $assignments = array_map(static function (array $row) use ($db): array {
        $row['report_count'] = (int) $row['report_count'];
        return enrichIssueWithCommunitySignals($db, $row);
      }, $stmt->fetchAll(PDO::FETCH_ASSOC));
      $assignmentStats['active'] = count(array_filter($assignments, static fn (array $item): bool => empty($item['completed_at'])));
      $assignmentStats['completed'] = count($assignments) - $assignmentStats['active'];

      $availableStmt = $db->query(
        "SELECT i.id, i.title, i.category, i.address, i.lat, i.lng, i.severity, i.upvote_count,
                i.priority_score, i.created_at, i.is_recurring, COUNT(ir.id) AS report_count
           FROM issues i
           LEFT JOIN issue_reports ir ON ir.issue_id = i.id
           LEFT JOIN assignments active_assignment
             ON active_assignment.issue_id = i.id AND active_assignment.completed_at IS NULL
          WHERE i.status IN ('pending', 'acknowledged', 'in_progress')
            AND active_assignment.id IS NULL
          GROUP BY i.id, i.title, i.category, i.address, i.lat, i.lng, i.severity,
                   i.upvote_count, i.priority_score, i.created_at, i.is_recurring
          ORDER BY i.priority_score DESC, i.created_at DESC
          LIMIT 30"
      );
      $availableIssues = array_map(static function (array $row) use ($db): array {
        $row['report_count'] = (int) $row['report_count'];
        return enrichIssueWithCommunitySignals($db, $row);
      }, $availableStmt->fetchAll(PDO::FETCH_ASSOC));
      $requestStmt = $db->prepare(
        'SELECT wr.id, wr.message, wr.status, wr.created_at, i.title
           FROM work_requests wr
           LEFT JOIN issues i ON i.id = wr.issue_id
          WHERE wr.worker_id = ?
          ORDER BY wr.created_at DESC, wr.id DESC
          LIMIT 8'
      );
      $requestStmt->execute([(int) $viewer['id']]);
      $workRequests = $requestStmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
      $feed = fetchIssueFeed($db, ['page' => 1, 'limit' => 10, 'sort' => 'hot']);
      $userRow = $db->query(
        "SELECT COUNT(*) AS total, SUM(is_active = 1) AS active,
                SUM(role = 'citizen') AS citizen, SUM(role = 'worker') AS worker,
                SUM(role = 'admin') AS admin
           FROM users"
      )->fetch(PDO::FETCH_ASSOC) ?: [];
      foreach ($userStats as $key => $value) $userStats[$key] = (int) ($userRow[$key] ?? 0);
      $aiStats = $db->query('SELECT COUNT(*) AS analysed, COALESCE(AVG(confidence), 0) AS average_confidence FROM issue_ai_analyses')->fetch(PDO::FETCH_ASSOC) ?: $aiStats;
      $assignmentCount = (int) $db->query('SELECT COUNT(*) FROM assignments WHERE completed_at IS NULL')->fetchColumn();
    }
  } catch (Throwable $exception) {
    error_log('Unified dashboard data failed: ' . $exception->getMessage());
  }
}

$statusOptions = ['pending', 'acknowledged', 'in_progress', 'resolved', 'rejected'];
$workerStatusOptions = ['in_progress', 'resolved'];
$displayName = (string) ($viewer['name'] ?? ucfirst($role));
$nameParts = preg_split('/\s+/', trim($displayName)) ?: [];
$initials = strtoupper(substr((string) ($nameParts[0] ?? 'C'), 0, 1));
if (count($nameParts) > 1) $initials .= strtoupper(substr((string) $nameParts[count($nameParts) - 1], 0, 1));
$pageTitle = $role === 'admin' ? 'Admin dashboard' : ($role === 'worker' ? 'Worker dashboard' : 'Citizen dashboard');
?>
<?php require_once CIVICCONNECT_ROOT . '/components/header.php'; ?>
<style>
  .unified-dashboard { min-height: 100vh; }
  .dashboard-hero { padding: clamp(1.35rem, 2.8vw, 2.35rem); border-radius: 1.2rem; color: #fff; background: linear-gradient(120deg, #273379, #4255c7 58%, #0f9f8f); box-shadow: 0 18px 36px rgba(39, 51, 121, .2); }
  .dashboard-hero .hero-copy { max-width: 46rem; }
  .dashboard-card, .data-card { border: 1px solid var(--cc-line); border-radius: var(--cc-radius); background: #fff; box-shadow: var(--cc-shadow); }
  .dashboard-card:hover, .data-card:hover { box-shadow: var(--cc-shadow-hover); }
  .dashboard-card-header { display: flex; align-items: center; justify-content: space-between; gap: 1rem; padding: 1.05rem 1.25rem; border-bottom: 1px solid var(--cc-line); }
  .dashboard-card-header h2 { margin: .2rem 0 0; color: var(--cc-ink); font-size: 1.05rem; font-weight: 800; }
  .dashboard-card-body { padding: 1.15rem 1.25rem; }
  .dashboard-list-row { display: flex; align-items: center; gap: .85rem; padding: .85rem 0; border-bottom: 1px solid var(--cc-line); }
  .dashboard-list-row:last-child { border-bottom: 0; }
  .dashboard-list-row strong { display: block; color: var(--cc-ink); font-size: .86rem; }
  .dashboard-list-row small { display: block; margin-top: .18rem; color: var(--cc-muted); font-size: .7rem; }
  .dashboard-list-row .row-actions { margin-left: auto; display: flex; flex-wrap: wrap; justify-content: flex-end; gap: .35rem; }
  .dashboard-priority { display: grid; width: 3rem; height: 3rem; flex: 0 0 3rem; place-items: center; border-radius: .9rem; color: #fff; background: var(--cc-primary); font-size: .95rem; font-weight: 850; }
  .dashboard-priority small { color: rgba(255,255,255,.72); font-size: .52rem; font-weight: 700; }
  .dashboard-empty { padding: 2rem .5rem; color: var(--cc-muted); text-align: center; }
  .dashboard-empty i { display: block; margin-bottom: .6rem; font-size: 1.5rem; opacity: .7; }
  .dashboard-signal { color: #087568; font-size: .7rem; font-weight: 750; }
  .dashboard-verified { color: #166534; background: #ecfdf3; border: 1px solid #bbf7d0; }
  @media (max-width: 720px) { .dashboard-list-row { align-items: flex-start; flex-wrap: wrap; } .dashboard-list-row .row-actions { width: 100%; margin-left: 3.85rem; justify-content: flex-start; } }
</style>
<body class="app-body">
  <div class="app-shell unified-dashboard">
    <aside id="dashboardSidebar" class="app-sidebar" data-app-sidebar aria-label="<?= $e(ucfirst($role)) ?> navigation">
      <div class="app-sidebar-brand"><span class="app-brand-mark"><i class="fas fa-city"></i></span><span>CivicConnect<small><?= $e(ucfirst($role)) ?> workspace</small></span><button type="button" class="app-sidebar-close" data-sidebar-close aria-label="Close navigation"><i class="fas fa-xmark"></i></button></div>
      <div class="app-sidebar-label">Workspace</div>
      <nav class="nav flex-column gap-1">
        <a class="nav-link active" href="<?= $e(civicRoute('dashboard')) ?>"><i class="fas fa-th-large fa-fw"></i><span>Dashboard</span></a>
        <?php if ($role === 'citizen'): ?>
          <a class="nav-link" href="<?= $e(civicRoute('report')) ?>"><i class="fas fa-plus-circle fa-fw"></i><span>Report issue</span></a>
        <?php elseif ($role === 'worker'): ?>
          <a class="nav-link" href="<?= $e(civicRoute('assignments')) ?>"><i class="fas fa-clipboard-list fa-fw"></i><span>My assignments</span><span class="nav-count"><?= (int) $assignmentStats['active'] ?></span></a>
        <?php else: ?>
          <a class="nav-link" href="<?= $e(civicRoute('work_management')) ?>"><i class="fas fa-briefcase fa-fw"></i><span>Work management</span><span class="nav-count"><?= $assignmentCount ?></span></a>
          <a class="nav-link" href="<?= $e(civicRoute('analytics')) ?>"><i class="fas fa-chart-line fa-fw"></i><span>Analytics</span></a>
        <?php endif; ?>
        <a class="nav-link" href="<?= $e(civicRoute('feed')) ?>"><i class="fas fa-layer-group fa-fw"></i><span>Community feed</span></a>
        <a class="nav-link" href="<?= $e(civicRoute('pulse')) ?>"><i class="fas fa-map-location-dot fa-fw"></i><span>City pulse</span></a>
        <a class="nav-link" href="<?= $e(civicRoute('profile')) ?>"><i class="fas fa-user fa-fw"></i><span>Profile</span></a>
      </nav>
      <div class="app-sidebar-spacer"></div>
      <div class="app-sidebar-user"><span class="app-avatar"><?= $e($initials) ?></span><div><strong><?= $e($displayName) ?></strong><small><?= $e($viewer['city'] ?? ucfirst($role)) ?></small></div></div>
      <a class="nav-link text-danger mt-2" href="<?= $e(civicRoute('logout')) ?>"><i class="fas fa-arrow-right-from-bracket fa-fw"></i><span>Sign out</span></a>
    </aside>
    <div class="app-sidebar-backdrop" data-sidebar-backdrop="dashboardSidebar"></div>

    <main class="app-content">
      <header class="app-topbar"><div class="d-flex align-items-center gap-3"><button class="app-menu-toggle" data-sidebar-toggle="dashboardSidebar" aria-controls="dashboardSidebar" aria-expanded="true" aria-label="Toggle navigation"><i class="fas fa-bars"></i></button><div><div class="eyebrow"><?= $e(ucfirst($role)) ?> workspace</div><h1><?= $e($pageTitle) ?></h1></div></div><div class="d-flex gap-2"><a class="btn btn-sm btn-outline-secondary" href="<?= $e(civicRoute('feed')) ?>"><i class="fas fa-eye me-1"></i>Public view</a><?php if ($role === 'citizen'): ?><a class="btn btn-sm btn-primary" href="<?= $e(civicRoute('report')) ?>"><i class="fas fa-plus me-1"></i>Report</a><?php endif; ?></div></header>

      <div class="container-fluid px-3 px-lg-4 py-4">
        <?php if ($role === 'citizen'): ?>
          <section class="dashboard-hero mb-4"><div class="hero-copy"><span class="hero-kicker"><i class="fas fa-hand-holding-heart me-1"></i>Welcome back, <?= $e($displayName) ?></span><h2 class="mt-2 mb-2">Your reports are helping shape a better city.</h2><p class="mb-0 text-white-50">Follow report progress, see nearby evidence, and confirm when a resolution is genuinely complete.</p></div><a href="<?= $e(civicRoute('report')) ?>" class="btn btn-light text-primary"><i class="fas fa-plus me-2"></i>Report an issue</a></section>
          <section class="row g-3 mb-4" aria-label="Your report statistics">
            <?php foreach ([['Reports submitted', $dashboard['stats']['total_reports'], 'fa-file-alt', 'primary'], ['Resolved issues', $dashboard['stats']['resolved'], 'fa-check-circle', 'success'], ['Active issues', $dashboard['stats']['active'], 'fa-clock', 'warning'], ['Community upvotes', $dashboard['stats']['upvotes'], 'fa-thumbs-up', 'info']] as $card): ?><div class="col-12 col-sm-6 col-xl-3"><div class="metric-card h-100"><div class="card-body d-flex flex-row justify-content-between align-items-center"><div><div class="text-muted small fw-semibold mb-2"><?= $e($card[0]) ?></div><div class="metric-number mb-0"><?= formatNumber((int) $card[1]) ?></div></div><span class="metric-icon text-<?= $e($card[3]) ?>"><i class="fas <?= $e($card[2]) ?>"></i></span></div></div></div><?php endforeach; ?>
          </section>
          <div class="row g-4">
            <section class="col-12 col-xl-7"><div class="dashboard-card h-100"><div class="dashboard-card-header"><div><span class="eyebrow">My activity</span><h2>Track your reports</h2></div><span class="count-badge">Rank #<?= (int) $dashboard['rank'] ?></span></div><div class="dashboard-card-body">
              <?php if ($dashboard['activity'] === []): ?><div class="dashboard-empty"><i class="fas fa-file-circle-plus"></i>No reports have been submitted from this account yet.</div><?php else: ?><?php foreach ($dashboard['activity'] as $activity): ?><article class="dashboard-list-row"><span class="dashboard-priority"><?= number_format((float) ($activity['priority_score'] ?? 0), 0) ?></span><div class="flex-grow-1 min-w-0"><strong><?= $e($activity['title'] ?: issueCategoryLabel($activity['category'])) ?></strong><small><i class="fas fa-location-dot me-1"></i><?= $e($activity['address'] ?: 'Location recorded') ?> · <?= $e($displayStatus($activity['status'])) ?></small><?php if (!empty($activity['assigned_worker_name'])): ?><small class="text-success"><i class="fas fa-helmet-safety me-1"></i>Assigned to <?= $e($activity['assigned_worker_name']) ?><?= empty($activity['assignment_completed_at']) ? '' : ' · Completed' ?></small><?php endif; ?><small class="dashboard-signal"><i class="fas fa-chart-simple me-1"></i><?= (int) ($activity['nearby_similar_reports'] ?? 0) ?> similar nearby · <?= (int) ($activity['city_similar_reports'] ?? 0) ?> citywide</small><?php if ($activity['status'] === 'resolved' && !empty($activity['assignment_completed_at']) && empty($activity['citizen_verified_at'])): ?><div class="alert dashboard-verified py-2 px-3 small mt-2 mb-0 d-flex flex-wrap align-items-center gap-2"><span><i class="fas fa-circle-check me-1"></i>Was the fix completed correctly?</span><label class="btn btn-sm btn-outline-secondary mb-0"><i class="fas fa-camera me-1"></i>After-photo<input type="file" accept="image/jpeg,image/png,image/webp" class="d-none" data-after-image="<?= (int) $activity['id'] ?>"></label><button type="button" class="btn btn-sm btn-success" data-verify-resolution="<?= (int) $activity['id'] ?>">Verify</button><button type="button" class="btn btn-sm btn-outline-danger" data-reopen-resolution="<?= (int) $activity['id'] ?>">Reopen</button></div><?php elseif (!empty($activity['citizen_verified_at'])): ?><small class="text-success"><i class="fas fa-shield-heart me-1"></i>Resolution verified by you</small><?php endif; ?></div><div class="row-actions"><a class="btn btn-sm btn-outline-secondary" href="<?= $e(civicRoute('feed', ['issue' => (int) $activity['id']])) ?>">Details</a></div></article><?php endforeach; ?><?php endif; ?>
            </div></div></section>
            <aside class="col-12 col-xl-5"><div class="dashboard-card h-100"><div class="dashboard-card-header"><div><span class="eyebrow">Community signal</span><h2>What residents see</h2></div><a href="<?= $e(civicRoute('feed')) ?>" class="small text-decoration-none">See all</a></div><div class="dashboard-card-body"><?php if ($community['items'] === []): ?><div class="dashboard-empty">No public issues have been submitted yet.</div><?php else: ?><?php foreach ($community['items'] as $issue): ?><a href="<?= $e(civicRoute('feed', ['issue' => (int) $issue['id']])) ?>" class="dashboard-list-row text-decoration-none"><span class="dashboard-priority"><i class="fas fa-location-dot"></i></span><span class="min-w-0 flex-grow-1"><strong><?= $e($issue['title'] ?: issueCategoryLabel($issue['category'])) ?></strong><small><?= $e($issue['address'] ?: 'Location recorded') ?> · <?= $e($displayStatus($issue['status'])) ?></small><small class="dashboard-signal"><?= (int) ($issue['nearby_similar_reports'] ?? 0) ?> nearby · <?= (int) ($issue['city_similar_reports'] ?? 0) ?> citywide</small></span></a><?php endforeach; ?><?php endif; ?></div></div></aside>
          </div>
        <?php elseif ($role === 'worker'): ?>
          <section class="dashboard-hero mb-4"><div class="hero-copy"><span class="hero-kicker"><i class="fas fa-helmet-safety me-1"></i>Field operations</span><h2 class="mt-2 mb-2">Make the next fix count.</h2><p class="mb-0 text-white-50">Update only issues assigned to your account. Every change is recorded for citizens and administrators.</p></div></section>
          <section class="row g-3 mb-4"><div class="col-6 col-md-3"><div class="metric-card metric-primary"><div class="metric-icon"><i class="fas fa-list-check"></i></div><div><span class="metric-label">Active</span><strong><?= $assignmentStats['active'] ?></strong><small>Need action</small></div></div></div><div class="col-6 col-md-3"><div class="metric-card metric-success"><div class="metric-icon"><i class="fas fa-check"></i></div><div><span class="metric-label">Completed</span><strong><?= $assignmentStats['completed'] ?></strong><small>Closed assignments</small></div></div></div></section>
          <section class="dashboard-card mb-4"><div class="dashboard-card-header"><div><span class="eyebrow">Need a new task?</span><h2>Request work from an administrator</h2></div><span class="count-badge"><?= count($availableIssues) ?> available</span></div><div class="dashboard-card-body"><?php if ($availableIssues === []): ?><p class="small text-muted mb-0"><i class="fas fa-circle-info me-1"></i>No unassigned issues are available right now.</p><?php else: ?><form id="workRequestForm" class="row g-3"><div class="col-12 col-lg-6"><label class="form-label small fw-semibold" for="requestIssue">Issue to request</label><select class="form-select" id="requestIssue" required><option value="">Choose an unassigned issue</option><?php foreach ($availableIssues as $issue): ?><option value="<?= (int) $issue['id'] ?>"><?= $e($issue['title'] ?: issueCategoryLabel($issue['category'])) ?> · <?= (int) $issue['report_count'] ?> reports · <?= $e($issue['address'] ?: 'Location recorded') ?></option><?php endforeach; ?></select></div><div class="col-12 col-lg-4"><label class="form-label small fw-semibold" for="requestMessage">Message</label><input class="form-control" id="requestMessage" maxlength="1000" placeholder="I am available to take this task." required></div><div class="col-12 col-lg-2 d-flex align-items-end"><button class="btn btn-primary w-100" type="submit">Request</button></div></form><?php endif; ?><div class="d-flex flex-wrap gap-2 mt-3"><?php foreach ($workRequests as $request): ?><span class="badge rounded-pill text-bg-<?= $request['status'] === 'approved' ? 'success' : ($request['status'] === 'declined' ? 'secondary' : 'warning') ?>"><?= $e(ucfirst($request['status'])) ?> · <?= $e($request['title'] ?: 'Work request') ?></span><?php endforeach; ?></div></div></section>
          <section id="assignments" class="dashboard-card"><div class="dashboard-card-header"><div><span class="eyebrow">My queue</span><h2>Issues assigned to me</h2></div><span class="count-badge"><?= count($assignments) ?> total</span></div><div class="dashboard-card-body"><?php if ($assignments === []): ?><div class="dashboard-empty"><i class="fas fa-clipboard-check"></i>No assignments yet.</div><?php else: ?><?php foreach ($assignments as $assignment): ?><article class="dashboard-list-row"><span class="dashboard-priority"><?= number_format((float) $assignment['priority_score'], 0) ?></span><div class="flex-grow-1 min-w-0"><strong><?= $e($assignment['title'] ?: issueCategoryLabel($assignment['category'])) ?></strong><small><?= $e($assignment['address'] ?: 'Location recorded') ?> · <?= $e($displayStatus($assignment['status'])) ?></small><small><?= (int) $assignment['report_count'] ?> reports · <?= (int) $assignment['image_count'] ?> photos<?= $assignment['notes'] ? ' · ' . $e($assignment['notes']) : '' ?></small></div><div class="row-actions"><?php if (!$assignment['completed_at']): ?><select class="form-select form-select-sm" data-status-select="<?= (int) $assignment['issue_id'] ?>" aria-label="Change assignment status"><?php foreach ($workerStatusOptions as $option): ?><option value="<?= $e($option) ?>" <?= $assignment['status'] === $option ? 'selected' : '' ?>><?= $e(issueStatusLabel($option)) ?></option><?php endforeach; ?></select><button class="btn btn-sm btn-primary" type="button" data-status-save="<?= (int) $assignment['issue_id'] ?>">Save</button><?php else: ?><span class="small text-success"><i class="fas fa-circle-check me-1"></i>Completed</span><?php endif; ?><a class="btn btn-sm btn-link" href="<?= $e(civicRoute('feed', ['issue' => (int) $assignment['issue_id']])) ?>">Details</a></div></article><?php endforeach; ?><?php endif; ?></div></section>
        <?php else: ?>
          <section class="dashboard-hero mb-4"><div class="hero-copy"><span class="hero-kicker"><i class="fas fa-lock me-1"></i>Administrator access</span><h2 class="mt-2 mb-2">One view of the whole city.</h2><p class="mb-0 text-white-50">Monitor participation, issue resolution, AI processing, and work waiting for action.</p></div><a class="btn btn-light text-primary" href="<?= $e(civicRoute('work_management')) ?>"><i class="fas fa-briefcase me-1"></i>Manage work</a></section>
          <section class="row g-3 mb-4"><div class="col-6 col-xl-3"><div class="metric-card metric-primary"><div class="metric-icon"><i class="fas fa-users"></i></div><div><span class="metric-label">Total users</span><strong><?= formatNumber($userStats['total']) ?></strong><small><?= formatNumber($userStats['active']) ?> active accounts</small></div></div></div><div class="col-6 col-xl-3"><div class="metric-card metric-danger"><div class="metric-icon"><i class="fas fa-road"></i></div><div><span class="metric-label">Tracked issues</span><strong><?= formatNumber((int) ($stats['total'] ?? 0)) ?></strong><small><?= formatNumber((int) ($stats['open'] ?? 0)) ?> currently open</small></div></div></div><div class="col-6 col-xl-3"><div class="metric-card metric-success"><div class="metric-icon"><i class="fas fa-chart-line"></i></div><div><span class="metric-label">Resolution rate</span><strong><?= (int) ($stats['total'] ?? 0) > 0 ? number_format(((int) $stats['resolved'] / (int) $stats['total']) * 100, 0) : 0 ?>%</strong><small><?= formatNumber((int) ($stats['resolved'] ?? 0)) ?> resolved</small></div></div></div><div class="col-6 col-xl-3"><div class="metric-card metric-warning"><div class="metric-icon"><i class="fas fa-robot"></i></div><div><span class="metric-label">AI analyses</span><strong><?= formatNumber((int) ($aiStats['analysed'] ?? 0)) ?></strong><small><?= number_format((float) ($aiStats['average_confidence'] ?? 0) * 100, 0) ?>% average confidence</small></div></div></div></section>
          <div class="row g-4"><section class="col-12 col-xl-8"><div class="dashboard-card h-100"><div class="dashboard-card-header"><div><span class="eyebrow">Cross-platform activity</span><h2>Highest priority issues</h2></div><span class="count-badge"><?= (int) ($feed['pagination']['total'] ?? 0) ?> total</span></div><div class="dashboard-card-body"><?php if ($feed['items'] === []): ?><div class="dashboard-empty"><i class="fas fa-inbox"></i>No issues have been reported.</div><?php else: ?><?php foreach ($feed['items'] as $issue): ?><article class="dashboard-list-row"><span class="dashboard-priority"><?= number_format((float) $issue['priority_score'], 0) ?><small>priority</small></span><div class="flex-grow-1 min-w-0"><strong><?= $e($issue['title'] ?: issueCategoryLabel($issue['category'])) ?></strong><small><?= $e($issue['address'] ?: 'Location recorded') ?> · <?= $e($displayStatus($issue['status'])) ?></small><small><?= (int) $issue['report_count'] ?> reports · <?= (int) $issue['image_count'] ?> photos · <?= (int) $issue['upvote_count'] ?> upvotes</small></div><div class="row-actions"><select class="form-select form-select-sm" data-admin-status-select="<?= (int) $issue['id'] ?>" aria-label="Change issue status"><?php foreach ($statusOptions as $option): ?><option value="<?= $e($option) ?>" <?= $issue['status'] === $option ? 'selected' : '' ?>><?= $e(issueStatusLabel($option)) ?></option><?php endforeach; ?></select><button class="btn btn-sm btn-primary" type="button" data-admin-status-save="<?= (int) $issue['id'] ?>">Save</button><a class="btn btn-sm btn-link" href="<?= $e(civicRoute('feed', ['issue' => (int) $issue['id']])) ?>">Details</a></div></article><?php endforeach; ?><?php endif; ?></div></div></section><aside class="col-12 col-xl-4"><div class="dashboard-card h-100"><div class="dashboard-card-header"><div><span class="eyebrow">People</span><h2>Account distribution</h2></div><i class="fas fa-users text-primary"></i></div><div class="dashboard-card-body"><?php foreach ([['Citizens', 'People reporting issues', $userStats['citizen'], 'fa-user'], ['Workers', 'Field operations', $userStats['worker'], 'fa-helmet-safety'], ['Administrators', 'Platform owners', $userStats['admin'], 'fa-user-shield']] as $person): ?><div class="dashboard-list-row"><span class="app-avatar avatar-soft"><i class="fas <?= $e($person[3]) ?>"></i></span><span><strong><?= $e($person[0]) ?></strong><small><?= $e($person[1]) ?></small></span><strong class="ms-auto"><?= formatNumber((int) $person[2]) ?></strong></div><?php endforeach; ?></div><div class="data-card-footer"><i class="fas fa-briefcase me-1"></i><?= $assignmentCount ?> active assignments.</div></div></aside></div>
          <section class="dashboard-card mt-4"><div class="dashboard-card-header"><div><span class="eyebrow">Priority transparency</span><h2>How the queue is scored</h2></div><a class="btn btn-sm btn-outline-primary" href="<?= $e(civicRoute('analytics')) ?>">Open analytics</a></div><div class="dashboard-card-body"><p class="small text-muted mb-0"><code>severity + same-location evidence + nearby category evidence + civic signal + upvotes + recency</code>. Same-location reports receive the strongest boost; different issues nearby receive a smaller boost. Scores are capped at 100.</p></div></section>
        <?php endif; ?>
      </div>
    </main>
  </div>
  <script>
    const dashboardCsrf = <?= json_encode(csrfToken(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const dashboardApi = <?= json_encode(civicUrl('api/'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    async function postDashboard(path, payload, formData = null) {
      const options = { method: 'POST', headers: { 'X-CSRF-Token': dashboardCsrf } };
      if (formData) options.body = formData;
      else { options.headers['Content-Type'] = 'application/json'; options.body = JSON.stringify(payload); }
      const response = await fetch(dashboardApi + path, options);
      const result = await response.json().catch(() => ({}));
      if (!response.ok) throw new Error(result.message || 'The update could not be saved.');
      return result;
    }
    document.querySelectorAll('[data-status-save]').forEach((button) => button.addEventListener('click', async () => {
      const issueId = Number(button.dataset.statusSave);
      const select = document.querySelector(`[data-status-select="${issueId}"]`);
      button.disabled = true;
      try { await postDashboard('issues/status.php', {issue_id: issueId, status: select.value}); button.textContent = 'Saved'; setTimeout(() => { button.textContent = 'Save'; }, 1400); }
      catch (error) { window.alert(error.message || 'Unable to update assignment.'); }
      finally { button.disabled = false; }
    }));
    document.querySelectorAll('[data-admin-status-save]').forEach((button) => button.addEventListener('click', async () => {
      const issueId = Number(button.dataset.adminStatusSave);
      const select = document.querySelector(`[data-admin-status-select="${issueId}"]`);
      button.disabled = true;
      try { await postDashboard('issues/status.php', {issue_id: issueId, status: select.value}); button.textContent = 'Saved'; setTimeout(() => { button.textContent = 'Save'; }, 1400); }
      catch (error) { window.alert(error.message || 'Unable to update issue status.'); }
      finally { button.disabled = false; }
    }));
    document.getElementById('workRequestForm')?.addEventListener('submit', async (event) => {
      event.preventDefault();
      const button = event.currentTarget.querySelector('button[type="submit"]');
      const issueId = Number(document.getElementById('requestIssue')?.value || 0);
      const message = document.getElementById('requestMessage')?.value.trim() || '';
      if (!issueId || !message) return;
      button.disabled = true;
      try { await postDashboard('work/request.php', {issue_id: issueId, message}); window.location.reload(); }
      catch (error) { window.alert(error.message || 'Unable to request work.'); button.disabled = false; }
    });
    async function updateResolution(issueId, action, afterImage = null) {
      const formData = new FormData();
      formData.append('csrf_token', dashboardCsrf); formData.append('issue_id', String(issueId)); formData.append('action', action);
      if (afterImage) formData.append('after_image', afterImage, afterImage.name);
      await postDashboard('issues/verify-resolution.php', {}, formData); window.location.reload();
    }
    document.querySelectorAll('[data-verify-resolution]').forEach((button) => button.addEventListener('click', async () => { button.disabled = true; const input = document.querySelector(`[data-after-image="${button.dataset.verifyResolution}"]`); try { await updateResolution(Number(button.dataset.verifyResolution), 'verify', input?.files?.[0] || null); } catch (error) { window.alert(error.message || 'Unable to verify resolution.'); button.disabled = false; } }));
    document.querySelectorAll('[data-reopen-resolution]').forEach((button) => button.addEventListener('click', async () => { const reason = window.prompt('What still needs to be fixed?'); if (!reason?.trim()) return; button.disabled = true; try { const formData = new FormData(); formData.append('csrf_token', dashboardCsrf); formData.append('issue_id', button.dataset.reopenResolution); formData.append('action', 'reopen'); formData.append('reason', reason.trim()); await postDashboard('issues/verify-resolution.php', {}, formData); window.location.reload(); } catch (error) { window.alert(error.message || 'Unable to reopen resolution.'); button.disabled = false; } }));
  </script>
  <?php require_once CIVICCONNECT_ROOT . '/components/footer.php'; ?>
</body>
</html>
