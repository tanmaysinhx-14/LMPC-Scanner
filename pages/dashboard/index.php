<!-- In authority/dashboard.php head -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster/dist/MarkerCluster.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="https://unpkg.com/leaflet.markercluster/dist/leaflet.markercluster.js"></script>
<script src="https://unpkg.com/leaflet.heat/dist/leaflet-heat.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0"></script>

<script type="text/javascript">
  async function loadKPIs() {
    const { data } = await (await fetch('/api/stats/city')).json();
    document.getElementById('kpiOpen').textContent = data.open_count;
    document.getElementById('kpiResolved').textContent = data.resolved_today;
    document.getElementById('kpiAvgTime').textContent = `${data.avg_resolution_hours}h`;
    document.getElementById('kpiCritical').textContent = data.severity_5_count;
}



  const map = L.map('authorityMap').setView([13.0827, 80.2707], 12); // Chennai coords
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png').addTo(map);

// Layer control
const clusterLayer = L.markerClusterGroup({ maxClusterRadius: 60 });
let heatLayer = null;
let currentView = 'cluster'; // 'cluster' | 'heat'

// Severity → color mapping
const severityColors = { 1: '#22c55e', 2: '#84cc16', 3: '#f59e0b', 4: '#f97316', 5: '#ef4444' };

function getSeverityIcon(severity) {
    return L.divIcon({
        className: '',
        html: `<div style="background:${severityColors[severity]};width:14px;height:14px;
               border-radius:50%;border:2px solid white;box-shadow:0 2px 4px rgba(0,0,0,0.3)"></div>`,
        iconSize: [14, 14]
    });
}

async function loadIssues(filters = {}) {
    const params = new URLSearchParams({ ward: filters.ward || '', cat: filters.cat || '', status: 'pending,acknowledged,in_progress' });
    const res = await fetch(`/api/issues/list?${params}`);
    const { data: issues } = await res.json();
    
    clusterLayer.clearLayers();
    
    const heatPoints = [];
    issues.forEach(issue => {
        const marker = L.marker([issue.lat, issue.lng], { icon: getSeverityIcon(issue.severity) });
        marker.bindPopup(buildPopupHTML(issue));
        clusterLayer.addLayer(marker);
        heatPoints.push([issue.lat, issue.lng, issue.severity / 5]); // weight 0–1
    });
    
    clusterLayer.addTo(map);
    
    // Refresh heatmap data
    if (heatLayer) map.removeLayer(heatLayer);
    heatLayer = L.heatLayer(heatPoints, { radius: 30, blur: 20, maxZoom: 17, 
                                           gradient: { 0.3: 'green', 0.6: 'yellow', 1.0: 'red' } });
}

function buildPopupHTML(issue) {
    return `
        <div class="map-popup">
            <strong>#CC-${issue.id}</strong> — ${issue.category}<br>
            <span class="severity-badge sev-${issue.severity}">Severity ${issue.severity}</span>
            <span>👍 ${issue.upvote_count}</span><br>
            <small>${issue.address}</small><br>
            <small>${timeAgo(issue.created_at)}</small>
            <div class="popup-actions">
                <button onclick="openIssuePanel(${issue.id})">Manage →</button>
            </div>
        </div>`;
}





async function loadPriorityQueue() {
    const res = await fetch('/api/issues/list?status=pending,acknowledged&sort=priority&limit=20');
    const { data: issues } = await res.json();
    
    const queueEl = document.getElementById('priorityQueue');
    queueEl.innerHTML = issues.map((issue, i) => `
        <div class="queue-item sev-border-${issue.severity}" onclick="openIssuePanel(${issue.id})">
            <div class="queue-rank">${i + 1}</div>
            <div class="queue-content">
                <div class="queue-title">${issue.category.replace(/_/g,' ')} — Ward ${issue.ward_id}</div>
                <div class="queue-meta">
                    <span class="severity-chip">⚠️ ${issue.severity}/5</span>
                    <span>👍 ${issue.upvote_count}</span>
                    <span>${timeAgo(issue.created_at)}</span>
                </div>
            </div>
        </div>`).join('');
}
</script>