<?php
declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';
$bootstrapData = bootstrapAccounts();
extract($bootstrapData);

$viewer = sessionUser();
$viewerRole = (string) ($viewer['role'] ?? '');
$dashboardPath = civicDashboardForRole($viewerRole);
$heatmapEndpoint = civicApi('stats/heatmap.php');
$cityStats = getIssueStats($db);
$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="en" data-theme="dark">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="description" content="Explore CivicConnect issue density across Chennai">
  <meta name="theme-color" content="#08111b">
  <title>CivicConnect · City Pulse</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://unpkg.com/maplibre-gl@4.7.1/dist/maplibre-gl.css">
  <link rel="stylesheet" href="<?= $e(civicAsset('css/civic-ui.css')) ?>">
  <style>
    :root {
      --pulse-bg: #08111b;
      --pulse-panel: rgba(13, 27, 40, .9);
      --pulse-panel-solid: #0d1b28;
      --pulse-line: rgba(173, 203, 222, .14);
      --pulse-muted: #8da3b2;
      --pulse-text: #edf7fb;
      --pulse-blue: #67d5ff;
      --pulse-green: #39d6a2;
      --pulse-yellow: #ffbd57;
      --pulse-red: #ff5264;
    }
    * { box-sizing: border-box; }
    html, body { min-height: 100%; }
    body { margin: 0; color: var(--pulse-text); background: var(--pulse-bg); font-family: 'DM Sans', ui-sans-serif, system-ui, sans-serif; }
    a { color: inherit; }
    .pulse-shell { min-height: 100vh; background: radial-gradient(circle at 70% -10%, rgba(48, 126, 172, .25), transparent 35%), var(--pulse-bg); }
    .pulse-nav { position: relative; z-index: 5; display: flex; align-items: center; justify-content: space-between; gap: 1rem; min-height: 72px; padding: .9rem clamp(1rem, 3vw, 3rem); border-bottom: 1px solid var(--pulse-line); background: rgba(8, 17, 27, .78); backdrop-filter: blur(20px); }
    .pulse-brand { display: inline-flex; align-items: center; gap: .7rem; color: var(--pulse-text); font-family: 'Space Grotesk', sans-serif; font-size: 1.03rem; font-weight: 700; letter-spacing: -.025em; text-decoration: none; }
    .pulse-brand-mark { display: inline-grid; width: 2.15rem; height: 2.15rem; place-items: center; border: 1px solid rgba(103, 213, 255, .4); border-radius: .72rem; color: #06121b; background: linear-gradient(135deg, var(--pulse-blue), #c0f2ff); box-shadow: 0 0 24px rgba(103, 213, 255, .26); }
    .pulse-nav-links { display: flex; align-items: center; gap: .3rem; }
    .pulse-nav-links a { padding: .47rem .7rem; border-radius: .55rem; color: var(--pulse-muted); font-size: .78rem; font-weight: 600; text-decoration: none; }
    .pulse-nav-links a:hover, .pulse-nav-links a.active { color: var(--pulse-text); background: rgba(255,255,255,.08); }
    .pulse-nav-actions { display: flex; align-items: center; gap: .5rem; }
    .pulse-nav-actions .btn { border-color: var(--pulse-line); color: var(--pulse-text); font-size: .75rem; }
    .pulse-nav-actions .btn:hover { border-color: rgba(103, 213, 255, .55); background: rgba(103, 213, 255, .1); }
    .pulse-layout { display: grid; grid-template-columns: minmax(0, 1fr) 360px; gap: 1rem; width: min(1600px, 100%); min-height: calc(100vh - 72px); margin: 0 auto; padding: 1rem; }
    .map-stage { position: relative; min-height: 690px; overflow: hidden; border: 1px solid var(--pulse-line); border-radius: 1.25rem; background: #101e2a; box-shadow: 0 24px 80px rgba(0,0,0,.26); }
    #cityMap { position: absolute; inset: 0; }
    #cityMap .maplibregl-ctrl-top-right { top: 7.7rem; right: 1rem; }
    #cityMap .maplibregl-ctrl-group { overflow: hidden; border: 1px solid rgba(255,255,255,.13); border-radius: .62rem; background: rgba(8,17,27,.84); box-shadow: 0 8px 25px rgba(0,0,0,.22); }
    #cityMap .maplibregl-ctrl-group button { width: 31px; height: 31px; filter: invert(1) saturate(.2); }
    #cityMap .maplibregl-ctrl-attrib { color: #a9c0cc; background: rgba(8,17,27,.72); font-size: .58rem; }
    #cityMap .maplibregl-ctrl-attrib a { color: #c9e7f3; }
    .map-scrim { position: absolute; inset: 0; z-index: 1; pointer-events: none; background: linear-gradient(180deg, rgba(5,13,22,.74), transparent 28%, transparent 70%, rgba(5,13,22,.35)); }
    .map-intro { position: absolute; z-index: 2; top: 1.35rem; left: 1.35rem; max-width: 28rem; pointer-events: none; }
    .eyebrow { color: var(--pulse-blue); font-size: .65rem; font-weight: 700; letter-spacing: .16em; text-transform: uppercase; }
    .map-intro h1 { margin: .35rem 0 .45rem; font-family: 'Space Grotesk', sans-serif; font-size: clamp(1.65rem, 3vw, 2.55rem); font-weight: 700; letter-spacing: -.055em; line-height: 1; }
    .map-intro p { max-width: 24rem; margin: 0; color: #b3c5cf; font-size: .82rem; line-height: 1.55; }
    .map-toolbar { position: absolute; z-index: 3; top: 1.25rem; right: 1.25rem; display: flex; align-items: center; gap: .4rem; width: min(23rem, calc(100% - 2.5rem)); }
    .map-search { display: flex; flex: 1; align-items: center; gap: .55rem; min-width: 0; padding: .55rem .7rem; border: 1px solid rgba(255,255,255,.16); border-radius: .7rem; color: var(--pulse-muted); background: rgba(8,17,27,.84); box-shadow: 0 8px 25px rgba(0,0,0,.2); backdrop-filter: blur(14px); }
    .map-search:focus-within { border-color: rgba(103, 213, 255, .65); box-shadow: 0 0 0 .2rem rgba(103, 213, 255, .1); }
    .map-search input { width: 100%; min-width: 0; border: 0; outline: 0; color: var(--pulse-text); background: transparent; font: inherit; font-size: .74rem; }
    .map-search input::placeholder { color: #718895; }
    .map-tool-btn { display: inline-grid; width: 2.25rem; height: 2.25rem; flex: 0 0 auto; place-items: center; border: 1px solid rgba(255,255,255,.16); border-radius: .7rem; color: var(--pulse-text); background: rgba(8,17,27,.84); box-shadow: 0 8px 25px rgba(0,0,0,.2); }
    .map-tool-btn:hover, .map-tool-btn.active { border-color: rgba(103, 213, 255, .65); color: var(--pulse-blue); background: rgba(18, 53, 71, .92); }
    .map-legend-panel { position: absolute; z-index: 3; bottom: 1.1rem; left: 1.1rem; display: flex; flex-wrap: wrap; align-items: center; gap: .7rem; padding: .55rem .72rem; border: 1px solid rgba(255,255,255,.12); border-radius: .68rem; color: #b2c3cb; background: rgba(8,17,27,.76); font-size: .66rem; backdrop-filter: blur(12px); }
    .map-legend-panel span { display: inline-flex; align-items: center; gap: .3rem; }
    .legend-dot { width: .55rem; height: .55rem; border-radius: 50%; box-shadow: 0 0 9px currentColor; }
    .legend-red { color: var(--pulse-red); background: currentColor; } .legend-yellow { color: var(--pulse-yellow); background: currentColor; } .legend-green { color: var(--pulse-green); background: currentColor; }
    .map-status { position: absolute; z-index: 3; right: 1.1rem; bottom: 1.1rem; max-width: 16rem; padding: .55rem .72rem; border: 1px solid rgba(255,255,255,.12); border-radius: .68rem; color: #b2c3cb; background: rgba(8,17,27,.76); font-size: .66rem; text-align: right; backdrop-filter: blur(12px); }
    .map-status.is-error { color: #ffb4bd; border-color: rgba(255,82,100,.3); }
    .signal-panel { display: flex; min-height: 690px; flex-direction: column; overflow: hidden; border: 1px solid var(--pulse-line); border-radius: 1.25rem; background: var(--pulse-panel); box-shadow: 0 24px 80px rgba(0,0,0,.2); }
    .panel-header { display: flex; align-items: flex-start; justify-content: space-between; gap: .8rem; padding: 1.25rem 1.25rem 1rem; border-bottom: 1px solid var(--pulse-line); }
    .panel-header h2 { margin: .25rem 0 0; font-family: 'Space Grotesk', sans-serif; font-size: 1.22rem; letter-spacing: -.04em; }
    .source-badge { display: inline-flex; align-items: center; gap: .35rem; padding: .3rem .5rem; border: 1px solid rgba(57,214,162,.27); border-radius: 999px; color: var(--pulse-green); background: rgba(57,214,162,.09); font-size: .62rem; font-weight: 700; white-space: nowrap; }
    .source-badge i { font-size: .47rem; }
    .pulse-stats { display: grid; grid-template-columns: repeat(2, 1fr); gap: .5rem; padding: 1rem 1.25rem; }
    .pulse-stat { padding: .75rem .8rem; border: 1px solid var(--pulse-line); border-radius: .75rem; background: rgba(255,255,255,.035); }
    .pulse-stat-value { color: var(--pulse-text); font-family: 'Space Grotesk', sans-serif; font-size: 1.3rem; font-weight: 700; letter-spacing: -.05em; }
    .pulse-stat-label { margin-top: .12rem; color: var(--pulse-muted); font-size: .62rem; }
    .filter-block { padding: 0 1.25rem 1rem; }
    .filter-label { display: block; margin-bottom: .42rem; color: var(--pulse-muted); font-size: .64rem; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; }
    .pulse-select { width: 100%; padding: .62rem .7rem; border: 1px solid var(--pulse-line); border-radius: .62rem; outline: 0; color: var(--pulse-text); background: #122635; font: inherit; font-size: .73rem; }
    .pulse-select:focus { border-color: rgba(103, 213, 255, .6); box-shadow: 0 0 0 .18rem rgba(103, 213, 255, .1); }
    .filter-row { display: grid; grid-template-columns: 1fr 1fr; gap: .5rem; margin-top: .5rem; }
    .filter-action { display: inline-flex; align-items: center; justify-content: center; gap: .35rem; width: 100%; padding: .58rem; border: 1px solid var(--pulse-line); border-radius: .62rem; color: var(--pulse-muted); background: transparent; font: inherit; font-size: .7rem; font-weight: 650; }
    .filter-action:hover { border-color: rgba(103, 213, 255, .55); color: var(--pulse-blue); background: rgba(103, 213, 255, .07); }
    .cluster-heading { display: flex; align-items: center; justify-content: space-between; gap: .5rem; padding: .1rem 1.25rem .65rem; }
    .cluster-heading h3 { margin: 0; color: var(--pulse-text); font-size: .77rem; font-weight: 700; }
    .cluster-heading span { color: var(--pulse-muted); font-size: .65rem; }
    .cluster-list { min-height: 0; flex: 1; overflow-y: auto; padding: 0 1.25rem .85rem; scrollbar-color: #2a4a5e transparent; scrollbar-width: thin; }
    .cluster-item { display: grid; grid-template-columns: 2.25rem minmax(0,1fr) auto; gap: .65rem; align-items: center; width: 100%; padding: .75rem .1rem; border: 0; border-top: 1px solid var(--pulse-line); color: inherit; background: transparent; text-align: left; }
    .cluster-item:hover, .cluster-item.selected { background: linear-gradient(90deg, rgba(103,213,255,.09), transparent); }
    .cluster-count { display: inline-grid; width: 2.1rem; height: 2.1rem; place-items: center; border-radius: 50%; color: #07131c; font-family: 'Space Grotesk', sans-serif; font-size: .72rem; font-weight: 700; box-shadow: 0 0 17px rgba(255,255,255,.12); }
    .cluster-count.red { background: var(--pulse-red); box-shadow: 0 0 17px rgba(255,82,100,.28); } .cluster-count.yellow { background: var(--pulse-yellow); box-shadow: 0 0 17px rgba(255,189,87,.24); } .cluster-count.green { background: var(--pulse-green); box-shadow: 0 0 17px rgba(57,214,162,.24); }
    .cluster-title { overflow: hidden; color: var(--pulse-text); font-size: .74rem; font-weight: 650; text-overflow: ellipsis; white-space: nowrap; }
    .cluster-meta { overflow: hidden; margin-top: .17rem; color: var(--pulse-muted); font-size: .61rem; text-overflow: ellipsis; white-space: nowrap; }
    .cluster-arrow { color: #547384; font-size: .68rem; }
    .empty-clusters { padding: 2rem .5rem; color: var(--pulse-muted); font-size: .75rem; text-align: center; }
    .panel-footer { display: flex; align-items: center; justify-content: space-between; gap: .5rem; padding: .85rem 1.25rem; border-top: 1px solid var(--pulse-line); color: var(--pulse-muted); font-size: .63rem; }
    .panel-footer a { color: var(--pulse-blue); font-weight: 650; text-decoration: none; }
    .map-popup { min-width: 190px; color: #18303d; font-family: 'DM Sans', sans-serif; }
    .map-popup-kicker { color: #527383; font-size: .6rem; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; }
    .map-popup h3 { margin: .25rem 0 .35rem; color: #132d3a; font-family: 'Space Grotesk', sans-serif; font-size: .95rem; line-height: 1.15; }
    .map-popup p { margin: .25rem 0; color: #58707b; font-size: .7rem; }
    .map-popup a { display: inline-block; margin-top: .45rem; color: #0c72a0; font-size: .68rem; font-weight: 700; text-decoration: none; }
    @media (max-width: 1050px) { .pulse-layout { grid-template-columns: minmax(0, 1fr) 315px; } }
    @media (max-width: 820px) { .pulse-nav-links { display: none; } .pulse-layout { display: block; min-height: 0; padding: .65rem; } .map-stage { min-height: 67vh; } .signal-panel { min-height: 0; margin-top: .65rem; } .cluster-list { max-height: 25rem; } }
    @media (max-width: 520px) { .pulse-nav { min-height: 62px; padding: .7rem .8rem; } .pulse-brand span:last-child { display: none; } .pulse-nav-actions .btn span { display: none; } .map-intro { top: 1rem; left: 1rem; } .map-intro h1 { font-size: 1.65rem; } .map-intro p { max-width: 17rem; font-size: .72rem; } .map-toolbar { top: 6.45rem; right: 1rem; width: calc(100% - 2rem); } .map-legend-panel { right: 1rem; bottom: 1rem; left: 1rem; } .map-status { display: none; } .map-stage { min-height: 72vh; border-radius: 1rem; } .signal-panel { border-radius: 1rem; } }
  </style>
</head>
<body>
  <div class="pulse-shell">
    <nav class="pulse-nav" aria-label="City pulse navigation">
      <a class="pulse-brand" href="<?= $e(civicRoute('home')) ?>"><span class="pulse-brand-mark"><i class="fas fa-location-crosshairs"></i></span><span>CivicConnect / City pulse</span></a>
      <div class="pulse-nav-links"><a href="<?= $e(civicRoute('feed')) ?>"><i class="fas fa-layer-group me-1"></i>Community feed</a><a href="<?= $e(civicRoute('pulse')) ?>" class="active"><i class="fas fa-map-location-dot me-1"></i>Heatmap</a></div>
      <div class="pulse-nav-actions">
        <?php if ($viewer): ?>
          <a class="btn btn-sm" href="<?= $e($dashboardPath) ?>"><i class="fas fa-th-large me-1"></i><span>Dashboard</span></a>
          <?php if ($viewerRole === 'citizen'): ?><a class="btn btn-sm btn-primary" href="<?= $e(civicRoute('report')) ?>"><i class="fas fa-plus me-1"></i><span>Report</span></a><?php endif; ?>
        <?php else: ?>
          <a class="btn btn-sm" href="<?= $e(civicRoute('login')) ?>"><i class="fas fa-sign-in-alt me-1"></i><span>Sign in</span></a>
          <a class="btn btn-sm btn-primary" href="<?= $e(civicRoute('register')) ?>"><i class="fas fa-user-plus me-1"></i><span>Join</span></a>
        <?php endif; ?>
      </div>
    </nav>

    <main class="pulse-layout">
      <section class="map-stage" aria-label="Interactive city issue heatmap">
        <div id="cityMap"></div>
        <div class="map-scrim"></div>
        <div class="map-intro">
          <div class="eyebrow"><i class="fas fa-satellite-dish me-1"></i>Community signal</div>
          <h1>Feel the city pulse.</h1>
          <p>Explore where reports are gathering, see what needs attention, and zoom from a city-wide signal to a single neighbourhood.</p>
        </div>
        <div class="map-toolbar">
          <label class="map-search" for="clusterSearch"><i class="fas fa-search"></i><input id="clusterSearch" type="search" placeholder="Search places or issues" autocomplete="off"></label>
          <button class="map-tool-btn" id="resetView" type="button" title="Fit all visible clusters" aria-label="Fit all visible clusters"><i class="fas fa-expand"></i></button>
          <button class="map-tool-btn active" id="densityToggle" type="button" title="Toggle cluster glow" aria-label="Toggle cluster glow" aria-pressed="true"><i class="fas fa-wand-magic-sparkles"></i></button>
        </div>
        <div class="map-legend-panel"><span><i class="legend-dot legend-red"></i>High density</span><span><i class="legend-dot legend-yellow"></i>Building</span><span><i class="legend-dot legend-green"></i>Low density</span></div>
        <div class="map-status" id="mapStatus" role="status">Loading community signal…</div>
      </section>

      <aside class="signal-panel" aria-label="Heatmap controls and cluster list">
        <div class="panel-header"><div><div class="eyebrow">Live issue intelligence</div><h2>Signal explorer</h2></div><span class="source-badge" id="sourceBadge"><i class="fas fa-circle"></i><span>Database</span></span></div>
        <div class="pulse-stats">
          <div class="pulse-stat"><div class="pulse-stat-value" data-stat="reports">0</div><div class="pulse-stat-label">Visible reports</div></div>
          <div class="pulse-stat"><div class="pulse-stat-value" data-stat="clusters">0</div><div class="pulse-stat-label">Issue clusters</div></div>
          <div class="pulse-stat"><div class="pulse-stat-value" data-stat="open">0</div><div class="pulse-stat-label">Open reports</div></div>
          <div class="pulse-stat"><div class="pulse-stat-value" data-stat="resolved">0</div><div class="pulse-stat-label">Resolved reports</div></div>
        </div>
        <div class="filter-block">
          <label class="filter-label" for="categoryFilter">Focus the signal</label>
          <select class="pulse-select" id="categoryFilter"><option value="">All categories</option><?php foreach (['pothole','garbage','streetlight','waterlogging','road_damage','encroachment','graffiti','open_drain','fallen_tree','other'] as $category): ?><option value="<?= $e($category) ?>"><?= $e(issueCategoryLabel($category)) ?></option><?php endforeach; ?></select>
          <div class="filter-row"><select class="pulse-select" id="statusFilter" aria-label="Filter by status"><option value="">All statuses</option><option value="open">Open only</option><option value="resolved">Resolved only</option></select><button class="filter-action" id="clearFilters" type="button"><i class="fas fa-rotate-left"></i>Reset</button></div>
        </div>
        <div class="cluster-heading"><h3>Most active clusters</h3><span id="clusterCount">0 visible</span></div>
        <div class="cluster-list" id="clusterList"><div class="empty-clusters">Waiting for the map signal…</div></div>
        <div class="panel-footer"><span><i class="fas fa-circle-info me-1"></i>Clusters group nearby reports.</span><a href="<?= $e(civicRoute('feed')) ?>">Browse feed <i class="fas fa-arrow-right ms-1"></i></a></div>
      </aside>
    </main>
  </div>

  <script src="https://unpkg.com/maplibre-gl@4.7.1/dist/maplibre-gl.js"></script>
  <script>
    const heatmapEndpoint = <?= json_encode($heatmapEndpoint, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const issueDetailUrl = <?= json_encode(civicRoute('issue_detail'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const mapStyle = {version: 8, sources: {carto: {type: 'raster', tiles: ['https://a.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}.png', 'https://b.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}.png', 'https://c.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}.png'], tileSize: 256, attribution: '&copy; OpenStreetMap contributors &copy; CARTO'}}, layers: [{id: 'carto', type: 'raster', source: 'carto'}]};
    const state = {all: [], visible: [], glow: true};
    const categoryLabels = {pothole: 'Pothole', garbage: 'Garbage', streetlight: 'Streetlight', waterlogging: 'Waterlogging', road_damage: 'Road damage', encroachment: 'Encroachment', graffiti: 'Graffiti', open_drain: 'Open drain', fallen_tree: 'Fallen tree', other: 'Other'};
    const escapeHtml = value => String(value ?? '').replace(/[&<>\'"]/g, character => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[character]));
    const formatCount = value => new Intl.NumberFormat().format(Number(value) || 0);
    const labelFor = value => categoryLabels[String(value || '').toLowerCase()] || String(value || 'Other').replace(/_/g, ' ');
    const map = typeof maplibregl !== 'undefined' ? new maplibregl.Map({container: 'cityMap', style: mapStyle, center: [80.2707, 13.0827], zoom: 10.4, minZoom: 8, maxZoom: 18, attributionControl: true}) : null;

    function normalizePoint(point, index) {
      return {
        ...point,
        id: String(point.id ?? index),
        lat: Number(point.lat),
        lng: Number(point.lng),
        count: Math.max(1, Number(point.count ?? point.report_count) || 1),
        severity: Number(point.severity) || 1,
        upvotes: Number(point.upvotes) || 0,
        category: String(point.category || 'other'),
        status: String(point.status || 'pending'),
        title: String(point.title || 'Community issue cluster'),
        address: String(point.address || 'Location recorded')
      };
    }

    function toFeatureCollection(points) {
      return {type: 'FeatureCollection', features: points.map(point => ({type: 'Feature', geometry: {type: 'Point', coordinates: [point.lng, point.lat]}, properties: point}))};
    }

    function visibleStats(points) {
      const reports = points.reduce((sum, point) => sum + point.count, 0);
      const open = points.filter(point => point.status !== 'resolved').reduce((sum, point) => sum + point.count, 0);
      const resolved = points.filter(point => point.status === 'resolved').reduce((sum, point) => sum + point.count, 0);
      document.querySelector('[data-stat="reports"]').textContent = formatCount(reports);
      document.querySelector('[data-stat="clusters"]').textContent = formatCount(points.length);
      document.querySelector('[data-stat="open"]').textContent = formatCount(open);
      document.querySelector('[data-stat="resolved"]').textContent = formatCount(resolved);
      document.getElementById('clusterCount').textContent = `${formatCount(points.length)} visible`;
    }

    function setStatus(message, isError = false) {
      const status = document.getElementById('mapStatus');
      status.textContent = message;
      status.classList.toggle('is-error', isError);
    }

    function updateSource(points) {
      const source = map?.getSource('city-points');
      if (source) source.setData(toFeatureCollection(points));
    }

    function fitToPoints(points) {
      if (!map || points.length === 0) return;
      if (points.length === 1) { map.flyTo({center: [points[0].lng, points[0].lat], zoom: 13.5, speed: 1.2}); return; }
      const bounds = points.reduce((current, point) => current.extend([point.lng, point.lat]), new maplibregl.LngLatBounds());
      map.fitBounds(bounds, {padding: {top: 130, right: 90, bottom: 90, left: 90}, maxZoom: 13.2, duration: 850});
    }

    function popupFor(point) {
      const liveIssueLink = /^\d+$/.test(point.id) ? `<a href="${issueDetailUrl}?id=${encodeURIComponent(point.id)}">Open issue details <i class="fas fa-arrow-right ms-1"></i></a>` : '';
      return `<div class="map-popup"><div class="map-popup-kicker">${escapeHtml(labelFor(point.category))} · ${escapeHtml(point.status.replace(/_/g, ' '))}</div><h3>${escapeHtml(point.title)}</h3><p><i class="fas fa-location-dot me-1"></i>${escapeHtml(point.address)}</p><p><strong>${formatCount(point.count)} reports</strong> · ${formatCount(point.upvotes)} upvotes</p>${liveIssueLink}</div>`;
    }

    function openPoint(point) {
      if (!map || !point) return;
      map.flyTo({center: [point.lng, point.lat], zoom: Math.max(map.getZoom(), 13.5), speed: 1.1});
      new maplibregl.Popup({closeButton: false, offset: 16, maxWidth: '270px'}).setLngLat([point.lng, point.lat]).setHTML(popupFor(point)).addTo(map);
      document.querySelectorAll('.cluster-item').forEach(item => item.classList.toggle('selected', item.dataset.pointId === point.id));
    }

    function renderList(points) {
      const list = document.getElementById('clusterList');
      const sorted = [...points].sort((a, b) => b.count - a.count || b.severity - a.severity);
      if (sorted.length === 0) { list.innerHTML = '<div class="empty-clusters"><i class="fas fa-filter-circle-xmark d-block mb-2"></i>No clusters match these filters.</div>'; return; }
      list.innerHTML = sorted.slice(0, 12).map(point => `<button type="button" class="cluster-item" data-point-id="${escapeHtml(point.id)}"><span class="cluster-count ${escapeHtml(point.band || 'green')}">${formatCount(point.count)}</span><span class="min-w-0"><span class="cluster-title d-block">${escapeHtml(point.title)}</span><span class="cluster-meta d-block">${escapeHtml(point.address)} · ${escapeHtml(labelFor(point.category))}</span></span><i class="cluster-arrow fas fa-chevron-right"></i></button>`).join('');
      list.querySelectorAll('[data-point-id]').forEach(button => button.addEventListener('click', () => openPoint(state.visible.find(point => point.id === button.dataset.pointId))));
    }

    function applyFilters() {
      const query = document.getElementById('clusterSearch').value.trim().toLowerCase();
      const category = document.getElementById('categoryFilter').value;
      const status = document.getElementById('statusFilter').value;
      state.visible = state.all.filter(point => {
        const matchesSearch = !query || `${point.title} ${point.address} ${point.category}`.toLowerCase().includes(query);
        const matchesCategory = !category || point.category === category;
        const matchesStatus = !status || (status === 'open' ? point.status !== 'resolved' : point.status === status);
        return matchesSearch && matchesCategory && matchesStatus;
      });
      updateSource(state.visible);
      visibleStats(state.visible);
      renderList(state.visible);
      setStatus(`${formatCount(state.visible.length)} clusters · drag to explore`);
    }

    function addMapLayers() {
      map.addSource('city-points', {type: 'geojson', data: toFeatureCollection([])});
      const color = ['match', ['get', 'band'], 'red', '#ff5264', 'yellow', '#ffbd57', '#39d6a2'];
      map.addLayer({id: 'city-halos', type: 'circle', source: 'city-points', paint: {'circle-color': color, 'circle-radius': ['interpolate', ['linear'], ['zoom'], 8, ['interpolate', ['linear'], ['get', 'count'], 1, 10, 20, 24], 14, ['interpolate', ['linear'], ['get', 'count'], 1, 24, 20, 55]], 'circle-blur': .9, 'circle-opacity': .58}});
      map.addLayer({id: 'city-points', type: 'circle', source: 'city-points', paint: {'circle-color': color, 'circle-radius': ['interpolate', ['linear'], ['zoom'], 8, ['interpolate', ['linear'], ['get', 'count'], 1, 5, 20, 12], 14, ['interpolate', ['linear'], ['get', 'count'], 1, 8, 20, 22]], 'circle-opacity': .96, 'circle-stroke-color': '#ecfbff', 'circle-stroke-width': ['interpolate', ['linear'], ['get', 'severity'], 1, 1, 5, 2.8], 'circle-stroke-opacity': .86}});
      map.addLayer({id: 'city-counts', type: 'symbol', source: 'city-points', layout: {'text-field': ['to-string', ['get', 'count']], 'text-size': ['interpolate', ['linear'], ['zoom'], 8, 9, 14, 12], 'text-allow-overlap': true}, paint: {'text-color': '#07131c', 'text-halo-color': 'rgba(255,255,255,.35)', 'text-halo-width': 1}});
      map.on('click', 'city-points', event => { const feature = event.features?.[0]; if (feature) openPoint(normalizePoint(feature.properties, 0)); });
      map.on('mouseenter', 'city-points', () => { map.getCanvas().style.cursor = 'pointer'; });
      map.on('mouseleave', 'city-points', () => { map.getCanvas().style.cursor = ''; });
    }

    function loadPoints() {
      fetch(heatmapEndpoint).then(response => response.json()).then(result => {
        if (!result.data) throw new Error(result.message || 'Heatmap data could not be loaded.');
        state.all = (result.data.points || []).map(normalizePoint).filter(point => Number.isFinite(point.lat) && Number.isFinite(point.lng));
        const sourceBadge = document.getElementById('sourceBadge');
        sourceBadge.querySelector('span').textContent = 'Database';
        state.visible = [...state.all];
        updateSource(state.visible);
        visibleStats(state.visible);
        renderList(state.visible);
        fitToPoints(state.visible);
        setStatus(`${formatCount(state.all.length)} clusters · drag to explore`);
      }).catch(error => { setStatus(error.message || 'Unable to load map data.', true); document.getElementById('clusterList').innerHTML = '<div class="empty-clusters">The signal is temporarily unavailable.</div>'; });
    }

    if (map) {
      map.addControl(new maplibregl.NavigationControl({showCompass: false}), 'top-right');
      map.addControl(new maplibregl.GeolocateControl({positionOptions: {enableHighAccuracy: true}, trackUserLocation: false}), 'top-right');
      map.on('load', () => { addMapLayers(); loadPoints(); });
    } else { setStatus('Map library could not be loaded.', true); }

    document.getElementById('clusterSearch').addEventListener('input', applyFilters);
    document.getElementById('categoryFilter').addEventListener('change', applyFilters);
    document.getElementById('statusFilter').addEventListener('change', applyFilters);
    document.getElementById('clearFilters').addEventListener('click', () => { document.getElementById('clusterSearch').value = ''; document.getElementById('categoryFilter').value = ''; document.getElementById('statusFilter').value = ''; applyFilters(); fitToPoints(state.visible); });
    document.getElementById('resetView').addEventListener('click', () => fitToPoints(state.visible.length ? state.visible : state.all));
    document.getElementById('densityToggle').addEventListener('click', event => { state.glow = !state.glow; event.currentTarget.classList.toggle('active', state.glow); event.currentTarget.setAttribute('aria-pressed', String(state.glow)); if (map?.getLayer('city-halos')) map.setPaintProperty('city-halos', 'circle-opacity', state.glow ? .58 : 0); });
  </script>
</body>
</html>
