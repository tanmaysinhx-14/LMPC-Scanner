<?php
declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';
$bootstrapData = bootstrapAccounts();
extract($bootstrapData);

$viewer = sessionUser();
$viewerRole = (string) ($viewer['role'] ?? '');
$dashboardPath = $viewerRole === 'admin'
  ? '../admin/admin-dashboard.php'
  : ($viewerRole === 'worker' ? '../worker/assignments.php' : 'citizen-dashboard.php');
$requestedSort = strtolower((string) ($_GET['sort'] ?? 'hot'));
$sort = in_array($requestedSort, ['hot', 'new', 'top', 'rising'], true) ? $requestedSort : 'hot';
$category = strtolower(trim((string) ($_GET['category'] ?? '')));
$status = strtolower(trim((string) ($_GET['status'] ?? '')));
$query = substr(trim((string) ($_GET['q'] ?? '')), 0, 100);
$page = max(1, (int) ($_GET['page'] ?? 1));
$feed = ['items' => [], 'pagination' => ['page' => $page, 'limit' => 20, 'total' => 0, 'pages' => 0]];
$cityStats = ['total' => 0, 'open' => 0, 'in_progress' => 0, 'resolved' => 0];

if ($db instanceof PDO) {
  try {
    $feed = fetchIssueFeed($db, [
      'page' => $page,
      'limit' => 20,
      'sort' => $sort,
      'category' => $category,
      'status' => $status,
      'query' => $query,
      'viewer_id' => $viewer['id'] ?? 0,
    ]);
    $cityStats = getIssueStats($db);
  } catch (Throwable $exception) {
    error_log('Public feed render failed: ' . $exception->getMessage());
  }
}

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$feedUrl = static function (array $overrides = []) use ($sort, $category, $status, $query): string {
  $params = array_filter(array_merge(['sort' => $sort, 'category' => $category, 'status' => $status, 'q' => $query], $overrides), static fn (mixed $value): bool => $value !== '' && $value !== null);
  return '?' . http_build_query($params);
};
$uiStatus = static fn (?string $value): string => $value === 'pending' || $value === 'acknowledged' ? 'open' : (string) $value;
?>
<!doctype html>
<html lang="en" data-theme="light">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="description" content="CivicConnect community issue feed">
  <title>CivicConnect · Community Feed</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link rel="stylesheet" href="https://unpkg.com/maplibre-gl@4.7.1/dist/maplibre-gl.css">
  <link rel="stylesheet" href="../../assets/css/civic-ui.css">
  <style>
    :root { --feed-bg: #f6f7f9; --feed-line: #e5e8ec; --feed-muted: #72777c; --feed-purple: #4f46e5; }
    body { background: var(--feed-bg); color: #1a1a1b; font-family: Inter, ui-sans-serif, system-ui, sans-serif; }
    .feed-navbar { position: sticky; top: 0; z-index: 1030; padding: .72rem 0; border-bottom: 1px solid var(--feed-line); background: rgba(255,255,255,.92); box-shadow: 0 2px 14px rgba(0,0,0,.04); backdrop-filter: blur(18px); }
    .feed-brand { display: inline-flex; align-items: center; gap: .6rem; color: #1a1a1b; font-size: 1.16rem; font-weight: 800; letter-spacing: -.04em; text-decoration: none; }
    .feed-brand-icon { display: inline-flex; width: 2.2rem; height: 2.2rem; align-items: center; justify-content: center; border-radius: .7rem; color: #fff; background: linear-gradient(135deg,#4f46e5,#7c3aed); }
    .feed-layout { display: grid; grid-template-columns: minmax(0, 1fr) 360px; gap: 1.6rem; max-width: 1360px; margin: 0 auto; padding: 1.6rem 1.2rem 3rem; }
    .feed-column { min-width: 0; }
    .feed-heading { display: flex; align-items: end; justify-content: space-between; gap: 1rem; margin-bottom: 1rem; }
    .feed-heading h1 { margin: 0; font-size: clamp(1.35rem, 2vw, 1.7rem); font-weight: 800; letter-spacing: -.04em; }
    .feed-heading p { margin: .25rem 0 0; color: var(--feed-muted); font-size: .83rem; }
    .feed-filter-panel { display: grid; grid-template-columns: minmax(0,1.4fr) repeat(2,minmax(130px,.7fr)) minmax(120px,.6fr) auto; gap: .5rem; padding: .7rem; margin-bottom: .75rem; border: 1px solid var(--feed-line); border-radius: 1rem; background: #fff; box-shadow: 0 3px 12px rgba(0,0,0,.025); }
    .feed-filter-panel input, .feed-filter-panel select { min-width: 0; min-height: 2.65rem; padding: .5rem .7rem; border: 1px solid #dfe3e7; border-radius: .65rem; color: #4d5358; background: #fff; font-size: .78rem; }
    .feed-filter-panel input:focus, .feed-filter-panel select:focus { outline: 0; border-color: #8b86ed; box-shadow: 0 0 0 .18rem rgba(79,70,229,.12); }
    .feed-filter-panel button { min-height: 2.65rem; padding: .5rem .85rem; border: 0; border-radius: .65rem; color: #fff; background: var(--feed-purple); font-size: .78rem; font-weight: 700; }
    .feed-filter-panel button:hover { background: #3730a3; }
    .sort-bar { display: flex; flex-wrap: wrap; align-items: center; gap: .4rem; padding: .35rem 0 .9rem; }
    .sort-btn { display: inline-flex; align-items: center; padding: .38rem .78rem; border: 1px solid var(--feed-line); border-radius: 999px; color: var(--feed-muted); background: #fff; font-size: .77rem; font-weight: 650; text-decoration: none; }
    .sort-btn:hover { color: var(--feed-purple); border-color: #c9c7fb; background: #f5f4ff; }
    .sort-btn.active { color: #fff; border-color: var(--feed-purple); background: var(--feed-purple); box-shadow: 0 6px 14px rgba(79,70,229,.18); }
    .feed-post { margin-bottom: .85rem; padding: 1.1rem 1.25rem; border: 1px solid var(--feed-line); border-radius: 1rem; background: #fff; transition: border-color .2s ease, box-shadow .2s ease, transform .2s ease; }
    .feed-post:hover { border-color: #d2d4de; box-shadow: 0 10px 28px rgba(16,24,40,.07); transform: translateY(-1px); }
    .post-header { display: flex; flex-wrap: wrap; gap: .4rem; margin-bottom: .3rem; color: #4d5258; font-size: .75rem; font-weight: 650; }
    .post-header span span { color: var(--feed-muted); font-weight: 500; }
    .post-title { display: block; margin: .25rem 0 .45rem; color: #1a1a1b; font-size: 1.08rem; font-weight: 760; line-height: 1.38; letter-spacing: -.025em; text-decoration: none; }
    .post-title:hover { color: var(--feed-purple); }
    .post-meta { display: flex; flex-wrap: wrap; align-items: center; gap: .42rem .68rem; margin-bottom: .75rem; color: var(--feed-muted); font-size: .73rem; }
    .badge-location, .badge-type, .badge-status { display: inline-flex; align-items: center; padding: .22rem .55rem; border-radius: 999px; font-size: .68rem; font-weight: 700; }
    .badge-location { color: #4c5561; background: #f0f2f4; }
    .badge-type { color: #3e38b8; background: #eeedff; }
    .badge-assignment { color: #126b54; background: #e8f8f1; }
    .badge-status.open { color: #b42318; background: #fff0ee; }
    .badge-status.in_progress { color: #925b00; background: #fff7df; }
    .badge-status.resolved { color: #167044; background: #eaf8ef; }
    .post-image { display: block; width: 100%; max-height: 410px; margin: .3rem 0 .8rem; border-radius: .8rem; object-fit: cover; background: #edf0f2; }
    .post-actions { display: flex; flex-wrap: wrap; align-items: center; gap: .15rem; padding-top: .65rem; border-top: 1px solid #edf0f2; }
    .vote-btn, .action-btn { display: inline-flex; align-items: center; gap: .35rem; padding: .38rem .7rem; border: 0; border-radius: 999px; color: var(--feed-muted); background: transparent; font-size: .77rem; font-weight: 650; }
    .vote-btn:hover, .action-btn:hover { color: #20242a; background: #f1f2f4; }
    .vote-btn.voted-up { color: #e0441b; background: #fff0ed; }
    .vote-count { min-width: 1.4rem; color: #252a30; text-align: center; font-weight: 800; }
    .post-score { margin-left: auto; color: #69727e; font-size: .7rem; font-weight: 650; }
    .feed-empty { padding: 4rem 1rem; border: 1px dashed #cfd5dd; border-radius: 1rem; color: var(--feed-muted); background: #fff; text-align: center; }
    .feed-sidebar { position: sticky; top: 5.8rem; align-self: start; }
    .side-card { margin-bottom: 1rem; overflow: hidden; border: 1px solid var(--feed-line); border-radius: 1rem; background: #fff; box-shadow: 0 5px 18px rgba(16,24,40,.045); }
    .side-card-header { display: flex; align-items: center; gap: .55rem; padding: 1rem 1.1rem; border-bottom: 1px solid var(--feed-line); font-size: .9rem; font-weight: 750; }
    .side-card-body { padding: 1rem 1.1rem; }
    .side-dots { display: inline-flex; gap: .22rem; }
    .side-dots span { width: .55rem; height: .55rem; border-radius: 50%; }
    .stat-grid { display: grid; grid-template-columns: repeat(3,1fr); gap: .4rem; margin-bottom: 1rem; text-align: center; }
    .stat-number { font-size: 1.45rem; font-weight: 800; letter-spacing: -.05em; }
    .stat-label { color: var(--feed-muted); font-size: .68rem; }
    .stat-open { color: #dc3545; } .stat-progress { color: #d48b00; } .stat-resolved { color: #18854c; }
    #issueMap { width: 100%; height: 310px; overflow: hidden; border-radius: .8rem; background: #101a24; }
    #issueMap .maplibregl-ctrl-attrib { font-size: .55rem; }
    #issueMap .maplibregl-ctrl-group { border: 0; overflow: hidden; box-shadow: 0 3px 12px rgba(0,0,0,.24); }
    #issueMap .maplibregl-ctrl-group button { width: 28px; height: 28px; }
    .map-preview-footer { display: flex; align-items: center; justify-content: space-between; gap: .75rem; margin-top: .7rem; }
    .map-preview-link { color: var(--feed-purple); font-size: .7rem; font-weight: 750; text-decoration: none; white-space: nowrap; }
    .map-preview-link:hover { color: #3730a3; }
    .map-legend { display: flex; flex-wrap: wrap; justify-content: center; gap: .75rem; margin-top: .75rem; color: var(--feed-muted); font-size: .68rem; }
    .map-legend span { display: inline-flex; align-items: center; gap: .25rem; }
    .map-dot { width: .58rem; height: .58rem; border-radius: 50%; }
    .map-high { background: #e53935; } .map-medium { background: #f3a800; } .map-low { background: #22a05a; }
    .heatmap-count { display: inline-flex; width: 2rem; height: 1.4rem; align-items: center; justify-content: center; border: 2px solid rgba(255,255,255,.95); border-radius: 999px; color: #fff; background: #1a1a1b; box-shadow: 0 3px 10px rgba(0,0,0,.24); font: 700 .66rem/1 Inter, sans-serif; }
    .detail-gallery { display: grid; grid-template-columns: repeat(auto-fit,minmax(130px,1fr)); gap: .7rem; }
    .detail-gallery img { width: 100%; height: 140px; border-radius: .65rem; object-fit: cover; }
    @media (max-width: 1050px) { .feed-layout { grid-template-columns: minmax(0,1fr) 310px; gap: 1rem; } .feed-filter-panel { grid-template-columns: 1fr 1fr; } .feed-filter-panel input { grid-column: 1 / -1; } }
    @media (max-width: 800px) { .feed-layout { display: block; padding: 1rem .75rem 2rem; } .feed-sidebar { position: static; margin-top: 1rem; } .feed-filter-panel { grid-template-columns: 1fr 1fr; } }
    @media (max-width: 520px) { .feed-navbar .container { padding: 0 .75rem; } .feed-navbar .nav-copy { display: none; } .feed-layout { padding-inline: .55rem; } .feed-filter-panel { grid-template-columns: 1fr; } .feed-post { padding: .9rem; } .post-score { width: 100%; margin-left: 0; padding-left: .7rem; } #issueMap { height: 260px; } }
  </style>
</head>
<body>
  <nav class="feed-navbar">
    <div class="container d-flex align-items-center justify-content-between gap-3">
      <a class="feed-brand" href="../../"><span class="feed-brand-icon"><i class="fas fa-city"></i></span><span class="nav-copy">CivicConnect</span></a>
      <div class="d-flex align-items-center gap-2">
        <a class="btn btn-sm btn-outline-secondary" href="../heatmap/"><i class="fas fa-map-location-dot me-1"></i><span class="d-none d-md-inline">City pulse</span></a>
        <?php if ($viewer): ?>
          <a class="btn btn-sm btn-outline-secondary" href="<?= $e($dashboardPath) ?>"><i class="fas fa-th-large me-1"></i><span class="d-none d-sm-inline">Dashboard</span></a>
          <?php if ($viewerRole === 'citizen'): ?><a class="btn btn-sm btn-primary" href="../report/"><i class="fas fa-plus me-1"></i>Report issue</a><?php endif; ?>
          <a class="btn btn-sm btn-link text-danger text-decoration-none d-none d-md-inline" href="../logout/">Logout</a>
        <?php else: ?>
          <a class="btn btn-sm btn-outline-secondary" href="../login/"><i class="fas fa-sign-in-alt me-1"></i>Sign in</a>
          <a class="btn btn-sm btn-primary" href="../register/">Get started</a>
        <?php endif; ?>
      </div>
    </div>
  </nav>

  <main class="feed-layout">
    <section class="feed-column">
      <div class="feed-heading"><div><h1>Community Feed</h1><p>Grouped posts turn nearby reports into one shared civic story.</p></div><span class="small text-muted"><?= (int) ($feed['pagination']['total'] ?? 0) ?> issues</span></div>
      <form class="feed-filter-panel" method="GET">
        <input type="search" name="q" value="<?= $e($query) ?>" placeholder="Search issues, places or descriptions" aria-label="Search issues">
        <select name="category" aria-label="Filter by category"><option value="">All categories</option><?php foreach (['pothole','garbage','streetlight','waterlogging','road_damage','encroachment','graffiti','open_drain','fallen_tree','other'] as $option): ?><option value="<?= $e($option) ?>" <?= $category === $option ? 'selected' : '' ?>><?= $e(issueCategoryLabel($option)) ?></option><?php endforeach; ?></select>
        <select name="status" aria-label="Filter by status"><option value="">All statuses</option><?php foreach (['pending','acknowledged','in_progress','resolved'] as $option): ?><option value="<?= $e($option) ?>" <?= $status === $option ? 'selected' : '' ?>><?= $e(issueStatusLabel($option)) ?></option><?php endforeach; ?></select>
        <select name="sort" aria-label="Sort issues" onchange="this.form.submit()"><?php foreach (['hot' => 'Hot', 'new' => 'New', 'top' => 'Top', 'rising' => 'Rising'] as $sortKey => $sortLabel): ?><option value="<?= $e($sortKey) ?>" <?= $sort === $sortKey ? 'selected' : '' ?>><?= $e($sortLabel) ?></option><?php endforeach; ?></select>
        <button type="submit"><i class="fas fa-search me-1"></i>Apply</button>
      </form>
      <div class="sort-bar"><span class="small text-muted me-1">Sort by</span><?php foreach (['hot' => 'Hot', 'new' => 'New', 'top' => 'Top', 'rising' => 'Rising'] as $sortKey => $sortLabel): ?><a class="sort-btn <?= $sort === $sortKey ? 'active' : '' ?>" href="<?= $e($feedUrl(['sort' => $sortKey, 'page' => 1])) ?>"><i class="fas <?= $sortKey === 'hot' ? 'fa-fire' : ($sortKey === 'new' ? 'fa-sparkles' : ($sortKey === 'top' ? 'fa-chart-line' : 'fa-arrow-trend-up')) ?> me-1"></i><?= $e($sortLabel) ?></a><?php endforeach; ?></div>

      <?php if ($feed['items'] === []): ?>
        <div class="feed-empty"><i class="fas fa-city fa-2x mb-3"></i><h2 class="h5 fw-bold">No issues match these filters</h2><p class="mb-3">Be the first person to report a problem in this area.</p><a class="btn btn-primary" href="../report/">Report an issue</a></div>
      <?php else: ?>
        <?php foreach ($feed['items'] as $issue): ?>
          <?php $displayStatus = $uiStatus($issue['status']); ?>
          <article class="feed-post" id="issue-<?= (int) $issue['id'] ?>">
            <div class="post-header"><span>r/CivicConnect <span>&bull; Posted by <?= $e($issue['reporter_name'] ?: 'the community') ?></span></span></div>
            <a class="post-title" href="<?= $e($feedUrl(['issue' => (int) $issue['id']])) ?>"><?= $e($issue['title'] ?: issueCategoryLabel($issue['category'])) ?></a>
            <div class="post-meta"><span><i class="fas fa-users me-1"></i><?= (int) $issue['report_count'] ?> report<?= (int) $issue['report_count'] === 1 ? '' : 's' ?></span><span>&bull;</span><span class="badge-location"><i class="fas fa-map-pin me-1"></i><?= $e($issue['address'] ?: 'Location recorded') ?></span><span class="badge-type"><?= $e(issueCategoryLabel($issue['category'])) ?></span><span class="badge-status <?= $e($displayStatus) ?>"><?= $e(issueStatusLabel($issue['status'])) ?></span><?php if (!empty($issue['assigned_worker_name'])): ?><span class="badge-assignment"><i class="fas fa-helmet-safety me-1"></i>Assigned to <?= $e($issue['assigned_worker_name']) ?><?php if (!empty($issue['assigned_by_name'])): ?> by <?= $e($issue['assigned_by_name']) ?><?php endif; ?><?= empty($issue['assignment_completed_at']) ? '' : ' · Completed' ?></span><?php else: ?><span class="badge-location"><i class="fas fa-hourglass-half me-1"></i>Awaiting assignment</span><?php endif; ?><span>&bull;</span><span><?= $e(date('M d, Y', strtotime((string) $issue['created_at']))) ?></span></div>
            <?php if (!empty($issue['is_recurring'])): ?><div class="alert alert-warning py-2 px-3 small mb-2"><i class="fas fa-arrows-rotate me-1"></i>Previously resolved here — this issue has recurred within 30 days.</div><?php endif; ?>
            <?php if ($issue['cover_url']): ?><img src="<?= $e($issue['cover_url']) ?>" alt="<?= $e($issue['title'] ?: 'Issue photo') ?>" class="post-image" loading="lazy"><?php endif; ?>
            <div class="post-actions"><button class="vote-btn <?= $issue['viewer_upvoted'] ? 'voted-up' : '' ?>" type="button" data-upvote-id="<?= (int) $issue['id'] ?>" aria-pressed="<?= $issue['viewer_upvoted'] ? 'true' : 'false' ?>"><i class="fas fa-arrow-up"></i><span class="vote-count"><?= formatNumber((int) $issue['upvote_count']) ?></span></button><button class="action-btn post-detail-btn" type="button" data-detail-id="<?= (int) $issue['id'] ?>"><i class="far fa-images"></i> <?= (int) $issue['image_count'] ?> photos</button><button class="action-btn post-detail-btn" type="button" data-detail-id="<?= (int) $issue['id'] ?>"><i class="far fa-comment"></i> <?= (int) $issue['report_count'] ?> reports</button><span class="post-score"><i class="fas fa-signal me-1"></i>Priority <?= number_format((float) $issue['priority_score'], 0) ?></span></div>
          </article>
        <?php endforeach; ?>
      <?php endif; ?>

      <?php if (($feed['pagination']['pages'] ?? 0) > 1): ?><nav aria-label="Community feed pages" class="d-flex justify-content-center mt-3"><div class="btn-group"><a class="btn btn-sm btn-outline-secondary <?= $page <= 1 ? 'disabled' : '' ?>" href="<?= $e($feedUrl(['page' => max(1, $page - 1)])) ?>">Previous</a><span class="btn btn-sm btn-outline-secondary disabled">Page <?= $page ?> / <?= (int) $feed['pagination']['pages'] ?></span><a class="btn btn-sm btn-outline-secondary <?= $page >= (int) $feed['pagination']['pages'] ? 'disabled' : '' ?>" href="<?= $e($feedUrl(['page' => $page + 1])) ?>">Next</a></div></nav><?php endif; ?>
    </section>

    <aside class="feed-sidebar">
      <div class="side-card"><div class="side-card-header"><span class="side-dots"><span class="bg-danger"></span><span class="bg-warning"></span><span class="bg-success"></span></span>Issue dashboard</div><div class="side-card-body"><div class="stat-grid"><div><div class="stat-number stat-open"><?= (int) $cityStats['open'] ?></div><div class="stat-label">Open</div></div><div><div class="stat-number stat-progress"><?= (int) $cityStats['in_progress'] ?></div><div class="stat-label">In progress</div></div><div><div class="stat-number stat-resolved"><?= (int) $cityStats['resolved'] ?></div><div class="stat-label">Resolved</div></div></div><div id="issueMap" aria-label="City issue density map"></div><div class="map-legend"><span><i class="map-dot map-high"></i>High density</span><span><i class="map-dot map-medium"></i>Medium</span><span><i class="map-dot map-low"></i>Low</span></div><div class="map-preview-footer"><span class="small text-muted">Pan, zoom and tap a cluster.</span><a class="map-preview-link" href="../heatmap/">Open full heatmap <i class="fas fa-arrow-right ms-1"></i></a></div></div></div>
      <div class="side-card"><div class="side-card-header"><i class="fas fa-layer-group text-primary"></i>How grouping works</div><div class="side-card-body"><p class="small text-muted mb-0">Nearby reports with the same category are combined into one post. More evidence increases the density signal and gives administrators a clearer picture of the problem.</p></div></div>
    </aside>
  </main>

  <div class="modal fade" id="issueDetailModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h2 class="modal-title h5" id="detailTitle">Issue details</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body" id="detailBody"><div class="text-center py-4"><div class="spinner-border text-primary" role="status"></div></div></div></div></div></div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script src="https://unpkg.com/maplibre-gl@4.7.1/dist/maplibre-gl.js"></script>
  <script>
    const civicCsrfToken = <?= json_encode(csrfToken(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const civicLoggedIn = <?= $viewer ? 'true' : 'false' ?>;

    function escapeHtml(value) { return String(value ?? '').replace(/[&<>\'"]/g, character => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[character])); }
    function formatCount(value) { return new Intl.NumberFormat().format(Number(value) || 0); }

    async function toggleUpvote(button) {
      if (!civicLoggedIn) { window.location.href = '../login/'; return; }
      button.disabled = true;
      try {
        const response = await fetch('../../api/issues/upvote.php', { method: 'POST', headers: {'Content-Type': 'application/json', 'X-CSRF-Token': civicCsrfToken}, body: JSON.stringify({issue_id: Number(button.dataset.upvoteId)}) });
        const result = await response.json();
        if (!response.ok) throw new Error(result.message || 'Unable to update the vote.');
        button.classList.toggle('voted-up', Boolean(result.data?.upvoted));
        button.setAttribute('aria-pressed', result.data?.upvoted ? 'true' : 'false');
        button.querySelector('.vote-count').textContent = formatCount(result.data?.upvote_count);
      } catch (error) { window.alert(error.message || 'Unable to update the vote.'); } finally { button.disabled = false; }
    }

    async function showIssueDetail(issueId) {
      const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('issueDetailModal'));
      const body = document.getElementById('detailBody');
      body.innerHTML = '<div class="text-center py-4"><div class="spinner-border text-primary" role="status"></div></div>';
      modal.show();
      try {
        const response = await fetch('../../api/issues/detail.php?id=' + encodeURIComponent(issueId));
        const result = await response.json();
        if (!response.ok) throw new Error(result.message || 'Issue details could not be loaded.');
        const issue = result.data.issue;
        document.getElementById('detailTitle').textContent = issue.title || 'Issue details';
        const images = (issue.images || []).map(image => `<img src="${escapeHtml(image.url)}" alt="${escapeHtml(image.original_name || 'Issue photo')}" loading="lazy">`).join('');
        const assignment = issue.assignment ? `<div class="alert alert-success py-2 small"><i class="fas fa-helmet-safety me-1"></i><strong>Assigned to ${escapeHtml(issue.assignment.worker_name || 'field worker')}</strong>${issue.assignment.assigned_by_name ? ` by ${escapeHtml(issue.assignment.assigned_by_name)}` : ''}${issue.assignment.completed_at ? ' · Work completed' : ' · Work in progress'}${issue.assignment.citizen_verified_at ? ' · Citizen verified' : ''}</div>${issue.assignment.after_image_path ? `<img class="img-fluid rounded-3 mb-3" src="/${escapeHtml(issue.assignment.after_image_path)}" alt="Citizen after-photo">` : ''}` : '<div class="alert alert-light border py-2 small"><i class="fas fa-hourglass-half me-1"></i>Waiting for an administrator to assign this issue.</div>';
        body.innerHTML = `<p class="small text-muted"><i class="fas fa-map-marker-alt me-1"></i>${escapeHtml(issue.address || 'Location recorded')} &middot; ${escapeHtml(issue.status || 'pending')}</p>${assignment}${issue.description ? `<p>${escapeHtml(issue.description)}</p>` : ''}<div class="detail-gallery">${images || '<p class="small text-muted">No photos attached.</p>'}</div><p class="small text-muted mt-3 mb-0">${formatCount(issue.report_count)} citizen reports &middot; ${formatCount(issue.upvote_count)} upvotes</p>`;
      } catch (error) { body.innerHTML = `<p class="text-danger mb-0">${escapeHtml(error.message || 'Issue details could not be loaded.')}</p>`; }
    }

    document.querySelectorAll('[data-upvote-id]').forEach(button => button.addEventListener('click', () => toggleUpvote(button)));
    document.querySelectorAll('[data-detail-id]').forEach(button => button.addEventListener('click', () => showIssueDetail(button.dataset.detailId)));

    if (typeof maplibregl !== 'undefined') {
      const previewStyle = {version: 8, sources: {carto: {type: 'raster', tiles: ['https://a.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}.png', 'https://b.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}.png', 'https://c.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}.png'], tileSize: 256, attribution: '&copy; OpenStreetMap contributors &copy; CARTO'}}, layers: [{id: 'carto', type: 'raster', source: 'carto'}]};
      const map = new maplibregl.Map({container: 'issueMap', style: previewStyle, center: [80.2707, 13.0827], zoom: 10.6, minZoom: 8, maxZoom: 17, attributionControl: true, scrollZoom: false});
      map.addControl(new maplibregl.NavigationControl({showCompass: false}), 'bottom-right');
      const heatmapEndpoint = <?= json_encode('../../api/stats/heatmap.php') ?>;
      map.on('load', () => {
        fetch(heatmapEndpoint).then(response => response.json()).then(result => {
          const points = result.data?.points || [];
          const geojson = {type: 'FeatureCollection', features: points.map((point, index) => ({type: 'Feature', geometry: {type: 'Point', coordinates: [Number(point.lng), Number(point.lat)]}, properties: {...point, id: point.id || index}}))};
          map.addSource('preview-points', {type: 'geojson', data: geojson});
          map.addLayer({id: 'preview-halo', type: 'circle', source: 'preview-points', paint: {'circle-color': ['match', ['get', 'band'], 'red', '#ff5264', 'yellow', '#ffbd57', '#39d6a2'], 'circle-radius': ['interpolate', ['linear'], ['get', 'count'], 1, 15, 20, 35], 'circle-blur': .85, 'circle-opacity': .48}});
          map.addLayer({id: 'preview-points', type: 'circle', source: 'preview-points', paint: {'circle-color': ['match', ['get', 'band'], 'red', '#ff5264', 'yellow', '#ffbd57', '#39d6a2'], 'circle-radius': ['interpolate', ['linear'], ['get', 'count'], 1, 5, 20, 12], 'circle-opacity': .95, 'circle-stroke-color': '#fff', 'circle-stroke-width': 1.5}});
          map.addLayer({id: 'preview-counts', type: 'symbol', source: 'preview-points', layout: {'text-field': ['to-string', ['get', 'count']], 'text-size': 9, 'text-allow-overlap': true}, paint: {'text-color': '#09121c'}});
          map.on('click', 'preview-points', event => { const point = event.features?.[0]?.properties || {}; new maplibregl.Popup({closeButton: false, offset: 10}).setLngLat(event.lngLat).setHTML(`<strong>${formatCount(point.count)} reports</strong><br>${escapeHtml(point.title || point.category || 'Issue cluster')}`).addTo(map); });
          map.on('mouseenter', 'preview-points', () => { map.getCanvas().style.cursor = 'pointer'; });
          map.on('mouseleave', 'preview-points', () => { map.getCanvas().style.cursor = ''; });
          if (points.length > 1) { const bounds = points.reduce((current, point) => current.extend([Number(point.lng), Number(point.lat)]), new maplibregl.LngLatBounds()); map.fitBounds(bounds, {padding: 20, maxZoom: 12.2, duration: 0}); }
        }).catch(() => {});
      });
    }

    const requestedIssue = <?= json_encode((int) ($_GET['issue'] ?? 0)) ?>;
    if (requestedIssue > 0) showIssueDetail(requestedIssue);
  </script>
</body>
</html>
