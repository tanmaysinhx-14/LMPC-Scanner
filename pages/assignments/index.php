<?php
declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';
$bootstrapData = bootstrapAccounts(['required_roles' => ['worker']]);
extract($bootstrapData);

$viewer = sessionUser() ?? [];
$assignments = [];
$availableIssues = [];
$workRequests = [];
$assignmentStats = ['active' => 0, 'completed' => 0];

if ($db instanceof PDO) {
  try {
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
    $stmt->execute([(int) ($viewer['id'] ?? 0)]);
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
      "SELECT wr.id, wr.message, wr.status, wr.created_at, i.title
         FROM work_requests wr
         LEFT JOIN issues i ON i.id = wr.issue_id
        WHERE wr.worker_id = ?
        ORDER BY wr.created_at DESC, wr.id DESC
        LIMIT 8"
    );
    $requestStmt->execute([(int) $viewer['id']]);
    $workRequests = $requestStmt->fetchAll(PDO::FETCH_ASSOC);
  } catch (Throwable $exception) {
    error_log('Worker assignments failed: ' . $exception->getMessage());
  }
}

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$statusOptions = ['in_progress', 'resolved'];
$displayStatus = static fn (?string $status): string => $status === 'pending' || $status === 'acknowledged' ? 'Open' : issueStatusLabel($status);
?>
<?php require_once CIVICCONNECT_ROOT . '/components/header.php'; ?>

