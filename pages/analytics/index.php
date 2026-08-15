<?php
declare(strict_types=1);
require __DIR__ . '/../../bootstrap.php';
$bootstrapData = bootstrapAccounts(['required_roles' => ['admin']]);
extract($bootstrapData);
$viewer = sessionUser() ?? [];
$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
?>
<?php require_once CIVICCONNECT_ROOT . '/components/header.php'; ?>
<body class="app-body">
  <div class="app-shell">
    <aside id="adminAnalyticsSidebar" class="app-sidebar" data-app-sidebar aria-label="Administrator navigation">
      <div class="app-sidebar-brand"><span class="app-brand-mark"><i class="fas fa-city"></i></span><span>CivicConnect<small>Administrator console</small></span><button type="button" class="app-sidebar-close" data-sidebar-close aria-label="Close navigation"><i class="fas fa-xmark"></i></button></div>
      <div class="app-sidebar-label">Platform</div>
      <nav class="nav flex-column gap-1"><a class="nav-link" href="<?= $e(civicRoute('dashboard')) ?>"><i class="fas fa-chart-pie fa-fw"></i><span>Dashboard</span></a><a class="nav-link" href="<?= $e(civicRoute('work_management')) ?>"><i class="fas fa-briefcase fa-fw"></i><span>Work management</span></a><a class="nav-link active" href="<?= $e(civicRoute('analytics')) ?>"><i class="fas fa-chart-line fa-fw"></i><span>Analytics</span></a><a class="nav-link" href="<?= $e(civicRoute('feed')) ?>"><i class="fas fa-layer-group fa-fw"></i><span>Community feed</span></a><a class="nav-link" href="<?= $e(civicRoute('pulse')) ?>"><i class="fas fa-map-location-dot fa-fw"></i><span>City pulse</span></a></nav>
      <div class="app-sidebar-spacer"></div><div class="app-sidebar-user"><span class="app-avatar"><?= $e(strtoupper(substr((string) ($viewer['name'] ?? 'A'), 0, 1))) ?></span><div><strong><?= $e($viewer['name'] ?? 'Administrator') ?></strong><small>Governance analytics</small></div></div><a class="nav-link text-danger mt-2" href="<?= $e(civicRoute('logout')) ?>"><i class="fas fa-arrow-right-from-bracket fa-fw"></i><span>Sign out</span></a>
    </aside>
    <div class="app-sidebar-backdrop" data-sidebar-backdrop="adminAnalyticsSidebar"></div>
    <main class="app-content"><header class="app-topbar"><div class="d-flex align-items-center gap-3"><button class="app-menu-toggle" data-sidebar-toggle="adminAnalyticsSidebar" aria-controls="adminAnalyticsSidebar" aria-expanded="true" aria-label="Toggle navigation"><i class="fas fa-bars"></i></button><div><div class="eyebrow">Data-driven governance</div><h1>Analytics</h1></div></div><div class="d-flex gap-2"><a class="btn btn-sm btn-outline-secondary" href="<?= $e(civicApi('stats/export.php')) ?>">Export 30 days</a><a class="btn btn-sm btn-outline-secondary" href="<?= $e(civicRoute('dashboard')) ?>">Dashboard</a></div></header>
      <div class="container-fluid px-3 px-lg-4 py-4">
        <section class="dashboard-hero mb-4"><div><span class="hero-kicker"><i class="fas fa-chart-line me-1"></i>Auditable city intelligence</span><h2>See where work slows down and repeats.</h2><p>Every metric below is derived from issue reports, status history, and assignment records already captured by CivicConnect.</p></div></section>
        <div id="analyticsError" class="alert alert-warning d-none"></div>
        <section class="row g-4 mb-4"><div class="col-12 col-xl-7"><div class="data-card h-100 p-3 p-lg-4"><div class="data-card-header px-0 pt-0"><div><span class="eyebrow">Lifecycle speed</span><h2>Average transition time</h2></div></div><canvas id="transitionChart" height="150" aria-label="Average issue lifecycle transition time"></canvas></div></div><div class="col-12 col-xl-5"><div class="data-card h-100 p-3 p-lg-4"><div class="data-card-header px-0 pt-0"><div><span class="eyebrow">Transparent prioritisation</span><h2>Priority formula</h2></div></div><p id="priorityFormula" class="small text-muted mb-0">Loading formula…</p><div class="alert alert-light border small mt-3 mb-0">Recurrences receive a 15% multiplier so repeated failures rise back into view.</div></div></div></section>
        <section class="row g-4"><div class="col-12 col-xl-6"><div class="data-card p-3 p-lg-4"><div class="data-card-header px-0 pt-0"><div><span class="eyebrow">Field operations</span><h2>Worker performance</h2></div></div><div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>Worker</th><th>Assigned</th><th>Completed</th><th>Avg hours</th></tr></thead><tbody id="workerRows"><tr><td colspan="4" class="text-muted">Loading…</td></tr></tbody></table></div></div></div><div class="col-12 col-xl-6"><div class="data-card p-3 p-lg-4"><div class="data-card-header px-0 pt-0"><div><span class="eyebrow">Geographic accountability</span><h2>Resolution rate by ward</h2></div></div><canvas id="wardChart" height="180" aria-label="Resolution rate by ward"></canvas></div></div></section>
      </div>
    </main>
  </div>
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
  <script>
    fetch(<?= json_encode(civicApi('stats/analytics.php')) ?>).then(response => response.json().then(result => ({response, result}))).then(({response, result}) => {
      if (!response.ok) throw new Error(result.message || 'Analytics could not be loaded.');
      const data = result.data || {};
      document.getElementById('priorityFormula').textContent = data.priority_formula || 'Priority formula unavailable.';
      const transitions = data.transitions || {};
      new Chart(document.getElementById('transitionChart'), {type: 'bar', data: {labels: ['Pending → acknowledged', 'Acknowledged → in progress', 'In progress → resolved'], datasets: [{label: 'Average hours', data: [transitions.pending_to_acknowledged?.average_hours || 0, transitions.acknowledged_to_in_progress?.average_hours || 0, transitions.in_progress_to_resolved?.average_hours || 0], backgroundColor: ['#6366f1', '#f59e0b', '#10b981'], borderRadius: 8}]}, options: {plugins: {legend: {display: false}}, scales: {y: {beginAtZero: true, title: {display: true, text: 'Hours'}}}}});
      const workers = data.workers || [];
      document.getElementById('workerRows').innerHTML = workers.length ? workers.map(worker => `<tr><td><strong>${escapeHtml(worker.name)}</strong><small class="d-block text-muted">${escapeHtml(worker.department || 'municipal')}</small></td><td>${worker.assigned_count}</td><td>${worker.completed_count}</td><td>${worker.average_completion_hours}</td></tr>`).join('') : '<tr><td colspan="4" class="text-muted">No worker assignments yet.</td></tr>';
      const wards = data.ward_resolution || [];
      new Chart(document.getElementById('wardChart'), {type: 'bar', data: {labels: wards.map(row => 'Ward ' + row.ward), datasets: [{label: 'Resolution rate %', data: wards.map(row => row.resolution_rate), backgroundColor: '#0ea5e9', borderRadius: 8}]}, options: {indexAxis: 'y', plugins: {legend: {display: false}}, scales: {x: {beginAtZero: true, max: 100}}}});
    }).catch(error => { const box = document.getElementById('analyticsError'); box.textContent = error.message; box.classList.remove('d-none'); });
    function escapeHtml(value) { return String(value ?? '').replace(/[&<>\'"]/g, character => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[character])); }
  </script>
  <?php require_once CIVICCONNECT_ROOT . '/components/footer.php'; ?>
</body>
</html>
