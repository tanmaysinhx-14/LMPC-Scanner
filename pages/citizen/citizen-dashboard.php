<?php
declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';

$bootstrapData = bootstrapAccounts(options: ['required_roles' => ['citizen']]);
extract($bootstrapData);

$user = sessionUser() ?? [];
$dashboard = [
  'stats' => ['total_reports' => 0, 'resolved' => 0, 'active' => 0, 'upvotes' => 0],
  'rank' => 0,
  'activity' => [],
];
$community = ['items' => [], 'pagination' => ['total' => 0]];

if ($db instanceof PDO) {
  try {
    $dashboard = fetchCitizenDashboardData($db, (int) $user['id']);
    $community = fetchIssueFeed($db, [
      'limit' => 4,
      'sort' => 'hot',
      'viewer_id' => (int) $user['id'],
      'ward' => $user['ward_id'] ?? null,
    ]);

    // If the account has no ward yet, show city-wide community posts rather
    // than leaving the dashboard empty.
    if ($community['items'] === [] && $user['ward_id'] !== null) {
      $community = fetchIssueFeed($db, [
        'limit' => 4,
        'sort' => 'hot',
        'viewer_id' => (int) $user['id'],
      ]);
    }
  } catch (Throwable $exception) {
    error_log('Citizen dashboard query failed: ' . $exception->getMessage());
  }
}

$stats = $dashboard['stats'];
$displayName = (string) ($user['name'] ?? 'Citizen');
$nameParts = preg_split('/\s+/', trim($displayName)) ?: [];
$initials = strtoupper(substr((string) ($nameParts[0] ?? 'C'), 0, 1));
if (count($nameParts) > 1) {
  $initials .= strtoupper(substr((string) $nameParts[count($nameParts) - 1], 0, 1));
}
$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');

require_once __DIR__ . '/../../components/header.php';
?>

