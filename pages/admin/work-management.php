<?php
declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';
$bootstrapData = bootstrapAccounts(['required_roles' => ['admin']]);
extract($bootstrapData);

$viewer = sessionUser() ?? [];
$workers = [];
$availableIssues = [];
$activeAssignments = [];
$requests = [];
$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');

if ($db instanceof PDO) {
  try {
    $workers = $db->query(
      "SELECT id, name, email, city
         FROM users
        WHERE role = 'worker' AND is_active = 1
        ORDER BY name ASC"
    )->fetchAll(PDO::FETCH_ASSOC);

    $availableIssues = $db->query(
      "SELECT i.id, i.title, i.category, i.status, i.address, i.severity, i.priority_score, i.created_at,
              COUNT(ir.id) AS report_count
         FROM issues i
         LEFT JOIN issue_reports ir ON ir.issue_id = i.id
         LEFT JOIN assignments active_assignment
           ON active_assignment.issue_id = i.id AND active_assignment.completed_at IS NULL
        WHERE i.status IN ('pending', 'acknowledged', 'in_progress')
          AND active_assignment.id IS NULL
        GROUP BY i.id, i.title, i.category, i.status, i.address, i.severity, i.priority_score, i.created_at
        ORDER BY i.priority_score DESC, i.created_at DESC
        LIMIT 100"
    )->fetchAll(PDO::FETCH_ASSOC);

    $activeAssignments = $db->query(
      "SELECT a.id, a.assigned_at, a.notes, i.id AS issue_id, i.title, i.category, i.status, i.address,
              worker.name AS worker_name, administrator.name AS assigned_by_name
         FROM assignments a
         INNER JOIN issues i ON i.id = a.issue_id
         INNER JOIN users worker ON worker.id = a.worker_id
         LEFT JOIN users administrator ON administrator.id = a.assigned_by
        WHERE a.completed_at IS NULL
        ORDER BY i.priority_score DESC, a.assigned_at DESC"
    )->fetchAll(PDO::FETCH_ASSOC);

    $requests = $db->query(
      "SELECT wr.id, wr.message, wr.created_at, wr.issue_id,
              worker.name AS worker_name, worker.email AS worker_email,
              i.title, i.address, i.status AS issue_status
         FROM work_requests wr
         INNER JOIN users worker ON worker.id = wr.worker_id
         LEFT JOIN issues i ON i.id = wr.issue_id
        WHERE wr.status = 'pending'
        ORDER BY wr.created_at ASC, wr.id ASC"
    )->fetchAll(PDO::FETCH_ASSOC);
  } catch (Throwable $exception) {
    error_log('Work management query failed: ' . $exception->getMessage());
  }
}
?>
<?php require_once __DIR__ . '/../../components/header.php'; ?>
<style>
  .work-hero { padding: 1.7rem; border-radius: 1.2rem; color: #fff; background: linear-gradient(120deg, #0f3b4d, #147d72 58%, #3ab88e); box-shadow: 0 18px 40px rgba(20,125,114,.2); }
  .work-card { border: 1px solid var(--cc-line); border-radius: var(--cc-radius); background: #fff; box-shadow: var(--cc-shadow); }
  .work-card-header { display:flex; align-items:center; justify-content:space-between; gap:1rem; padding:1.1rem 1.25rem; border-bottom:1px solid var(--cc-line); }
  .work-card-header h2 { margin: .2rem 0 0; font-size: 1.03rem; font-weight: 780; }
  .work-card-body { padding: 1.15rem 1.25rem; }
  .work-row { display:grid; grid-template-columns: 1.7rem minmax(0,1fr) auto; gap:.7rem; align-items:center; padding:.8rem 0; border-bottom:1px solid var(--cc-line); }
  .work-row:last-child { border-bottom:0; }
  .work-row-icon { display:grid; width:1.7rem; height:1.7rem; place-items:center; border-radius:.55rem; color:#0d7667; background:#e7f7f2; font-size:.72rem; }
  .work-row strong { display:block; color:var(--cc-ink); font-size:.82rem; }
  .work-row small { display:block; margin-top:.18rem; color:var(--cc-muted); font-size:.68rem; }
  .work-row-actions { display:flex; flex-wrap:wrap; justify-content:flex-end; gap:.35rem; }
  .request-message { margin-top:.35rem; color:var(--cc-muted); font-size:.7rem; }
  .status-chip { display:inline-flex; align-items:center; padding:.25rem .55rem; border-radius:999px; color:#166534; background:#ecfdf3; font-size:.65rem; font-weight:700; }
  .empty-copy { padding:1.8rem .5rem; color:var(--cc-muted); text-align:center; }
  .empty-copy i { display:block; margin-bottom:.6rem; font-size:1.5rem; opacity:.7; }
  @media (max-width: 680px) { .work-row { grid-template-columns: 1.7rem minmax(0,1fr); } .work-row-actions { grid-column: 2; justify-content:flex-start; } }
</style>
<body class="app-body">
  <div class="app-shell">
    <aside id="adminSidebar" class="app-sidebar" data-app-sidebar aria-label="Administrator navigation">
      <div class="app-sidebar-brand"><span class="app-brand-mark"><i class="fas fa-city"></i></span><span>CivicConnect<small>Administrator console</small></span><button type="button" class="app-sidebar-close" data-sidebar-close aria-label="Close navigation"><i class="fas fa-xmark"></i></button></div>
      <div class="app-sidebar-label">Platform</div>
      <nav class="nav flex-column gap-1">
        <a class="nav-link" href="admin-dashboard.php"><i class="fas fa-chart-pie fa-fw"></i><span>Dashboard</span></a>
        <a class="nav-link active" href="work-management.php"><i class="fas fa-briefcase fa-fw"></i><span>Work management</span></a>
        <a class="nav-link" href="../citizen/public-feed.php"><i class="fas fa-layer-group fa-fw"></i><span>Community feed</span></a>
        <a class="nav-link" href="../heatmap/"><i class="fas fa-map-location-dot fa-fw"></i><span>City pulse</span></a>
        <a class="nav-link" href="../register/index.php"><i class="fas fa-user-plus fa-fw"></i><span>Create account</span></a>
      </nav>
      <div class="app-sidebar-spacer"></div>
      <div class="app-sidebar-user"><span class="app-avatar"><?= $e(strtoupper(substr((string) ($viewer['name'] ?? 'A'), 0, 1))) ?></span><div><strong><?= $e($viewer['name'] ?? 'Administrator') ?></strong><small>Full platform access</small></div></div>
      <a class="nav-link text-danger mt-2" href="../logout/"><i class="fas fa-arrow-right-from-bracket fa-fw"></i><span>Sign out</span></a>
    </aside>
    <div class="app-sidebar-backdrop" data-sidebar-backdrop="adminSidebar"></div>

    <main class="app-content">
      <header class="app-topbar"><div class="d-flex align-items-center gap-3"><button class="app-menu-toggle" data-sidebar-toggle="adminSidebar" aria-controls="adminSidebar" aria-expanded="true" aria-label="Toggle navigation"><i class="fas fa-bars"></i></button><div><div class="eyebrow">Administrator workflow</div><h1>Work management</h1></div></div><a class="btn btn-sm btn-outline-secondary" href="admin-dashboard.php"><i class="fas fa-arrow-left me-1"></i>Dashboard</a></header>
      <div class="container-fluid px-3 px-lg-4 py-4">
        <section class="work-hero mb-4"><div class="eyebrow text-white-50">Allocate the next fix</div><h2 class="h3 fw-bold mb-2">Turn city signals into field work.</h2><p class="mb-0 text-white-50">Assign unowned problems to active workers, review requests, and keep citizens informed automatically.</p></section>

        <div class="row g-4">
          <div class="col-12 col-xl-7">
            <section class="work-card mb-4"><div class="work-card-header"><div><span class="eyebrow">Direct assignment</span><h2>Allocate an issue</h2></div><span class="count-badge"><?= count($availableIssues) ?> available</span></div><div class="work-card-body">
              <?php if ($workers === []): ?><div class="alert alert-warning small mb-0"><i class="fas fa-user-hard-hat me-1"></i>Create a worker account before assigning field work.</div><?php elseif ($availableIssues === []): ?><div class="empty-copy"><i class="fas fa-circle-check"></i>Every active issue currently has an owner.</div><?php else: ?>
                <form id="assignForm" class="row g-3">
                  <div class="col-12"><label class="form-label small fw-semibold" for="assignIssue">Issue</label><select class="form-select" id="assignIssue" required><option value="">Select an unassigned issue</option><?php foreach ($availableIssues as $issue): ?><option value="<?= (int) $issue['id'] ?>"><?= $e($issue['title'] ?: issueCategoryLabel($issue['category'])) ?> · <?= (int) $issue['report_count'] ?> reports · <?= $e($issue['address'] ?: 'Location recorded') ?></option><?php endforeach; ?></select></div>
                  <div class="col-12 col-md-6"><label class="form-label small fw-semibold" for="assignWorker">Worker</label><select class="form-select" id="assignWorker" required><option value="">Select worker</option><?php foreach ($workers as $worker): ?><option value="<?= (int) $worker['id'] ?>"><?= $e($worker['name']) ?> · <?= $e($worker['city'] ?: 'Field team') ?></option><?php endforeach; ?></select></div>
                  <div class="col-12 col-md-6"><label class="form-label small fw-semibold" for="assignNotes">Instruction <span class="text-muted fw-normal">(optional)</span></label><input class="form-control" id="assignNotes" maxlength="2000" placeholder="What should the worker check?"></div>
                  <div class="col-12 d-flex justify-content-end"><button class="btn btn-primary" type="submit"><i class="fas fa-share-from-square me-1"></i>Assign work</button></div>
                </form>
              <?php endif; ?>
            </div></section>

            <section class="work-card"><div class="work-card-header"><div><span class="eyebrow">Current ownership</span><h2>Active assignments</h2></div><span class="count-badge"><?= count($activeAssignments) ?> active</span></div><div class="work-card-body">
              <?php if ($activeAssignments === []): ?><div class="empty-copy"><i class="fas fa-inbox"></i>No active assignments yet.</div><?php else: ?><?php foreach ($activeAssignments as $assignment): ?><div class="work-row"><span class="work-row-icon"><i class="fas fa-helmet-safety"></i></span><div><strong><?= $e($assignment['title'] ?: issueCategoryLabel($assignment['category'])) ?></strong><small><?= $e($assignment['worker_name']) ?> · <?= $e($assignment['address'] ?? 'Location recorded') ?> · <?= $e(issueStatusLabel($assignment['status'])) ?><?php if ($assignment['notes']): ?><br><?= $e($assignment['notes']) ?><?php endif; ?></small></div><span class="status-chip"><i class="fas fa-circle me-1"></i>Assigned</span></div><?php endforeach; ?><?php endif; ?>
            </div></section>
          </div>

          <div class="col-12 col-xl-5">
            <section class="work-card"><div class="work-card-header"><div><span class="eyebrow">Worker inbox</span><h2>Requests for work</h2></div><span class="count-badge"><?= count($requests) ?> pending</span></div><div class="work-card-body" id="requestList">
              <?php if ($requests === []): ?><div class="empty-copy"><i class="fas fa-paper-plane"></i>No pending worker requests.</div><?php else: ?><?php foreach ($requests as $request): ?><article class="work-row" data-request-row="<?= (int) $request['id'] ?>"><span class="work-row-icon"><i class="fas fa-inbox"></i></span><div><strong><?= $e($request['worker_name']) ?> requested work</strong><small><?= $e($request['title'] ?: 'Issue not specified') ?><?php if ($request['address']): ?> · <?= $e($request['address']) ?><?php endif; ?></small><div class="request-message">“<?= $e($request['message']) ?>”</div></div><div class="work-row-actions"><button class="btn btn-sm btn-success" type="button" data-review-request="<?= (int) $request['id'] ?>" data-review-action="approve"><i class="fas fa-check me-1"></i>Approve</button><button class="btn btn-sm btn-outline-danger" type="button" data-review-request="<?= (int) $request['id'] ?>" data-review-action="decline"><i class="fas fa-xmark me-1"></i>Decline</button></div></article><?php endforeach; ?><?php endif; ?>
            </div></section>
          </div>
        </div>
      </div>
    </main>
  </div>
  <script>
    const adminWorkCsrfToken = <?= json_encode(csrfToken(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    async function postWork(url, payload) {
      const response = await fetch(url, {method: 'POST', headers: {'Content-Type': 'application/json', 'X-CSRF-Token': adminWorkCsrfToken}, body: JSON.stringify(payload)});
      const result = await response.json().catch(() => ({}));
      if (!response.ok) throw new Error(result.message || 'The work action could not be completed.');
      return result;
    }
    document.getElementById('assignForm')?.addEventListener('submit', async event => {
      event.preventDefault();
      const form = event.currentTarget;
      const button = form.querySelector('button[type="submit"]');
      button.disabled = true;
      try {
        await postWork('../../api/work/assign.php', {issue_id: Number(document.getElementById('assignIssue').value), worker_id: Number(document.getElementById('assignWorker').value), notes: document.getElementById('assignNotes').value});
        window.location.reload();
      } catch (error) { window.alert(error.message); button.disabled = false; }
    });
    document.querySelectorAll('[data-review-request]').forEach(button => button.addEventListener('click', async () => {
      button.disabled = true;
      try {
        await postWork('../../api/work/review-request.php', {request_id: Number(button.dataset.reviewRequest), action: button.dataset.reviewAction});
        window.location.reload();
      } catch (error) { window.alert(error.message); button.disabled = false; }
    }));
  </script>
  <?php require_once __DIR__ . '/../../components/footer.php'; ?>
</body>
</html>