<body class="app-body">
  <div class="app-shell">
    <aside id="workerSidebar" class="app-sidebar" data-app-sidebar aria-label="Worker navigation">
      <div class="app-sidebar-brand"><span class="app-brand-mark"><i class="fas fa-city"></i></span><span>CivicConnect<small>Field operations</small></span><button type="button" class="app-sidebar-close" data-sidebar-close aria-label="Close navigation"><i class="fas fa-xmark"></i></button></div>
      <div class="app-sidebar-label">My work</div>
      <nav class="nav flex-column gap-1"><a class="nav-link active" href="<?= $e(civicRoute('assignments')) ?>"><i class="fas fa-clipboard-list fa-fw"></i><span>Assignments</span><span class="nav-count"><?= $assignmentStats['active'] ?></span></a><a class="nav-link" href="<?= $e(civicRoute('feed')) ?>"><i class="fas fa-layer-group fa-fw"></i><span>Community feed</span></a><a class="nav-link" href="<?= $e(civicRoute('pulse')) ?>"><i class="fas fa-map-location-dot fa-fw"></i><span>City pulse</span></a><a class="nav-link" href="<?= $e(civicRoute('profile')) ?>"><i class="fas fa-user fa-fw"></i><span>Profile</span></a><a class="nav-link" href="<?= $e(civicRoute('dashboard')) ?>"><i class="fas fa-chart-pie fa-fw"></i><span>Dashboard</span></a></nav>
      <div class="app-sidebar-spacer"></div>
      <div class="app-sidebar-user"><span class="app-avatar"><?= $e(strtoupper(substr((string) ($viewer['name'] ?? 'W'), 0, 1))) ?></span><div><strong><?= $e($viewer['name'] ?? 'Worker') ?></strong><small><?= $e($viewer['city'] ?? 'Field worker') ?></small></div></div><a class="nav-link text-danger mt-2" href="<?= $e(civicRoute('logout')) ?>"><i class="fas fa-arrow-right-from-bracket fa-fw"></i><span>Sign out</span></a>
    </aside>
    <div class="app-sidebar-backdrop" data-sidebar-backdrop="workerSidebar"></div>

    <main class="app-content"><header class="app-topbar"><div class="d-flex align-items-center gap-3"><button class="app-menu-toggle" data-sidebar-toggle="workerSidebar" aria-controls="workerSidebar" aria-expanded="true" aria-label="Toggle navigation"><i class="fas fa-bars"></i></button><div><div class="eyebrow">Field operations</div><h1>My assignments</h1></div></div><a class="btn btn-sm btn-outline-secondary" href="<?= $e(civicRoute('feed')) ?>"><i class="fas fa-eye me-1"></i>Public view</a></header>
      <div class="container-fluid px-3 px-lg-4 py-4"><section class="dashboard-hero mb-4"><div><span class="hero-kicker"><i class="fas fa-helmet-safety me-1"></i>Assigned to you</span><h2>Make the next fix count.</h2><p>Update only the issues assigned to your account. Every change is recorded for citizens and supervisors.</p></div><div class="hero-art"><span></span><span></span><span></span></div></section>
        <section class="data-card mb-4"><div class="data-card-header"><div><span class="eyebrow">Need a new task?</span><h2>Request work from an administrator</h2></div><span class="count-badge"><?= count($availableIssues) ?> available</span></div><div class="p-3 p-lg-4"><?php if ($availableIssues === []): ?><p class="small text-muted mb-0"><i class="fas fa-circle-info me-1"></i>No unassigned issues are available right now. You can still see the status of your previous requests below.</p><?php else: ?><form id="workRequestForm" class="row g-3"><div class="col-12 col-lg-6"><label class="form-label small fw-semibold" for="requestIssue">Issue to request</label><select class="form-select" id="requestIssue" required><option value="">Choose an unassigned issue</option><?php foreach ($availableIssues as $issue): ?><option value="<?= (int) $issue['id'] ?>"><?= $e($issue['title'] ?: issueCategoryLabel($issue['category'])) ?> · <?= (int) $issue['report_count'] ?> reports · <?= $e($issue['address'] ?: 'Location recorded') ?></option><?php endforeach; ?></select></div><div class="col-12 col-lg-4"><label class="form-label small fw-semibold" for="requestMessage">Message</label><input class="form-control" id="requestMessage" maxlength="1000" placeholder="I am available to take this task." required></div><div class="col-12 col-lg-2 d-flex align-items-end"><button class="btn btn-primary w-100" type="submit"><i class="fas fa-paper-plane me-1"></i>Request</button></div></form><?php endif; ?><div class="d-flex flex-wrap gap-2 mt-3"><?php foreach ($workRequests as $request): ?><span class="badge rounded-pill text-bg-<?= $request['status'] === 'approved' ? 'success' : ($request['status'] === 'declined' ? 'secondary' : 'warning') ?>"><?= $e(ucfirst($request['status'])) ?> · <?= $e($request['title'] ?: 'Work request') ?></span><?php endforeach; ?></div></div></section>
        <div class="row g-3 mb-4"><div class="col-6 col-md-3"><div class="metric-card metric-primary"><div class="metric-icon"><i class="fas fa-list-check"></i></div><div><span class="metric-label">Active</span><strong><?= $assignmentStats['active'] ?></strong><small>Need action</small></div></div></div><div class="col-6 col-md-3"><div class="metric-card metric-success"><div class="metric-icon"><i class="fas fa-check"></i></div><div><span class="metric-label">Completed</span><strong><?= $assignmentStats['completed'] ?></strong><small>Closed assignments</small></div></div></div></div>
        <section class="data-card"><div class="data-card-header"><div><span class="eyebrow">Work queue</span><h2>Issues assigned to me</h2></div><span class="count-badge"><?= count($assignments) ?> total</span></div><?php if ($assignments === []): ?><div class="empty-state"><i class="fas fa-clipboard-check"></i><h3>No assignments yet</h3><p>Your administrator will assign issues here when field work is ready.</p></div><?php else: ?><div class="staff-issue-list"><?php foreach ($assignments as $assignment): ?><article class="staff-issue-row <?= $assignment['completed_at'] ? 'is-complete' : '' ?>" data-issue-row="<?= (int) $assignment['issue_id'] ?>"><div class="staff-issue-priority"><span><?= number_format((float) $assignment['priority_score'], 0) ?></span><small>priority</small></div><div class="staff-issue-main"><div class="d-flex flex-wrap gap-2 align-items-center"><span class="pill pill-category"><?= $e(issueCategoryLabel($assignment['category'])) ?></span><span class="pill pill-<?= $e(issueStatusClass($assignment['status'])) ?>" data-issue-status><?= $e($displayStatus($assignment['status'])) ?></span></div><h3><?= $e($assignment['title'] ?: issueCategoryLabel($assignment['category'])) ?></h3><p><i class="fas fa-location-dot me-1"></i><?= $e($assignment['address'] ?: 'Location recorded') ?></p><small><?= (int) $assignment['report_count'] ?> reports · <?= (int) $assignment['image_count'] ?> photos · <?= $e($assignment['notes'] ?: 'No assignment note') ?></small></div><div class="staff-issue-actions"><?php if ($assignment['completed_at']): ?><span class="small text-success"><i class="fas fa-circle-check me-1"></i>Completed</span><?php else: ?><select class="form-select form-select-sm" data-status-select="<?= (int) $assignment['issue_id'] ?>" aria-label="Change assignment status"><?php foreach ($statusOptions as $option): ?><option value="<?= $e($option) ?>" <?= $assignment['status'] === $option ? 'selected' : '' ?>><?= $e(issueStatusLabel($option)) ?></option><?php endforeach; ?></select><button class="btn btn-sm btn-primary" type="button" data-status-save="<?= (int) $assignment['issue_id'] ?>">Save</button><button class="btn btn-sm btn-success" type="button" data-complete-id="<?= (int) $assignment['issue_id'] ?>"><i class="fas fa-check me-1"></i>Complete</button><?php endif; ?><a class="btn btn-sm btn-link" href="<?= $e(civicRoute('issue_detail', ['id' => (int) $assignment['issue_id']])) ?>">Details</a></div></article><?php endforeach; ?></div><?php endif; ?></section>
      </div>
    </main>
  </div>
  <script>
    const workerCsrfToken = <?= json_encode(csrfToken(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const statusEndpoint = <?= json_encode(civicApi('issues/status.php')) ?>;
    const workRequestEndpoint = <?= json_encode(civicApi('work/request.php')) ?>;
    document.querySelectorAll('[data-status-save]').forEach((button) => button.addEventListener('click', async () => {
      const issueId = Number(button.dataset.statusSave);
      const select = document.querySelector(`[data-status-select="${issueId}"]`);
      button.disabled = true;
      try {
        const response = await fetch(statusEndpoint, {method: 'POST', headers: {'Content-Type': 'application/json', 'X-CSRF-Token': workerCsrfToken}, body: JSON.stringify({issue_id: issueId, status: select.value})});
        const result = await response.json();
        if (!response.ok) throw new Error(result.message || 'Unable to update assignment.');
        const status = document.querySelector(`[data-issue-row="${issueId}"] [data-issue-status]`);
        status.textContent = select.options[select.selectedIndex].text;
        status.className = 'pill pill-' + (select.value === 'resolved' ? 'success' : 'warning');
        button.textContent = 'Saved';
        setTimeout(() => { button.textContent = 'Save'; }, 1400);
      } catch (error) { window.alert(error.message || 'Unable to update assignment.'); } finally { button.disabled = false; }
    }));
    const requestForm = document.getElementById('workRequestForm');
    requestForm?.addEventListener('submit', async (event) => {
      event.preventDefault();
      const button = requestForm.querySelector('button[type="submit"]');
      const issueId = Number(document.getElementById('requestIssue')?.value || 0);
      const message = document.getElementById('requestMessage')?.value.trim() || '';
      if (!issueId || !message) return;
      button.disabled = true;
      try {
        const response = await fetch(workRequestEndpoint, {method: 'POST', headers: {'Content-Type': 'application/json', 'X-CSRF-Token': workerCsrfToken}, body: JSON.stringify({issue_id: issueId, message})});
        const result = await response.json();
        if (!response.ok) throw new Error(result.message || 'Unable to request work.');
        window.location.reload();
      } catch (error) { window.alert(error.message || 'Unable to request work.'); button.disabled = false; }
    });
    document.querySelectorAll('[data-complete-id]').forEach((button) => button.addEventListener('click', async () => {
      const issueId = Number(button.dataset.completeId);
      if (!issueId || !window.confirm('Mark this assignment as completed?')) return;
      button.disabled = true;
      try {
        const response = await fetch(statusEndpoint, {method: 'POST', headers: {'Content-Type': 'application/json', 'X-CSRF-Token': workerCsrfToken}, body: JSON.stringify({issue_id: issueId, status: 'resolved', note: 'Marked completed by worker.'})});
        const result = await response.json();
        if (!response.ok) throw new Error(result.message || 'Unable to complete assignment.');
        window.location.reload();
      } catch (error) { window.alert(error.message || 'Unable to complete assignment.'); button.disabled = false; }
    }));
  </script>
  <?php require_once CIVICCONNECT_ROOT . '/components/footer.php'; ?>
</body>
</html>
