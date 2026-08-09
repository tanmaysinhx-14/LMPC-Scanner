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
    if ($community['items'] === [] && ($user['ward_id'] ?? null) !== null) {
      $community = fetchIssueFeed($db, ['limit' => 4, 'sort' => 'hot', 'viewer_id' => (int) $user['id']]);
    }
  } catch (Throwable $exception) {
    error_log('Citizen dashboard query failed: ' . $exception->getMessage());
  }
}

$stats = $dashboard['stats'];
$displayName = (string) ($user['name'] ?? 'Citizen');
$nameParts = preg_split('/\s+/', trim($displayName)) ?: [];
$initials = strtoupper(substr((string) ($nameParts[0] ?? 'C'), 0, 1));
if (count($nameParts) > 1) $initials .= strtoupper(substr((string) $nameParts[count($nameParts) - 1], 0, 1));
$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');

require_once __DIR__ . '/../../components/header.php';
?>

<style>
  .dashboard-hero { padding: clamp(1.25rem, 2.7vw, 2.35rem); border-radius: 1.2rem; color: #fff; background: linear-gradient(120deg, #312e81, #4f46e5 55%, #6d5dfc); box-shadow: 0 18px 36px rgba(67, 56, 202, .22); }
  .dashboard-hero .hero-copy { max-width: 41rem; }
  .activity-card, .dashboard-card { border: 1px solid var(--cc-line); border-radius: var(--cc-radius); background: #fff; box-shadow: var(--cc-shadow); }
  .report-row-title { color: var(--cc-ink); font-size: .98rem; font-weight: 780; letter-spacing: -.015em; }
  .report-row-location { color: var(--cc-muted); font-size: .81rem; }
  .issue-card { color: var(--cc-ink); }
  .issue-card .issue-card-title { font-size: .86rem; font-weight: 750; }
</style>

<body class="cc-page">
  <div class="app-shell d-flex">
    <aside id="citizenSidebar" class="app-sidebar" data-app-sidebar aria-hidden="false">
      <div class="d-flex align-items-center justify-content-between mb-4">
        <a href="citizen-dashboard.php" class="app-sidebar-brand d-flex align-items-center text-decoration-none gap-2"><i class="fas fa-city fs-4 text-primary"></i><span>CivicConnect</span></a>
        <button class="app-sidebar-close btn btn-light btn-sm" type="button" data-sidebar-close aria-label="Close menu"><i class="fas fa-times"></i></button>
      </div>
      <div class="small text-uppercase text-muted fw-semibold mb-2">Citizen space</div>
      <ul class="nav nav-pills flex-column gap-1 mb-auto">
        <li class="nav-item"><a href="citizen-dashboard.php" class="nav-link active"><i class="fas fa-th-large fa-fw"></i>Dashboard</a></li>
        <li class="nav-item"><a href="../report/" class="nav-link"><i class="fas fa-plus-circle fa-fw"></i>Report issue</a></li>
        <li class="nav-item"><a href="public-feed.php" class="nav-link"><i class="fas fa-globe fa-fw"></i>Public feed</a></li>
        <li class="nav-item"><a href="../heatmap/" class="nav-link"><i class="fas fa-map-location-dot fa-fw"></i>City pulse</a></li>
        <li class="nav-item"><a href="../account/profile.php" class="nav-link"><i class="fas fa-user fa-fw"></i>Profile</a></li>
      </ul>
      <hr>
      <div class="small text-muted mb-2 text-truncate" title="<?= $e($user['email'] ?? '') ?>"><?= $e($user['email'] ?? '') ?></div>
      <a href="../logout/" class="nav-link text-danger px-0"><i class="fas fa-sign-out-alt fa-fw me-2"></i>Logout</a>
    </aside>
    <div class="app-sidebar-backdrop" data-sidebar-backdrop="citizenSidebar"></div>

    <main class="app-content overflow-auto">
      <nav class="navbar app-topbar sticky-top px-3 px-lg-4 py-2">
        <div class="d-flex align-items-center gap-3">
          <button class="btn btn-light d-md-none" type="button" aria-label="Toggle navigation" data-sidebar-toggle="citizenSidebar" aria-expanded="false"><i class="fas fa-bars"></i></button>
          <div><div class="fw-bold">Citizen dashboard</div><div class="small text-muted">Track the issues you helped bring to the city</div></div>
        </div>
        <div class="dropdown">
          <a href="#" class="d-flex align-items-center gap-2 link-dark text-decoration-none dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false"><span class="bg-primary text-white d-flex align-items-center justify-content-center rounded-circle fw-bold" style="width:38px;height:38px;"><?= $e($initials) ?></span><span class="d-none d-sm-inline"><?= $e($displayName) ?></span></a>
          <ul class="dropdown-menu dropdown-menu-end shadow-sm"><li><span class="dropdown-item-text small text-muted"><?= $e($user['city'] ?? 'City resident') ?></span></li><li><a class="dropdown-item" href="../account/profile.php"><i class="fas fa-user me-2"></i>Profile</a></li><li><a class="dropdown-item" href="../account/change-password.php"><i class="fas fa-lock me-2"></i>Change password</a></li><li><hr class="dropdown-divider"></li><li><a class="dropdown-item text-danger" href="../logout/"><i class="fas fa-sign-out-alt me-2"></i>Logout</a></li></ul>
        </div>
      </nav>

      <div class="container-fluid p-3 p-lg-4">
        <section class="dashboard-hero d-flex flex-wrap justify-content-between align-items-end gap-4 mb-4">
          <div class="hero-copy"><div class="text-white-50 small fw-semibold text-uppercase mb-2">Welcome back, <?= $e($displayName) ?></div><h1 class="h2 fw-bold mb-2">Your reports are helping shape a better city.</h1><p class="mb-0 text-white-50">Follow report progress, see nearby evidence, and add a report whenever a civic issue needs attention.</p></div>
          <a href="../report/" class="btn btn-light text-primary"><i class="fas fa-plus me-2"></i>Report an issue</a>
        </section>

        <?php if (!($db instanceof PDO)): ?><div class="alert alert-warning">The database is unavailable. Your dashboard will refresh automatically when it is back online.</div><?php endif; ?>

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
              <div class="metric-card h-100">
                <div class="card-body d-flex flex-row justify-content-between align-items-center px-2 py-1">
                  <div>
                    <div class="text-muted small fw-semibold mb-2"><?= $e($card['label']) ?></div>
                    <div class="metric-number mb-0"><?= formatNumber((int) $card['value']) ?></div>
                  </div>
                  <span class="metric-icon text-<?= $e($card['class']) ?>">
                    <i class="fas <?= $e($card['icon']) ?>"></i>
                  </span>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        </section>

        <div class="row g-4">
          <section class="col-12 col-xl-7">
            <div class="card h-100 border-0 shadow-sm rounded-4">
              <div class="card-header bg-white border-bottom-0 pt-4 px-4 d-flex justify-content-between align-items-center">
                <div><h2 class="h5 fw-bold mb-1">Your recent reports</h2><p class="small text-muted mb-0">Tracked evidence from your neighbourhood.</p></div>
                <a href="public-feed.php" class="btn btn-sm btn-outline-primary">Browse feed</a>
              </div>
              <div class="card-body px-4 pb-4">
                <?php if ($dashboard['activity'] === []): ?>
                  <div class="text-center py-5"><i class="fas fa-clipboard-list fa-2x text-muted mb-3"></i><p class="text-muted mb-3">You have not submitted a report yet.</p><a href="../report/" class="btn btn-primary btn-sm">Submit your first report</a></div>
                <?php else: ?>
                  <?php foreach ($dashboard['activity'] as $activity): ?>
                    <?php $statusClass = issueStatusClass($activity['status']); ?>
                    <!-- Restructured Report Card -->
                    <article class="data-row d-flex flex-column flex-sm-row gap-3 p-3 mb-3">
                      <?php if ($activity['cover_url']): ?>
                        <img src="<?= $e($activity['cover_url']) ?>" class="rounded-3 object-fit-cover" style="width: 80px; height: 80px;" alt="Report photo" loading="lazy">
                      <?php else: ?>
                        <span class="d-flex align-items-center justify-content-center bg-light rounded-3 text-secondary" style="width: 80px; height: 80px;"><i class="fas fa-camera"></i></span>
                      <?php endif; ?>

                      <div class="flex-grow-1">
                        <div class="d-flex justify-content-between align-items-start mb-1">
                          <a href="public-feed.php?issue=<?= (int) $activity['id'] ?>" class="text-dark fw-bold text-decoration-none text-truncate" style="max-width: 70%;"><?= $e($activity['title'] ?: issueCategoryLabel($activity['category'])) ?></a>
                          <span class="badge bg-<?= $e($statusClass) ?> bg-opacity-10 text-<?= $e($statusClass) ?> border border-<?= $e($statusClass) ?>-subtle"><?= $e(issueStatusLabel($activity['status'])) ?></span>
                        </div>
                        <div class="small text-muted mb-2"><i class="fas fa-map-marker-alt text-primary me-1"></i><?= $e($activity['address'] ?: 'Location recorded') ?> &middot; <?= $e(date('M d', strtotime((string) ($activity['last_reported_at'] ?? $activity['created_at'])))) ?></div>
                        <?php if (!empty($activity['assigned_worker_name'])): ?><div class="small text-success mb-2"><i class="fas fa-helmet-safety me-1"></i>Assigned to <?= $e($activity['assigned_worker_name']) ?><?php if (!empty($activity['assigned_by_name'])): ?> by <?= $e($activity['assigned_by_name']) ?><?php endif; ?><?= empty($activity['assignment_completed_at']) ? '' : ' · Completed' ?></div><?php else: ?><div class="small text-muted mb-2"><i class="fas fa-hourglass-half me-1"></i>Awaiting admin assignment</div><?php endif; ?>
                        <?php if ($activity['status'] === 'resolved' && !empty($activity['assignment_completed_at']) && empty($activity['citizen_verified_at'])): ?>
                          <div class="alert alert-success py-2 px-3 small d-flex flex-wrap align-items-center gap-2 mb-2">
                            <span><i class="fas fa-circle-check me-1"></i>Was the fix completed correctly?</span>
                            <label class="btn btn-sm btn-outline-secondary mb-0"><i class="fas fa-camera me-1"></i>After-photo<input type="file" accept="image/jpeg,image/png,image/webp" class="d-none" data-after-image="<?= (int) $activity['id'] ?>"></label>
                            <button type="button" class="btn btn-sm btn-success" data-verify-resolution="<?= (int) $activity['id'] ?>">Yes, verify</button>
                            <button type="button" class="btn btn-sm btn-outline-danger" data-reopen-resolution="<?= (int) $activity['id'] ?>">Reopen</button>
                          </div>
                        <?php elseif (!empty($activity['citizen_verified_at'])): ?>
                          <div class="small text-success mb-2"><i class="fas fa-shield-heart me-1"></i>Resolution verified by you</div>
                        <?php endif; ?>
                        <div class="d-flex gap-2">
                          <span class="badge bg-light text-dark border"><i class="fas fa-tag me-1 text-muted"></i><?= $e(issueCategoryLabel($activity['category'])) ?></span>
                          <span class="badge bg-light text-dark border"><i class="fas fa-users me-1 text-muted"></i><?= (int) $activity['citizen_report_count'] ?></span>
                          <span class="badge bg-light text-dark border"><i class="fas fa-thumbs-up me-1 text-muted"></i><?= formatNumber((int) $activity['upvote_count']) ?></span>
                        </div>
                      </div>
                    </article>
                  <?php endforeach; ?>
                <?php endif; ?>
              </div>
            </div>
          </section>

          <aside class="col-12 col-xl-5">
            <div class="dashboard-card mb-4"><div class="card-body p-4 d-flex align-items-center gap-3"><span class="rounded-circle bg-warning-subtle text-warning d-inline-flex align-items-center justify-content-center" style="width:58px;height:58px"><i class="fas fa-trophy fa-lg"></i></span><div><div class="small text-muted">Contributor rank</div><div class="h3 fw-bold mb-1">#<?= (int) $dashboard['rank'] ?></div><div class="small text-success">Keep helping your neighbourhood</div></div></div></div>
            <div class="dashboard-card"><div class="card-header bg-white border-0 pt-4 px-4 d-flex justify-content-between align-items-center"><h2 class="h5 fw-bold mb-0">Community issues</h2><a href="public-feed.php" class="small text-decoration-none">See all</a></div><div class="card-body px-4 pb-4">
              <?php if ($community['items'] === []): ?><p class="text-muted small mb-0">No public issues have been submitted yet.</p><?php else: ?>
                <?php foreach ($community['items'] as $issue): ?>
                  <?php $statusClass = issueStatusClass($issue['status']); ?>
                  <a href="public-feed.php?issue=<?= (int) $issue['id'] ?>" class="data-row issue-card d-flex gap-3 text-decoration-none p-2 mb-2">
                    <?php if ($issue['cover_url']): ?><img src="<?= $e($issue['cover_url']) ?>" alt="" class="data-thumb" style="width:3.9rem;height:3.9rem;flex-basis:3.9rem"><?php else: ?><span class="data-thumb-placeholder" style="width:3.9rem;height:3.9rem;flex-basis:3.9rem"><i class="fas fa-image"></i></span><?php endif; ?>
                    <span class="min-w-0 flex-grow-1"><span class="d-flex justify-content-between gap-2"><strong class="issue-card-title text-truncate"><?= $e($issue['title'] ?: issueCategoryLabel($issue['category'])) ?></strong><span class="badge text-bg-<?= $e($statusClass) ?> small"><?= $e(issueStatusLabel($issue['status'])) ?></span></span><span class="d-block small text-muted text-truncate mt-1"><i class="fas fa-map-marker-alt me-1"></i><?= $e($issue['address'] ?: 'Location recorded') ?></span><span class="d-block small text-muted mt-1"><i class="fas fa-thumbs-up me-1"></i><?= formatNumber((int) $issue['upvote_count']) ?> &middot; <?= (int) $issue['report_count'] ?> reports</span></span>
                  </a>
                <?php endforeach; ?>
              <?php endif; ?>
            </div></div>
          </aside>
        </div>
      </div>
    </main>
  </div>
  <script>
    const citizenResolutionCsrf = <?= json_encode(csrfToken(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    async function updateResolution(issueId, action, reason = '', afterImage = null) {
      const options = {method: 'POST'};
      if (afterImage) {
        const formData = new FormData();
        formData.append('csrf_token', citizenResolutionCsrf);
        formData.append('issue_id', String(issueId));
        formData.append('action', action);
        formData.append('reason', reason);
        formData.append('after_image', afterImage, afterImage.name);
        options.body = formData;
      } else {
        options.headers = {'Content-Type': 'application/json', 'X-CSRF-Token': citizenResolutionCsrf};
        options.body = JSON.stringify({issue_id: issueId, action, reason});
      }
      const response = await fetch('../../api/issues/verify-resolution.php', options);
      const result = await response.json().catch(() => ({}));
      if (!response.ok) throw new Error(result.message || 'The resolution could not be updated.');
      window.location.reload();
    }
    document.querySelectorAll('[data-verify-resolution]').forEach(button => button.addEventListener('click', async () => {
      button.disabled = true;
      const imageInput = document.querySelector(`[data-after-image="${button.dataset.verifyResolution}"]`);
      try { await updateResolution(Number(button.dataset.verifyResolution), 'verify', '', imageInput?.files?.[0] || null); }
      catch (error) { window.alert(error.message); button.disabled = false; }
    }));
    document.querySelectorAll('[data-reopen-resolution]').forEach(button => button.addEventListener('click', async () => {
      const reason = window.prompt('What still needs to be fixed?');
      if (!reason || !reason.trim()) return;
      button.disabled = true;
      try { await updateResolution(Number(button.dataset.reopenResolution), 'reopen', reason.trim()); }
      catch (error) { window.alert(error.message); button.disabled = false; }
    }));
  </script>
  <?php require_once __DIR__ . '/../../components/footer.php'; ?>
</body>
</html>