<style>
  body { background: #f5f7fb; }
  .citizen-shell { min-height: 100vh; }
  .citizen-sidebar { width: 260px; transition: margin-left .25s ease; }
  .citizen-sidebar.collapsed { margin-left: -260px; }
  .stat-card, .dashboard-card, .activity-card, .issue-card { border: 0; box-shadow: 0 .35rem 1.2rem rgba(15, 23, 42, .07); }
  .stat-icon { width: 42px; height: 42px; }
  .issue-card { transition: transform .2s ease, box-shadow .2s ease; }
  .issue-card:hover { transform: translateY(-2px); box-shadow: 0 .7rem 1.5rem rgba(15, 23, 42, .12); }
  .status-badge { white-space: nowrap; }
  .activity-line:last-child .activity-rail { border-color: transparent !important; }
  @media (max-width: 767.98px) {
    .citizen-sidebar { position: fixed; inset: 0 auto 0 0; z-index: 1040; }
    .citizen-sidebar.collapsed { margin-left: -260px; }
  }
</style>

<body class="citizen-shell">
  <div class="d-flex min-vh-100">
    <aside id="citizenSidebar" class="citizen-sidebar d-flex flex-column flex-shrink-0 p-3 bg-white border-end">
      <a href="citizen-dashboard.php" class="d-flex align-items-center mb-4 link-dark text-decoration-none gap-2">
        <i class="fas fa-city fs-4 text-primary"></i>
        <span class="fs-4 fw-bold">CivicConnect</span>
      </a>
      <div class="small text-uppercase text-muted fw-semibold mb-2">Citizen space</div>
      <ul class="nav nav-pills flex-column gap-1 mb-auto">
        <li class="nav-item"><a href="citizen-dashboard.php" class="nav-link active"><i class="fas fa-th-large fa-fw me-2"></i>Dashboard</a></li>
        <li class="nav-item"><a href="../report/" class="nav-link link-dark"><i class="fas fa-plus-circle fa-fw me-2"></i>Report issue</a></li>
        <li class="nav-item"><a href="public-feed.php" class="nav-link link-dark"><i class="fas fa-globe fa-fw me-2"></i>Public feed</a></li>
      </ul>
      <hr>
      <div class="small text-muted mb-2 text-truncate" title="<?= $e($user['email'] ?? '') ?>"><?= $e($user['email'] ?? '') ?></div>
      <a href="../logout/" class="nav-link text-danger px-0"><i class="fas fa-sign-out-alt fa-fw me-2"></i>Logout</a>
    </aside>

    <main class="flex-grow-1 overflow-auto">
      <nav class="navbar bg-white border-bottom sticky-top px-3 py-2">
        <div class="d-flex align-items-center gap-3">
          <button class="btn btn-light" type="button" aria-label="Toggle navigation" onclick="document.getElementById('citizenSidebar').classList.toggle('collapsed')"><i class="fas fa-bars"></i></button>
          <div>
            <div class="fw-bold">Citizen dashboard</div>
            <div class="small text-muted">Track the issues you helped bring to the city</div>
          </div>
        </div>
        <div class="dropdown">
          <a href="#" class="d-flex align-items-center gap-2 link-dark text-decoration-none dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
            <span class="bg-primary text-white d-flex align-items-center justify-content-center rounded-circle fw-bold" style="width:38px;height:38px;"><?= $e($initials) ?></span>
            <span class="d-none d-sm-inline"><?= $e($displayName) ?></span>
          </a>
          <ul class="dropdown-menu dropdown-menu-end shadow-sm">
            <li><span class="dropdown-item-text small text-muted"><?= $e($user['city'] ?? 'City resident') ?></span></li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item text-danger" href="../logout/"><i class="fas fa-sign-out-alt me-2"></i>Logout</a></li>
          </ul>
        </div>
      </nav>

      <div class="container-fluid p-3 p-lg-4">
        <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">
          <div>
            <p class="text-primary fw-semibold mb-1">Welcome back, <?= $e($displayName) ?></p>
            <h1 class="h3 fw-bold mb-1">Your civic impact</h1>
            <p class="text-muted mb-0">Every report helps authorities see what needs attention.</p>
          </div>
          <a href="../report/" class="btn btn-primary"><i class="fas fa-plus me-2"></i>Report an issue</a>
        </div>

        <?php if (!($db instanceof PDO)): ?>
          <div class="alert alert-warning">The database is unavailable. Your dashboard will refresh automatically when it is back online.</div>
        <?php endif; ?>

        <section class="row g-3 mb-4" aria-label="Your report statistics">
          <?php
          $statCards = [
            ['label' => 'Reports submitted', 'value' => $stats['total_reports'], 'icon' => 'fa-file-alt', 'class' => 'primary'],
            ['label' => 'Resolved issues', 'value' => $stats['resolved'], 'icon' => 'fa-check-circle', 'class' => 'success'],
            ['label' => 'Active issues', 'value' => $stats['active'], 'icon' => 'fa-clock', 'class' => 'warning'],
            ['label' => 'Community upvotes', 'value' => $stats['upvotes'], 'icon' => 'fa-thumbs-up', 'class' => 'info'],
          ];
          foreach ($statCards as $card):
          ?>
            <div class="col-12 col-sm-6 col-xl-3">
              <div class="card stat-card h-100">
                <div class="card-body d-flex justify-content-between align-items-center">
                  <div><div class="text-muted small fw-semibold mb-2"><?= $e($card['label']) ?></div><div class="h3 mb-0 fw-bold"><?= formatNumber((int) $card['value']) ?></div></div>
                  <span class="stat-icon rounded-3 d-inline-flex align-items-center justify-content-center bg-<?= $e($card['class']) ?>-subtle text-<?= $e($card['class']) ?>"><i class="fas <?= $e($card['icon']) ?>"></i></span>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        </section>

        <div class="row g-4">
          <section class="col-12 col-xl-7">
            <div class="card activity-card h-100">
              <div class="card-header bg-white border-0 pt-4 px-4 d-flex justify-content-between align-items-center">
                <div><h2 class="h5 fw-bold mb-1">Your recent reports</h2><p class="small text-muted mb-0">Grouped posts include every nearby citizen report.</p></div>
                <a href="public-feed.php" class="btn btn-sm btn-outline-primary">View feed</a>
              </div>
              <div class="card-body px-4">
                <?php if ($dashboard['activity'] === []): ?>
                  <div class="text-center py-5"><i class="fas fa-clipboard-list fa-2x text-muted mb-3"></i><p class="text-muted mb-3">You have not submitted a report yet.</p><a href="../report/" class="btn btn-primary btn-sm">Submit your first report</a></div>
                <?php else: ?>
                  <?php foreach ($dashboard['activity'] as $activity): ?>
                    <?php $statusClass = issueStatusClass($activity['status']); ?>
                    <article class="activity-line d-flex gap-3">
                      <div class="activity-rail border-start border-2 border-primary-subtle position-relative ms-2"><span class="position-absolute top-0 start-0 translate-middle p-2 bg-primary border border-white border-2 rounded-circle"></span></div>
                      <div class="pb-4 flex-grow-1">
                        <div class="d-flex flex-wrap justify-content-between gap-2 mb-1"><span class="small text-muted"><?= $e(date('M d, Y H:i', strtotime((string) ($activity['last_reported_at'] ?? $activity['created_at'])))) ?></span><span class="badge text-bg-<?= $e($statusClass) ?> status-badge"><?= $e(issueStatusLabel($activity['status'])) ?></span></div>
                        <a href="public-feed.php?issue=<?= (int) $activity['id'] ?>" class="text-decoration-none text-dark"><h3 class="h6 fw-bold mb-1"><?= $e($activity['title'] ?: issueCategoryLabel($activity['category'])) ?></h3></a>
                        <div class="small text-muted d-flex flex-wrap gap-3"><span><i class="fas fa-tag me-1"></i><?= $e(issueCategoryLabel($activity['category'])) ?></span><span><i class="fas fa-images me-1"></i><?= (int) $activity['image_count'] ?> photos</span><span><i class="fas fa-users me-1"></i><?= (int) $activity['citizen_report_count'] ?> reports</span><span><i class="fas fa-thumbs-up me-1"></i><?= formatNumber((int) $activity['upvote_count']) ?></span></div>
                      </div>
                    </article>
                  <?php endforeach; ?>
                <?php endif; ?>
              </div>
            </div>
          </section>

          <aside class="col-12 col-xl-5">
            <div class="card dashboard-card mb-4">
              <div class="card-body p-4 d-flex align-items-center gap-3">
                <span class="rounded-circle bg-warning-subtle text-warning d-inline-flex align-items-center justify-content-center" style="width:58px;height:58px"><i class="fas fa-trophy fa-lg"></i></span>
                <div><div class="small text-muted">Contributor rank</div><div class="h3 fw-bold mb-1">#<?= (int) $dashboard['rank'] ?></div><div class="small text-success">Keep helping your neighbourhood</div></div>
              </div>
            </div>
            <div class="card dashboard-card">
              <div class="card-header bg-white border-0 pt-4 px-4 d-flex justify-content-between align-items-center"><h2 class="h5 fw-bold mb-0">Community issues</h2><a href="public-feed.php" class="small text-decoration-none">See all</a></div>
              <div class="card-body px-4 pb-4">
                <?php if ($community['items'] === []): ?>
                  <p class="text-muted small mb-0">No public issues have been submitted yet.</p>
                <?php else: ?>
                  <?php foreach ($community['items'] as $issue): ?>
                    <?php $statusClass = issueStatusClass($issue['status']); ?>
                    <a href="public-feed.php?issue=<?= (int) $issue['id'] ?>" class="issue-card d-flex gap-3 text-decoration-none text-dark rounded-3 p-2 mb-2 border">
                      <?php if ($issue['cover_url']): ?><img src="<?= $e($issue['cover_url']) ?>" alt="" class="rounded-2 object-fit-cover" style="width:62px;height:62px"><?php else: ?><span class="rounded-2 bg-light d-inline-flex align-items-center justify-content-center text-muted" style="width:62px;height:62px"><i class="fas fa-image"></i></span><?php endif; ?>
                      <span class="min-w-0 flex-grow-1"><span class="d-flex justify-content-between gap-2"><strong class="text-truncate"><?= $e($issue['title'] ?: issueCategoryLabel($issue['category'])) ?></strong><span class="badge text-bg-<?= $e($statusClass) ?> small"><?= $e(issueStatusLabel($issue['status'])) ?></span></span><span class="d-block small text-muted text-truncate mt-1"><i class="fas fa-map-marker-alt me-1"></i><?= $e($issue['address'] ?: 'Location recorded') ?></span><span class="d-block small text-muted mt-1"><i class="fas fa-thumbs-up me-1"></i><?= formatNumber((int) $issue['upvote_count']) ?> · <?= (int) $issue['report_count'] ?> reports</span></span>
                    </a>
                  <?php endforeach; ?>
                <?php endif; ?>
              </div>
            </div>
          </aside>
        </div>
      </div>
    </main>
  </div>

  <?php require_once __DIR__ . '/../../components/footer.php'; ?>
</body>
</html>
