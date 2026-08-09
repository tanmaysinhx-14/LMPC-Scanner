<?php // Bootstrapper
  require __DIR__ . '/../../bootstrap.php';
  $bootstrapData = bootstrapAccounts(options: ['required_roles' => ['citizen']]);
  extract($bootstrapData);
?>
<?php require_once __DIR__ . '/../../components/header.php'; ?>

<body class="bg-light">
  <style>
    body { font-family: 'Inter', sans-serif; }
    .report-intro { background: linear-gradient(135deg, #eef0ff, #fafaff); border: 1px solid #dfe2ff; }
    .location-meta { min-height: 1.2rem; }
    .border-dashed { border-style: dashed !important; cursor: pointer; transition: all 0.2s; }
    .border-dashed:hover { background-color: #f8fafc !important; border-color: #4f46e5 !important; }
  </style>

  <nav class="navbar report-topnav sticky-top px-3 py-2">
    <div class="container-fluid px-lg-4">
      <a class="navbar-brand d-flex align-items-center gap-2" href="../citizen/citizen-dashboard.php"><span class="feed-brand-icon"><i class="fas fa-city"></i></span><span>CivicConnect</span></a>
      <div class="d-flex align-items-center gap-2">
        <a class="btn btn-sm btn-outline-secondary" href="../citizen/citizen-dashboard.php"><i class="fas fa-th-large me-1"></i><span class="d-none d-sm-inline">Dashboard</span></a>
        <a class="btn btn-sm btn-outline-secondary" href="../citizen/public-feed.php"><i class="fas fa-globe me-1"></i><span class="d-none d-md-inline">Public feed</span></a>
        <a class="btn btn-sm btn-outline-secondary" href="../heatmap/"><i class="fas fa-map-location-dot me-1"></i><span class="d-none d-md-inline">City pulse</span></a>
        <a class="btn btn-sm btn-link text-danger text-decoration-none" href="../logout/"><i class="fas fa-sign-out-alt me-1"></i><span class="d-none d-sm-inline">Logout</span></a>
      </div>
    </div>
  </nav>

  <main class="min-vh-100 py-4">
    <div class="container">
      <div class="row justify-content-center">
        <div class="col-12 col-lg-9 col-xl-8">
          <div class="report-intro rounded-4 p-4 mb-3">
            <div class="d-flex align-items-start gap-3"><span class="rounded-3 bg-primary text-white d-inline-flex align-items-center justify-content-center" style="width:44px;height:44px"><i class="fas fa-camera"></i></span><div><div class="cc-eyebrow">New community report</div><h1 class="h3 fw-bold mb-1">Help your city see the problem.</h1><p class="text-muted mb-0">Add one clear photo and your current location. Nearby reports are grouped together so administrators can coordinate field work.</p></div></div>
          </div>
          <div class="card shadow-sm border-0 rounded-4">
            <div class="card-header bg-white border-bottom-0 pt-4 pb-0 px-4">
              <h2 class="h5 mb-0 fw-bold text-dark">Report details</h2>
            </div>
            <div class="card-body p-4">
              <form id="issueReportForm" method="POST" action="./" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" id="latitude" name="latitude" value="">
                <input type="hidden" id="longitude" name="longitude" value="">
                <input type="hidden" id="gpsAccuracy" name="gps_accuracy" value="">
                <div class="mb-4">
                  <label class="form-label fw-semibold">Issue Photo <span class="text-danger">*</span></label>
                  <label class="d-block w-100 p-5 text-center border border-2 border-secondary-subtle rounded-3 bg-light border-dashed" for="issueImage">
                    <i class="fas fa-camera fs-1 text-secondary mb-3"></i>
                    <span class="d-block text-secondary">Tap to capture or <strong class="text-primary">upload image</strong></span>
                  </label>
                  <input type="file" id="issueImage" name="issueImage" accept="image/*" capture="environment" class="d-none" required>
                </div>

                <div id="imagePreviewContainer" class="d-none mb-4 position-relative border rounded-3 p-2 bg-light align-items-center">
                  <img id="previewImg" src="" alt="Preview" class="rounded object-fit-cover" style="width: 60px; height: 60px;">
                  <span class="ms-3 text-truncate flex-grow-1 fw-medium" id="previewName">issue.jpg</span>
                  <button type="button" class="btn btn-sm btn-outline-danger border-0 ms-auto" id="removeImageBtn">
                    <i class="fas fa-times"></i>
                  </button>
                </div>

                <div id="aiResultCard" class="card bg-primary-subtle border-primary-subtle mb-4 d-none">
                  <div id="aiSkeleton" class="card-body placeholder-glow">
                    <span class="placeholder col-4 rounded bg-primary"></span>
                    <span class="placeholder col-7 rounded bg-secondary mt-2"></span>
                    <span class="placeholder col-5 rounded bg-secondary mt-2"></span>
                  </div>
                  <div id="aiContent" class="card-body d-none">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                      <span class="small fw-semibold text-primary"><i class="fas fa-robot me-2"></i>AI Analysis Complete</span>
                      <span id="aiConfidence" class="badge bg-success"></span>
                    </div>
                    <p class="small mb-0 text-dark">Detected Issue: <strong id="severityCategory" class="text-danger ms-1"></strong></p>
                    <p class="small mb-0 text-dark">Detected Severity: <strong id="severityDisplay" class="text-danger ms-1"></strong></p>
                  </div>
                </div>

                <div class="mb-4">
                  <label for="category" class="form-label fw-semibold">Category <span class="text-danger">*</span></label>
                  <select id="category" name="issueCategory" class="form-select" required>
                    <option value="">Select Category</option>
                    <option value="pothole">Pothole</option>
                    <option value="garbage">Garbage dump</option>
                    <option value="streetlight">Broken streetlight</option>
                    <option value="waterlogging">Waterlogging</option>
                    <option value="road_damage">Road damage</option>
                    <option value="encroachment">Encroachment</option>
                    <option value="graffiti">Graffiti</option>
                    <option value="open_drain">Open drainage</option>
                    <option value="fallen_tree">Fallen tree</option>
                    <option value="other">Other</option>
                  </select>
                </div>

                <div class="mb-4">
                  <label for="location" class="form-label fw-semibold">Location <span class="text-danger">*</span></label>
                  <div class="input-group">
                    <input type="text" id="location" name="issueLocation" class="form-control bg-light" placeholder="Fetching your city..." readonly required aria-describedby="locationMeta">
                    <button type="button" class="btn btn-secondary" id="refreshGpsBtn"><i class="fas fa-map-marker-alt"></i></button>
                  </div>
                  <div id="locationMeta" class="location-meta small text-muted mt-2"><i class="fas fa-lock me-1"></i>Coordinates stay hidden and are used only to place the report.</div>
                </div>

                <div class="mb-4">
                  <label for="description" class="form-label fw-semibold">Additional Details <span class="text-primary fw-normal">(Optional)</span></label>
                  <textarea id="description" name="issueDescription" class="form-control" rows="3" placeholder="Provide extra context..."></textarea>
                </div>

                <div class="mt-4">
                  <button type="submit" class="btn btn-primary w-100 btn-lg fw-semibold shadow-sm" name="citizenReportIssueBtn">Submit Report</button>
                </div>
              </form>
            </div>
          </div>
        </div>
      </div>
    </div>
  </main>

  <div class="toast-container position-fixed bottom-0 end-0 p-3">
    <div id="statusToast" class="toast align-items-center border-0" role="alert" aria-live="assertive" aria-atomic="true">
      <div class="d-flex">
        <div class="toast-body fw-medium" id="toastMessage"></div>
        <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
      </div>
    </div>
  </div>

  <?php require_once __DIR__ . '/../../components/footer.php'; ?>
  <script type="text/javascript">
    if ('serviceWorker' in navigator) {
      navigator.serviceWorker.register('../../sw.js', {scope: '../../'}).catch(() => {});
    }

    function showToast(message, type = 'info') {
      const toast = document.getElementById('statusToast');
      document.getElementById('toastMessage').textContent = message;
      toast.className = `toast align-items-center border-0 text-bg-${type}`;
      bootstrap.Toast.getOrCreateInstance(toast, { delay: 6000 }).show();
    }

    async function fetchAddress(lat, lng) {
      try {
        const response = await fetch(`https://nominatim.openstreetmap.org/reverse?format=json&lat=${lat}&lon=${lng}`);
        const data = await response.json();
        const address = data.address || {};
        const locality = address.city || address.town || address.municipality || address.village || address.state_district;
        const region = address.state || address.country;
        return [locality, region].filter(Boolean).join(', ') || 'Current location detected';
      } catch (err) {
        return 'Current location detected';
      }
    }

    async function fetchGPS() {
      return new Promise((resolve) => {
        if (!navigator.geolocation) {
          showToast("Location unsupported by this browser.", "danger");
          return resolve({ lat: null, lng: null });
        }
        navigator.geolocation.getCurrentPosition(
          pos => resolve({ lat: pos.coords.latitude, lng: pos.coords.longitude, accuracy: pos.coords.accuracy }),
          err => { showToast("Failed to acquire GPS.", "danger"); resolve({ lat: null, lng: null }); },
          { timeout: 15000, enableHighAccuracy: true }
        );
      });
    }

    async function initLocation() {
      const locInput = document.getElementById('location');
      const locationMeta = document.getElementById('locationMeta');
      locInput.value = "Acquiring GPS...";
      locationMeta.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Requesting permission from your browser...';
      const pos = await fetchGPS();
      if (pos.lat !== null) {
        locInput.value = "Fetching address...";
        const address = await fetchAddress(pos.lat, pos.lng);
        locInput.value = address;
        locationMeta.innerHTML = `<i class="fas fa-location-crosshairs me-1 text-primary"></i>GPS detected${pos.accuracy ? ` · accuracy about ${Math.round(pos.accuracy)} m` : ''}`;
        document.getElementById('latitude').value = pos.lat;
        document.getElementById('longitude').value = pos.lng;
        document.getElementById('gpsAccuracy').value = pos.accuracy;
      } else {
        locInput.value = "";
        locInput.placeholder = "Enable GPS to submit a report";
        locationMeta.innerHTML = '<i class="fas fa-triangle-exclamation me-1 text-warning"></i>Allow location access to continue.';
      }
    }

    document.getElementById('refreshGpsBtn').addEventListener('click', initLocation);
    window.addEventListener('DOMContentLoaded', initLocation);

    let analysisRequestId = 0;

    function renderAiResult(ai) {
      if (!ai || typeof ai.category !== 'string') {
        throw new Error('The server did not return an AI analysis.');
      }

      const categoryLabel = ai.category
        .replace(/_/g, ' ')
        .replace(/\b\w/g, char => char.toUpperCase());
      const confidenceValue = Math.max(0, Math.min(1, Number(ai.confidence)));
      const confidence = Math.round(confidenceValue * 100);
      const severity = Number(ai.severity);
      const categorySelect = document.getElementById('category');
      const matchingOption = Array.from(categorySelect.options).find(option =>
        option.value.toLowerCase() === ai.category.toLowerCase()
      );

      if (!categorySelect.value && matchingOption) {
        categorySelect.value = matchingOption.value;
      }

      document.getElementById('aiResultCard').classList.remove('d-none');
      document.getElementById('aiSkeleton').classList.add('d-none');
      document.getElementById('aiContent').classList.remove('d-none');
      document.getElementById('severityCategory').textContent = categoryLabel;
      document.getElementById('severityDisplay').textContent = `${severity}/5`;
      document.getElementById('aiConfidence').textContent = `${confidence}% Match`;
    }

    document.getElementById('removeImageBtn').addEventListener('click', () => {
      analysisRequestId += 1;
      document.getElementById('issueImage').value = '';
      document.getElementById('imagePreviewContainer').classList.replace('d-flex', 'd-none');
      document.getElementById('aiResultCard').classList.add('d-none');
    });

    document.getElementById('issueImage').addEventListener('change', async (e) => {
      const file = e.target.files[0];
      if (!file) return;
      const requestId = ++analysisRequestId;
      document.getElementById('previewImg').src = URL.createObjectURL(file);
      document.getElementById('previewName').textContent = file.name;
      document.getElementById('imagePreviewContainer').classList.replace('d-none', 'd-flex');
      document.getElementById('aiResultCard').classList.remove('d-none');
      document.getElementById('aiSkeleton').classList.remove('d-none');
      document.getElementById('aiContent').classList.add('d-none');

      const previewData = new FormData();
      previewData.append('csrf_token', document.querySelector('input[name="csrf_token"]').value);
      previewData.append('issueImage', file, file.name);
      previewData.append('issueCategory', document.getElementById('category').value);
      previewData.append('latitude', document.getElementById('latitude').value);
      previewData.append('longitude', document.getElementById('longitude').value);

      try {
        const response = await fetch('../../api/issues/analyze.php', {
          method: 'POST',
          body: previewData
        });
        const result = await response.json().catch(() => ({}));
        if (requestId !== analysisRequestId) return;
        if (!response.ok) {
          throw new Error(result.message || 'The image could not be analyzed.');
        }
        renderAiResult(result.data && result.data.ai);
      } catch (error) {
        if (requestId !== analysisRequestId) return;
        document.getElementById('aiResultCard').classList.add('d-none');
        showToast(error.message || 'AI analysis failed.', 'danger');
      }
    });

document.getElementById('issueReportForm').addEventListener('submit', async (e) => {
      e.preventDefault();
      const form = e.target;
      const btn = form.querySelector('button[type="submit"]');
      if (!document.getElementById('latitude').value) return showToast('Location permission is required.', 'danger');

      btn.disabled = true;
      btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Analyzing & saving...';

      try {
        const response = await fetch('../../api/issues/submit.php', { method: 'POST', body: new FormData(form) });
        const result = await response.json();

        if (!response.ok) {
          throw new Error(result.message || 'The report could not be saved.');
        }

        const ai = result.data && result.data.ai;
        if (!ai || typeof ai.category !== 'string') {
          throw new Error('The server did not return an AI analysis.');
        }

        renderAiResult(ai);

        {
          btn.innerHTML = '<i class="fas fa-check me-2"></i>Saved — redirecting';
          // A simple redirect. The API has already queued the toast in the PHP session.
          window.setTimeout(() => {
            window.location.href = '../citizen/citizen-dashboard.php';
          }, 900);
        }
      } catch (error) {
        showToast(error.message || 'Server connection failed.', 'danger');
        btn.disabled = false;
        btn.textContent = 'Submit Report';
      }
    });
  </script>
</body>
</html>
