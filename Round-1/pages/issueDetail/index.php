<?php
declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';
$bootstrapData = bootstrapAccounts();
extract($bootstrapData);

$viewer = sessionUser();
$issueId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$issueEndpoints = issueDetailPageEndpoints($urlForApi);
$detailEndpoint = $issueId ? $issueEndpoints['detail'] . '?id=' . (int) $issueId : '';
?>
<?php // Main HTML ?>
<?php require_once CIVICCONNECT_ROOT . '/components/header.php'; ?>

<body class="app-body">
  <main class="container py-4 py-lg-5">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
      <a href="<?= $e($urlForPublicFeed) ?>" class="text-decoration-none fw-bold"><i class="fas fa-arrow-left me-2"></i>Back to community feed</a>
      <div class="d-flex gap-2"><a class="btn btn-sm btn-outline-secondary" href="<?= $e($urlForHeatmap) ?>"><i class="fas fa-map-location-dot me-1"></i>City pulse</a><?php if ($viewer): ?><a class="btn btn-sm btn-outline-secondary" href="<?= $e($urlForDashboard) ?>">Dashboard</a><?php else: ?><a class="btn btn-sm btn-primary" href="<?= $e($urlForLogin) ?>">Sign in</a><?php endif; ?></div>
    </div>

    <?php if (!$issueId): ?>
      <section class="data-card p-4"><div class="empty-state"><i class="fas fa-link-slash"></i><h1 class="h3">Issue link is invalid</h1><p>Return to the community feed and choose a report to inspect.</p><a class="btn btn-primary" href="<?= $e($urlForPublicFeed) ?>">Open community feed</a></div></section>
    <?php else: ?>
      <section id="issueDetailCard" class="data-card p-4 p-lg-5" aria-live="polite">
        <div id="issueLoading" class="text-center py-5"><div class="spinner-border text-primary" role="status"></div><p class="text-muted mt-3 mb-0">Loading issue details…</p></div>
        <div id="issueError" class="d-none alert alert-danger mb-0"></div>
        <div id="issueContent" class="d-none">
          <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-4"><div><span id="issueCategory" class="pill pill-category"></span><h1 id="issueTitle" class="h2 mt-2 mb-2"></h1><p id="issueLocation" class="text-muted mb-0"><i class="fas fa-location-dot me-1"></i></p></div><button id="upvoteButton" type="button" class="btn btn-outline-primary"><i class="fas fa-arrow-up me-1"></i><span class="vote-count">0</span> Upvote</button></div>
          <div class="row g-4"><div class="col-12 col-lg-7"><div id="issueImages" class="row g-3 mb-3"></div><p id="issueDescription" class="text-body-secondary"></p><div class="row g-3"><div class="col-6 col-md-3"><div class="metric-card metric-primary"><span class="metric-label">Priority</span><strong id="issuePriority">—</strong><small id="issuePriorityBand"></small></div></div><div class="col-6 col-md-3"><div class="metric-card metric-success"><span class="metric-label">Reports</span><strong id="issueReports">0</strong><small>citizen reports</small></div></div><div class="col-6 col-md-3"><div class="metric-card metric-warning"><span class="metric-label">Nearby</span><strong id="issueNearby">0</strong><small>similar nearby</small></div></div><div class="col-6 col-md-3"><div class="metric-card metric-secondary"><span class="metric-label">Status</span><strong id="issueStatus">—</strong><small>latest update</small></div></div></div><div id="communitySignals" class="alert alert-light border mt-4 mb-0"></div></div><div class="col-12 col-lg-5"><section class="border rounded-4 p-3 mb-3"><div class="d-flex justify-content-between align-items-center mb-3"><h2 class="h5 mb-0">Resolution workflow</h2><span id="assignmentState" class="badge text-bg-light">Loading</span></div><div id="assignmentContent" class="small text-muted">Loading assignment status…</div></section><section class="border rounded-4 p-3"><h2 class="h5 mb-3">Status history</h2><div id="statusHistory" class="small text-muted">No updates yet.</div></section></div></div>
        </div>
      </section>
    <?php endif; ?>
  </main>
  <?php // Bottom scripts ?>
  <script>
    const detailEndpoint = <?= json_encode($detailEndpoint, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const upvoteEndpoint = <?= json_encode($issueEndpoints['upvote'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const civicCsrfToken = <?= json_encode(csrfToken(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const civicLoggedIn = <?= $viewer ? 'true' : 'false' ?>;
    const loginUrl = <?= json_encode($urlForLogin) ?>;
    function escapeHtml(value) { return String(value ?? '').replace(/[&<>\'\"]/g, character => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','\"':'&quot;'}[character])); }
    function formatCount(value) { return new Intl.NumberFormat().format(Number(value) || 0); }
    function statusClass(status) { return status === 'resolved' ? 'success' : (status === 'in_progress' || status === 'acknowledged' ? 'warning' : 'danger'); }
    function renderIssue(issue) {
      document.getElementById('issueCategory').textContent = String(issue.category || 'issue').replace(/_/g, ' ');
      document.getElementById('issueTitle').textContent = issue.title || 'Civic issue';
      document.getElementById('issueLocation').innerHTML = `<i class="fas fa-location-dot me-1"></i>${escapeHtml(issue.address || 'Location recorded')}`;
      document.getElementById('issueDescription').textContent = issue.description || 'No additional description was provided.';
      document.getElementById('issuePriority').textContent = Math.round(Number(issue.priority_score) || 0);
      document.getElementById('issuePriorityBand').textContent = `${escapeHtml(issue.priority_band || 'low')} priority`;
      document.getElementById('issueReports').textContent = formatCount(issue.report_count);
      document.getElementById('issueNearby').textContent = formatCount(issue.nearby_similar_reports);
      document.getElementById('issueStatus').textContent = String(issue.status || 'pending').replace(/_/g, ' ');
      document.getElementById('communitySignals').innerHTML = `<strong>Community signal:</strong> ${formatCount(issue.nearby_similar_reports)} similar reports nearby, ${formatCount(issue.city_similar_reports)} similar reports across the city, and ${formatCount(issue.nearby_different_reports)} different issues nearby.`;
      const voteButton = document.getElementById('upvoteButton');
      voteButton.querySelector('.vote-count').textContent = formatCount(issue.upvote_count);
      voteButton.classList.toggle('active', Boolean(issue.viewer_upvoted));
      voteButton.setAttribute('aria-pressed', issue.viewer_upvoted ? 'true' : 'false');
      const images = (issue.images || []).map(image => `<div class="col-12 col-sm-6"><img src="${escapeHtml(image.url)}" alt="${escapeHtml(image.original_name || 'Issue photo')}" class="img-fluid rounded-4 w-100" loading="lazy"></div>`).join('');
      document.getElementById('issueImages').innerHTML = images || '<div class="col-12"><div class="bg-light rounded-4 p-4 text-muted">No photos attached.</div></div>';
      const assignment = issue.assignment;
      document.getElementById('assignmentState').textContent = assignment ? (assignment.completed_at ? 'Completed' : 'Assigned') : 'Unassigned';
      document.getElementById('assignmentState').className = `badge text-bg-${assignment ? (assignment.completed_at ? 'success' : 'warning') : 'light'}`;
      document.getElementById('assignmentContent').innerHTML = assignment
        ? `<p class="mb-2"><i class="fas fa-helmet-safety me-1"></i><strong>${escapeHtml(assignment.worker_name || 'Field worker')}</strong>${assignment.assigned_by_name ? ` · assigned by ${escapeHtml(assignment.assigned_by_name)}` : ''}</p><p class="mb-0">${assignment.completed_at ? 'Work marked completed.' : 'Work is currently in progress.'}${assignment.citizen_verified_at ? ' Citizen verified.' : ''}</p>`
        : 'Waiting for an administrator to assign this issue.';
      const history = (issue.status_history || []).map(item => `<div class="border-start border-3 border-primary ps-3 mb-3"><strong>${escapeHtml(String(item.new_status || '').replace(/_/g, ' '))}</strong><div>${escapeHtml(item.note || 'Status updated')}</div><small class="text-muted">${escapeHtml(item.changed_by_name || 'CivicConnect')} · ${escapeHtml(item.created_at || '')}</small></div>`).join('');
      document.getElementById('statusHistory').innerHTML = history || 'No status updates yet.';
    }
    async function loadIssue() {
      try {
        const response = await fetch(detailEndpoint);
        const result = await response.json();
        if (!response.ok) throw new Error(result.message || 'Issue details could not be loaded.');
        renderIssue(result.data.issue);
        document.getElementById('issueLoading').classList.add('d-none');
        document.getElementById('issueContent').classList.remove('d-none');
      } catch (error) {
        document.getElementById('issueLoading').classList.add('d-none');
        const box = document.getElementById('issueError');
        box.textContent = error.message || 'Issue details could not be loaded.';
        box.classList.remove('d-none');
      }
    }
    document.getElementById('upvoteButton')?.addEventListener('click', async () => {
      if (!civicLoggedIn) { window.location.href = loginUrl; return; }
      const button = document.getElementById('upvoteButton');
      button.disabled = true;
      try {
        const issueId = new URL(detailEndpoint, window.location.origin).searchParams.get('id');
        const response = await fetch(upvoteEndpoint, {method: 'POST', headers: {'Content-Type': 'application/json', 'X-CSRF-Token': civicCsrfToken}, body: JSON.stringify({issue_id: Number(issueId)})});
        const result = await response.json();
        if (!response.ok) throw new Error(result.message || 'Unable to update the vote.');
        button.querySelector('.vote-count').textContent = formatCount(result.data?.upvote_count);
        button.classList.toggle('active', Boolean(result.data?.upvoted));
      } catch (error) { window.alert(error.message || 'Unable to update the vote.'); } finally { button.disabled = false; }
    });
    if (detailEndpoint) loadIssue();
  </script>
</body>
</html>
