<?php
declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';
$bootstrapData = bootstrapAccounts();
extract($bootstrapData);

$user = sessionUser();
$requestedSort = strtolower((string) ($_GET['sort'] ?? 'hot'));
$sort = in_array($requestedSort, ['hot', 'new', 'top', 'rising'], true) ? $requestedSort : 'hot';
$category = strtolower(trim((string) ($_GET['category'] ?? '')));
$status = strtolower(trim((string) ($_GET['status'] ?? '')));
$query = substr(trim((string) ($_GET['q'] ?? '')), 0, 100);
$feed = ['items' => [], 'pagination' => ['total' => 0, 'pages' => 0]];
$cityStats = ['total' => 0, 'open' => 0, 'in_progress' => 0, 'resolved' => 0];

if ($db instanceof PDO) {
  try {
    $feed = fetchIssueFeed($db, [
      'page' => max(1, (int) ($_GET['page'] ?? 1)),
      'limit' => 20,
      'sort' => $sort,
      'category' => $category,
      'status' => $status,
      'query' => $query,
      'viewer_id' => $user['id'] ?? 0,
    ]);
    $cityStats = getIssueStats($db);
  } catch (Throwable $exception) {
    error_log('Public issue feed query failed: ' . $exception->getMessage());
  }
}

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$csrf = csrfToken();
$queryBase = array_filter(['q' => $query, 'category' => $category, 'status' => $status], static fn ($value): bool => $value !== '');
$sortUrl = static function (string $value) use ($queryBase): string {
  return '?' . http_build_query(array_merge($queryBase, ['sort' => $value]));
};

