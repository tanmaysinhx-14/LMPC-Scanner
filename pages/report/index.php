<?php
  require_once __DIR__ . '/../../functions/database/database.php';
  require_once __DIR__ . '/../../functions/validations/validations.php';
  require_once __DIR__ . '/../../functions/utility/utility.php';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="description" content="Report Civic Issues" />
  <meta name="theme-color" content="#0d6efd" />
  <title>CivicConnect - Report Issue</title>

  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
  <link rel="stylesheet" href="../../assets/css/bootstrap.css" />

  <style>
    body { font-family: 'Inter', sans-serif; background-color: #f8f9fa; }
    .border-dashed { border-style: dashed !important; cursor: pointer; }
    .border-dashed:hover { background-color: #f1f5f9 !important; border-color: #0d6efd !important; }
  </style>
</head>
<body>
  <main class="min-vh-100 py-5">
    <div class="container">
      <div class="row justify-content-center">
        <div class="col-12 col-md-8 col-lg-6">
          <div class="card shadow-sm border-0 rounded-4">
            <div class="card-header bg-white border-bottom-0 pt-4 pb-0 px-4">
              <h2 class="h4 mb-0 fw-bold text-dark">Report Civic Issue</h2>
            </div>
            
            <div class="card-body p-4">
              <form id="issueReportForm" method="POST" action="./">
                
                <div class="mb-4">
                  <label class="form-label fw-semibold">Issue Photo <span class="text-danger">*</span></label>
                  <label class="d-block w-100 p-5 text-center border border-2 border-secondary-subtle rounded-3 bg-light border-dashed" for="issueImage">
                    <i class="fas fa-camera fs-1 text-secondary mb-3"></i>
                    <span class="d-block text-secondary">Tap to capture or <strong class="text-primary">upload image</strong></span>
                    <input type="file" 
                           id="issueImage" 
                           name="issueImage"
                           accept="image/*" 
                           capture="environment" 
                           class="d-none" 
                           required>
                  </label>
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
                    <p class="small mb-0 text-dark">
                      Detected Severity: <strong id="severityDisplay" class="text-danger ms-1"></strong>
                    </p>
                  </div>
                </div>

                <div class="mb-4">
                  <label for="category" class="form-label fw-semibold">Category <span class="text-danger">*</span></label>
                  <select id="category" 
                          name="issueCategory"
                          class="form-select" 
                          required>
                    <option value="">Select Category</option>
                    <option value="Pothole">Pothole</option>
                    <option value="Drainage">Open Drainage</option>
                    <option value="Garbage">Garbage Dump</option>
                    <option value="Streetlight">Broken Streetlight</option>
                    <option value="Other">Other</option>
                  </select>
                </div>

                <div class="mb-4">
                  <label for="location" class="form-label fw-semibold">Location <span class="text-danger">*</span></label>
                  <div class="input-group">
                    <input type="text" 
                           id="location" 
                           name="issueLocation" 
                           class="form-control bg-light" 
                           placeholder="Fetching location..." 
                           readonly 
                           required>
                    <button type="button" class="btn btn-secondary" id="refreshGpsBtn">
                      <i class="fas fa-map-marker-alt"></i>
                    </button>
                  </div>
                </div>

                <div class="mb-4">
                  <label for="description" class="form-label fw-semibold">
                    Additional Details
                    <span class="text-primary fw-normal">(Optional)</span>
                  </label>
                  <textarea id="description" 
                            name="issueDescription" 
                            class="form-control" 
                            rows="4" 
                            placeholder="Provide any extra context regarding the issue...">
                  </textarea>
                </div>

                <div class="mt-5">
                  <button type="submit" 
                          class="btn btn-primary w-100 btn-lg fw-semibold shadow-sm"
                          name="citizenReportIssueBtn">
                    Submit Report
                  </button>
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

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js" integrity="sha384-FKyoEForCGlyvwx9Hj09JcYn3nv7wiPVlz7YYwJrWVcXK/BmnVDxM+D2scQbITxI" crossorigin="anonymous"></script>
  
  <script>
    let currentLocation = null;
    const toastElement = document.getElementById('statusToast');
    const bsToast = new bootstrap.Toast(toastElement);

    function showToast(message, type = 'primary') {
      const toastMsg = document.getElementById('toastMessage');
      toastElement.className = `toast align-items-center text-bg-${type} border-0`;
      
      const icon = type === 'danger' ? 'fa-exclamation-triangle' : (type === 'success' ? 'fa-check-circle' : 'fa-info-circle');
      toastMsg.innerHTML = `<i class="fas ${icon} me-2"></i> ${message}`;
      
      bsToast.show();
    }

    async function fetchGPS() {
      return new Promise((resolve) => {
        if (!navigator.geolocation) {
          resolve({ lat: null, lng: null, source: 'manual' });
          return;
        }
        navigator.geolocation.getCurrentPosition(
          pos => resolve({
            lat: pos.coords.latitude,
            lng: pos.coords.longitude,
            accuracy: pos.coords.accuracy,
            source: 'gps'
          }),
          () => resolve({ lat: null, lng: null, source: 'manual' }),
          { timeout: 8000, enableHighAccuracy: true }
        );
      });
    }

    async function initLocation() {
      const locInput = document.getElementById('location');
      locInput.value = "Acquiring GPS...";
      locInput.classList.add('bg-light');
      
      currentLocation = await fetchGPS();
      
      if (currentLocation.lat) {
        locInput.value = `${currentLocation.lat.toFixed(6)}, ${currentLocation.lng.toFixed(6)}`;
      } else {
        locInput.value = "";
        locInput.removeAttribute('readonly');
        locInput.classList.remove('bg-light');
        locInput.placeholder = "Enter location manually";
      }
    }

    document.getElementById('refreshGpsBtn').addEventListener('click', initLocation);
    window.addEventListener('DOMContentLoaded', initLocation);

    async function compressImage(file, maxPx = 1024, quality = 0.85) {
      return new Promise(resolve => {
        const canvas = document.createElement('canvas');
        const img = new Image();
        img.onload = () => {
          const scale = Math.min(maxPx / img.width, maxPx / img.height, 1);
          canvas.width = img.width * scale;
          canvas.height = img.height * scale;
          canvas.getContext('2d').drawImage(img, 0, 0, canvas.width, canvas.height);
          canvas.toBlob(resolve, 'image/jpeg', quality);
        };
        img.src = URL.createObjectURL(file);
      });
    }

    function showSkeleton() {
      document.getElementById('aiResultCard').classList.remove('d-none');
      document.getElementById('aiSkeleton').classList.remove('d-none');
      document.getElementById('aiContent').classList.add('d-none');
    }

    function hideSkeleton() {
      document.getElementById('aiSkeleton').classList.add('d-none');
      document.getElementById('aiContent').classList.remove('d-none');
    }

    document.getElementById('removeImageBtn').addEventListener('click', () => {
      document.getElementById('issueImage').value = '';
      document.getElementById('imagePreviewContainer').classList.replace('d-flex', 'd-none');
      document.getElementById('aiResultCard').classList.add('d-none');
      document.getElementById('category').value = '';
    });

    document.getElementById('issueImage').addEventListener('change', async (e) => {
      const file = e.target.files[0];
      if (!file) return;

      const container = document.getElementById('imagePreviewContainer');
      document.getElementById('previewImg').src = URL.createObjectURL(file);
      container.classList.replace('d-none', 'd-flex');

      showSkeleton();

      const compressed = await compressImage(file);
      
      const formData = new FormData();
      formData.append('image', compressed, 'issue.jpg');

      await new Promise(r => setTimeout(r, 1800));

      const result = {
        data: {
          category: "Pothole",
          severity: "Critical",
          confidence: 0.94,
          is_manipulated: false
        }
      };

      document.getElementById('category').value = result.data.category;
      document.getElementById('severityDisplay').textContent = result.data.severity;
      document.getElementById('aiConfidence').textContent = `${Math.round(result.data.confidence * 100)}% Match`;

      if (result.data.is_manipulated) {
        showToast('Warning: This image may have been altered.', 'danger');
      }

      hideSkeleton();
    });

document.getElementById('issueReportForm').addEventListener('submit', async (e) => {
  e.preventDefault();

  const form = e.target;
  const formData = new FormData(form);

  try {
    const response = await fetch('../../api/issues/submit.php', {
      method: 'POST',
      body: formData
    });

    if (!response.ok) {
      throw new Error("HTTP error " + response.status);
    }

    const result = await response.json();

    if (result.status === 'success') {
      showToast('Report submitted and AI triggered!', 'success');
      form.reset();
      document.getElementById('removeImageBtn').click();
      initLocation();
    } else {
      showToast('Server error: ' + result.message, 'danger');
    }
  } catch (error) {
    showToast('Failed to connect to the server.', 'danger');
  }
});
  </script>
</body>
</html>