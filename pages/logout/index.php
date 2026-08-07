<?php
  require __DIR__ . '/../../bootstrap.php';

  $bootstrapData = bootstrapAccounts(
    options: [
      'require_login' => true // Page accessible to logged out users only
    ]
  );
  
  extract($bootstrapData);
?>

<?php
  logoutUser($db instanceof PDO ? $db : null);
?>

<?php
  require_once __DIR__ . '/../../components/header.php';
?>

<body class="d-flex flex-column min-vh-100">
  <nav class="navbar navbar-expand-lg sticky-top bg-body border-bottom shadow-sm">
    <div class="container-fluid px-4">
      <a href="../../index.php" class="navbar-brand d-flex align-items-center gap-2 fw-bold text-primary">
        <span class="d-inline-flex align-items-center justify-content-center rounded-3 text-white bg-primary" style="width:36px;height:36px;">
          <i class="fas fa-city"></i>
        </span>
        CivicConnect
      </a>
      <div class="d-flex align-items-center gap-2">
        <button id="themeToggleBtn" class="btn btn-link text-body p-2 rounded-circle border-0" aria-label="Toggle theme">
          <i class="fas fa-moon fs-5" id="themeIcon"></i>
        </button>
      </div>
    </div>
  </nav>

  <section class="flex-grow-1 d-flex align-items-center py-5">
    <div class="container">
      <div class="row justify-content-center">
        <div class="col-12 col-md-8 col-lg-6 col-xl-5">
          <div class="card shadow-lg border-0 rounded-4 text-center">
            <div class="card-body p-4 p-md-5">
              
              <div class="mb-4">
                <div class="bg-success bg-opacity-10 text-success rounded-circle d-inline-flex align-items-center justify-content-center mb-3" style="width: 80px; height: 80px; font-size: 32px;">
                  <i class="fas fa-check"></i>
                </div>
                <h1 class="h3 fw-bold">Logged Out Successfully</h1>
                <p class="text-muted mt-3">You have been securely logged out of your CivicConnect account. Thank you for making your city better.</p>
              </div>

              <div class="d-grid gap-3 mt-4">
                <a href="../login/" class="btn btn-primary py-2 fw-medium">
                  <i class="fas fa-sign-in-alt me-2"></i>Sign In Again
                </a>
                <a href="../../" class="btn btn-outline-secondary py-2 fw-medium">
                  <i class="fas fa-home me-2"></i>Return to Homepage
                </a>
              </div>

            </div>
          </div>
        </div>
      </div>
    </div>
  </section>

  <?php
    require_once __DIR__ . '/../../components/bottom-credits.php';
    require_once __DIR__ . '/../../components/footer.php';
  ?>

  <script src="../../assets/js/index.js" type="text/javascript"></script>
</body>
</html>