require_once __DIR__ . '/../../components/header.php';
?>

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIINfQ3R7fX4W8m2dP5k2t4x5j7P2m5s1M=" crossorigin="">
<style>
  body { background: #f5f7fb; }
  .feed-shell { min-height: 100vh; }
  .feed-nav { background: #fff; border-bottom: 1px solid #e7ebf0; }
  .feed-post, .side-card { border: 0; box-shadow: 0 .35rem 1.2rem rgba(15, 23, 42, .07); }
  .feed-post:hover { box-shadow: 0 .7rem 1.5rem rgba(15, 23, 42, .12); }
  .post-image { width: 100%; height: 230px; object-fit: cover; background: #edf1f5; }
  .sort-link.active { color: #fff !important; background: #0d6efd; }
  .vote-button.voted { color: #0d6efd; border-color: #0d6efd; background: #e8f1ff; }
  .sticky-sidebar { position: sticky; top: 1rem; }
  #heatmap { height: 290px; border-radius: .75rem; overflow: hidden; }
  .legend-dot { width: 11px; height: 11px; display: inline-block; border-radius: 50%; }
  .empty-state { border: 2px dashed #d9e0e8; }
  .detail-gallery { display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: .75rem; }
  .detail-gallery img { width: 100%; height: 130px; object-fit: cover; border-radius: .5rem; }
  @media (max-width: 991.98px) { .sticky-sidebar { position: static; } }
</style>

<body class="feed-shell">
  <nav class="feed-nav navbar navbar-expand-lg sticky-top">
    <div class="container-fluid px-3 px-lg-4">
      <a class="navbar-brand d-flex align-items-center gap-2 fw-bold" href="citizen-dashboard.php"><i class="fas fa-city text-primary"></i>CivicConnect</a>
      <div class="d-flex align-items-center gap-2">
        <?php if ($user): ?>
          <a href="../report/" class="btn btn-primary btn-sm"><i class="fas fa-plus me-1"></i>Report issue</a>
          <a href="citizen-dashboard.php" class="btn btn-outline-secondary btn-sm">Dashboard</a>
          <a href="../logout/" class="btn btn-link btn-sm text-danger text-decoration-none">Logout</a>
        <?php else: ?>
          <a href="../login/" class="btn btn-primary btn-sm">Login</a>
        <?php endif; ?>
      </div>
    </div>
  </nav>

  <main class="container-fluid px-3 px-lg-5 py-4">
    <div class="row g-4">
      <section class="col-12 col-lg-8">
        <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-3">
          <div><div class="text-primary small fw-semibold text-uppercase">City issue board</div><h1 class="h3 fw-bold mb-1">Community feed</h1><p class="text-muted mb-0">Similar reports are grouped into one post with all submitted photos.</p></div>
          <span class="text-muted small"><?= (int) ($feed['pagination']['total'] ?? 0) ?> public issues</span>
        </div>

        <form method="GET" class="card side-card mb-3">
          <div class="card-body row g-2">
            <div class="col-12 col-md-5"><label class="visually-hidden" for="feedSearch">Search</label><input id="feedSearch" name="q" value="<?= $e($query) ?>" class="form-control" placeholder="Search issue, address or description"></div>
            <div class="col-6 col-md-3"><label class="visually-hidden" for="feedCategory">Category</label><select id="feedCategory" name="category" class="form-select"><option value="">All categories</option><?php foreach (['pothole','garbage','streetlight','waterlogging','road_damage','encroachment','graffiti','open_drain','fallen_tree','other'] as $option): ?><option value="<?= $e($option) ?>" <?= $category === $option ? 'selected' : '' ?>><?= $e(issueCategoryLabel($option)) ?></option><?php endforeach; ?></select></div>
            <div class="col-6 col-md-2"><label class="visually-hidden" for="feedStatus">Status</label><select id="feedStatus" name="status" class="form-select"><option value="">All status</option><?php foreach (['pending','acknowledged','in_progress','resolved'] as $option): ?><option value="<?= $e($option) ?>" <?= $status === $option ? 'selected' : '' ?>><?= $e(issueStatusLabel($option)) ?></option><?php endforeach; ?></select></div>
            <div class="col-12 col-md-2"><button class="btn btn-primary w-100" type="submit"><i class="fas fa-search me-1"></i>Filter</button></div>
          </div>
        </form>

        <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
          <span class="small text-muted me-1">Sort:</span>
          <?php foreach (['hot' => 'Hot', 'new' => 'New', 'top' => 'Top', 'rising' => 'Rising'] as $key => $label): ?><a class="btn btn-sm sort-link <?= $sort === $key ? 'active' : 'btn-outline-secondary' ?>" href="<?= $e($sortUrl($key)) ?>"><?= $e($label) ?></a><?php endforeach; ?>
        </div>

        <?php if ($feed['items'] === []): ?>
          <div class="card feed-post empty-state text-center py-5"><div class="card-body"><i class="fas fa-city fa-2x text-muted mb-3"></i><h2 class="h5 fw-bold">No issues match these filters</h2><p class="text-muted mb-3">Be the first person to report a problem in this area.</p><a href="../report/" class="btn btn-primary">Report an issue</a></div></div>
        <?php else: ?>
          <?php foreach ($feed['items'] as $issue): ?>
            <?php $statusClass = issueStatusClass($issue['status']); ?>
            <article class="card feed-post mb-3" id="issue-<?= (int) $issue['id'] ?>" data-issue-id="<?= (int) $issue['id'] ?>">
              <?php if ($issue['cover_url']): ?><img class="post-image rounded-top" src="<?= $e($issue['cover_url']) ?>" alt="<?= $e(issueCategoryLabel($issue['category'])) ?> report photo" loading="lazy"><?php endif; ?>
              <div class="card-body p-3 p-md-4">
                <div class="d-flex justify-content-between align-items-start gap-3 mb-2"><div class="small text-muted"><i class="fas fa-users me-1"></i><?= (int) $issue['report_count'] ?> citizen report<?= (int) $issue['report_count'] === 1 ? '' : 's' ?> · <?= $e($issue['reporter_name'] ?: 'CivicConnect community') ?></div><span class="badge text-bg-<?= $e($statusClass) ?>"><?= $e(issueStatusLabel($issue['status'])) ?></span></div>
                <h2 class="h5 fw-bold mb-2"><?= $e($issue['title'] ?: issueCategoryLabel($issue['category'])) ?></h2>
                <p class="small text-muted mb-3"><i class="fas fa-map-marker-alt text-primary me-1"></i><?= $e($issue['address'] ?: 'Location recorded from GPS') ?> <span class="mx-1">·</span> <?= $e(date('M d, Y', strtotime((string) $issue['created_at']))) ?></p>
                <?php if (!empty($issue['description'])): ?><p class="mb-3 text-body-secondary"><?= nl2br($e($issue['description'])) ?></p><?php endif; ?>
                <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 border-top pt-3">
                  <div class="d-flex flex-wrap gap-2 small text-muted"><span class="badge bg-light text-dark border"><i class="fas fa-tag me-1"></i><?= $e(issueCategoryLabel($issue['category'])) ?></span><span class="badge bg-light text-dark border"><i class="fas fa-triangle-exclamation me-1"></i>Severity <?= (int) $issue['severity'] ?>/5</span><?php if ((int) $issue['image_count'] > 0): ?><span class="badge bg-light text-dark border"><i class="fas fa-images me-1"></i><?= (int) $issue['image_count'] ?> photos</span><?php endif; ?></div>
                  <div class="d-flex gap-2"><button type="button" class="btn btn-sm btn-outline-primary vote-button <?= $issue['viewer_upvoted'] ? 'voted' : '' ?>" data-upvote-id="<?= (int) $issue['id'] ?>" aria-pressed="<?= $issue['viewer_upvoted'] ? 'true' : 'false' ?>"><i class="fas fa-arrow-up me-1"></i><span class="vote-count"><?= formatNumber((int) $issue['upvote_count']) ?></span></button><button type="button" class="btn btn-sm btn-outline-secondary detail-button" data-detail-id="<?= (int) $issue['id'] ?>"><i class="fas fa-images me-1"></i>View post</button></div>
                </div>
              </div>
            </article>
          <?php endforeach; ?>
        <?php endif; ?>

        <?php if (($feed['pagination']['pages'] ?? 0) > 1): ?>
          <nav aria-label="Feed pages"><ul class="pagination justify-content-center"><li class="page-item <?= (int) $feed['pagination']['page'] <= 1 ? 'disabled' : '' ?>"><a class="page-link" href="?<?= $e(http_build_query(array_merge($queryBase, ['sort' => $sort, 'page' => max(1, (int) $feed['pagination']['page'] - 1)]))) ?>">Previous</a></li><li class="page-item disabled"><span class="page-link">Page <?= (int) $feed['pagination']['page'] ?> of <?= (int) $feed['pagination']['pages'] ?></span></li><li class="page-item <?= (int) $feed['pagination']['page'] >= (int) $feed['pagination']['pages'] ? 'disabled' : '' ?>"><a class="page-link" href="?<?= $e(http_build_query(array_merge($queryBase, ['sort' => $sort, 'page' => (int) $feed['pagination']['page'] + 1]))) ?>">Next</a></li></ul></nav>
        <?php endif; ?>
      </section>

      <aside class="col-12 col-lg-4">
        <div class="sticky-sidebar">
          <div class="card side-card mb-4"><div class="card-body p-4"><div class="d-flex justify-content-between align-items-center mb-3"><h2 class="h5 fw-bold mb-0">Issue dashboard</h2><a href="../../api/stats/city.php" class="small text-decoration-none">JSON</a></div><div class="row g-2 text-center mb-3"><div class="col-4"><div class="h4 fw-bold text-danger mb-0"><?= (int) $cityStats['open'] ?></div><div class="small text-muted">Open</div></div><div class="col-4"><div class="h4 fw-bold text-warning mb-0"><?= (int) $cityStats['in_progress'] ?></div><div class="small text-muted">Working</div></div><div class="col-4"><div class="h4 fw-bold text-success mb-0"><?= (int) $cityStats['resolved'] ?></div><div class="small text-muted">Resolved</div></div></div><div id="heatmap" aria-label="Live issue heatmap"></div><div class="d-flex justify-content-center gap-3 small text-muted mt-3"><span><i class="legend-dot bg-danger me-1"></i>High</span><span><i class="legend-dot bg-warning me-1"></i>Medium</span><span><i class="legend-dot bg-success me-1"></i>Low</span></div></div></div>
          <div class="card side-card mb-4"><div class="card-body p-4"><h2 class="h6 fw-bold mb-3">How grouping works</h2><p class="small text-muted mb-0">Reports with the same AI category in a nearby geohash are grouped into one issue. New photos and citizen reports increase the post's evidence and priority.</p></div></div>
          <?php if (!$user): ?><div class="card side-card"><div class="card-body p-4"><h2 class="h6 fw-bold">Have you spotted a problem?</h2><p class="small text-muted">Sign in to submit a photo, upvote a report, and track your contribution.</p><a href="../login/" class="btn btn-primary btn-sm">Sign in</a></div></div><?php endif; ?>
        </div>
      </aside>
    </div>
  </main>

  <div class="modal fade" id="issueDetailModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h2 class="modal-title h5" id="detailTitle">Issue details</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body" id="detailBody"><div class="text-center py-5"><div class="spinner-border text-primary" role="status"></div></div></div></div></div></div>

  <?php require_once __DIR__ . '/../../components/footer.php'; ?>
  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
  <script>
    const civicCsrfToken = <?= json_encode($csrf, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const civicLoggedIn = <?= $user ? 'true' : 'false' ?>;
    const issueModal = new bootstrap.Modal(document.getElementById('issueDetailModal'));

    function formatCount(value) {
      return new Intl.NumberFormat().format(Number(value) || 0);
    }

    async function toggleUpvote(button) {
      if (!civicLoggedIn) {
        window.location.href = '../login/';
        return;
      }
      button.disabled = true;
      const issueId = button.dataset.upvoteId;
      try {
        const response = await fetch('../../api/issues/upvote.php', {
          method: 'POST',
          headers: {'Content-Type': 'application/json', 'X-CSRF-Token': civicCsrfToken},
          body: JSON.stringify({issue_id: Number(issueId)})
        });
        const result = await response.json();
        if (!response.ok) throw new Error(result.message || 'Unable to update vote.');
        button.classList.toggle('voted', Boolean(result.data?.upvoted));
        button.setAttribute('aria-pressed', result.data?.upvoted ? 'true' : 'false');
        button.querySelector('.vote-count').textContent = formatCount(result.data?.upvote_count);
      } catch (error) {
        window.alert(error.message || 'Unable to update vote.');
      } finally {
        button.disabled = false;
      }
    }

    async function showIssueDetail(issueId) {
      const body = document.getElementById('detailBody');
      body.innerHTML = '<div class="text-center py-5"><div class="spinner-border text-primary" role="status"></div></div>';
      issueModal.show();
      try {
        const response = await fetch('../../api/issues/detail.php?id=' + encodeURIComponent(issueId));
        const result = await response.json();
        if (!response.ok) throw new Error(result.message || 'Issue could not be loaded.');
        const issue = result.data.issue;
        document.getElementById('detailTitle').textContent = issue.title || 'Issue details';
        const images = (issue.images || []).map(image => `<img src="${escapeHtml(image.url)}" alt="${escapeHtml(image.original_name || 'Issue photo')}" loading="lazy">`).join('');
        body.innerHTML = `<div class="small text-muted mb-3"><i class="fas fa-map-marker-alt me-1"></i>${escapeHtml(issue.address || 'Location recorded')} · ${escapeHtml(issue.status || 'pending')}</div>${issue.description ? `<p>${escapeHtml(issue.description)}</p>` : ''}<div class="detail-gallery">${images || '<p class="text-muted small">No photos attached.</p>'}</div><div class="small text-muted mt-3">${formatCount(issue.report_count)} citizen reports · ${formatCount(issue.upvote_count)} upvotes</div>`;
      } catch (error) {
        body.innerHTML = `<p class="text-danger mb-0">${escapeHtml(error.message || 'Issue could not be loaded.')}</p>`;
      }
    }

    function escapeHtml(value) {
      return String(value ?? '').replace(/[&<>'"]/g, character => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[character]));
    }

    document.querySelectorAll('[data-upvote-id]').forEach(button => button.addEventListener('click', () => toggleUpvote(button)));
    document.querySelectorAll('[data-detail-id]').forEach(button => button.addEventListener('click', () => showIssueDetail(button.dataset.detailId)));

    const map = L.map('heatmap', {scrollWheelZoom: false}).setView([13.0827, 80.2707], 11);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {maxZoom: 19, attribution: '&copy; OpenStreetMap contributors'}).addTo(map);
    fetch('../../api/stats/heatmap.php').then(response => response.json()).then(result => {
      const points = result.data?.points || [];
      const bounds = [];
      points.forEach(point => {
        const color = point.band === 'red' ? '#dc3545' : (point.band === 'yellow' ? '#ffc107' : '#198754');
        const marker = L.circleMarker([Number(point.lat), Number(point.lng)], {radius: Math.min(22, 7 + Number(point.count)), color, fillColor: color, fillOpacity: .45, weight: 2}).addTo(map);
        marker.bindPopup(`<strong>${formatCount(point.count)} reports</strong><br>${escapeHtml(point.band)} concentration`);
        bounds.push([Number(point.lat), Number(point.lng)]);
      });
      if (bounds.length > 0) map.fitBounds(bounds, {padding: [20, 20], maxZoom: 15});
    }).catch(() => {});

    const requestedIssue = <?= json_encode((int) ($_GET['issue'] ?? 0)) ?>;
    if (requestedIssue > 0) showIssueDetail(requestedIssue);
  </script>
</body>
</html>
