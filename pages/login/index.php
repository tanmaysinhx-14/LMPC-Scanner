<?php // Bootstrapper + Backend Configuration
  require __DIR__ . '/../../bootstrap.php';

  require __DIR__ . '/../../functions/worker/login.php';
?>

<?php // Header (contains Unified Page Meta-Data and CSS imports)
  require_once '../../components/header.php';
?>

<body class="d-flex flex-column min-vh-100">
  <nav class="navbar navbar-expand-lg sticky-top bg-body border-bottom shadow-sm">
    <div class="container-fluid px-4">
      <a href="../../index.html" class="navbar-brand d-flex align-items-center gap-2 fw-bold text-primary">
        <span class="d-inline-flex align-items-center justify-content-center rounded-3 text-white bg-primary" style="width:36px;height:36px;">
          <i class="fas fa-city"></i>
        </span>
        CivicConnect
      </a>
      <div class="d-flex align-items-center gap-2">
        <button id="themeToggleBtn" class="btn btn-link text-body p-2 rounded-circle border-0" aria-label="Toggle theme">
          <i class="fas fa-moon fs-5" id="themeIcon"></i>
        </button>
        <a href="../register/index.php" class="btn btn-primary rounded-pill px-4">
          <i class="fas fa-user-plus me-2"></i>
          Create Account
        </a>
      </div>
    </div>
  </nav>

  <section class="min-vh-100 d-flex align-items-center py-5">
    <div class="container">
      <div class="row justify-content-center">
        <div class="col-12 col-md-8 col-lg-6 col-xl-5">
          <div class="card shadow-lg border-0 rounded-4">
            <div class="card-body p-4 p-md-5">
              
              <div class="text-center mb-4">
                <div class="bg-primary bg-opacity-10 text-primary rounded-circle d-inline-flex align-items-center justify-content-center mb-3" style="width: 64px; height: 64px; font-size: 24px;">
                  <i class="fas fa-sign-in-alt"></i>
                </div>
                <h1 class="h3 fw-bold">Welcome Back</h1>
                <p class="text-muted">Sign in to your CivicConnect account</p>
              </div>

              <?php if (!empty($successMessage)): ?>
                <div class="alert alert-success d-flex align-items-center gap-2" role="alert">
                  <i class="fas fa-check-circle"></i>
                  <div><?php echo $successMessage; ?></div>
                </div>
              <?php endif; ?>

              <?php if (!empty($error)): ?>
                <div class="alert alert-danger d-flex align-items-center gap-2" role="alert">
                  <i class="fas fa-exclamation-circle"></i>
                  <div><?php echo htmlspecialchars($error); ?></div>
                </div>
              <?php endif; ?>

              <form method="POST" action="./index.php">
                <div class="mb-3">
                  <label for="loginRole" class="form-label fw-medium">Login As <span class="text-danger">*</span></label>
                  <div class="input-group">
                    <span class="input-group-text bg-body border-end-0 text-muted"><i class="fas fa-user-tag"></i></span>
                    <select id="loginRole" name="role" class="form-select border-start-0 ps-0" required>
                      <option value="">Select your role...</option>
                      <option value="citizen" <?php echo ($role === 'citizen' || (isset($_POST['role']) && $_POST['role'] === 'citizen')) ? 'selected' : ''; ?>>Citizen</option>
                      <option value="authority" <?php echo ($role === 'authority' || (isset($_POST['role']) && $_POST['role'] === 'authority')) ? 'selected' : ''; ?>>Municipal Authority</option>
                      <option value="admin" <?php echo ($role === 'admin' || (isset($_POST['role']) && $_POST['role'] === 'admin')) ? 'selected' : ''; ?>>Administrator</option>
                    </select>
                  </div>
                </div>

                <div class="mb-3">
                  <label for="loginEmail" class="form-label fw-medium">Email Address <span class="text-danger">*</span></label>
                  <div class="input-group">
                    <span class="input-group-text bg-body border-end-0 text-muted"><i class="fas fa-envelope"></i></span>
                    <input id="loginEmail" name="email" type="email" class="form-control border-start-0 ps-0" placeholder="Enter your email address" required autocomplete="email" value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : htmlspecialchars($email ?? ''); ?>">
                  </div>
                </div>

                <div class="mb-3">
                  <label for="loginPassword" class="form-label fw-medium">Password <span class="text-danger">*</span></label>
                  <div class="input-group">
                    <span class="input-group-text bg-body border-end-0 text-muted"><i class="fas fa-lock"></i></span>
                    <input id="loginPassword" name="password" type="password" class="form-control border-start-0 border-end-0 ps-0" placeholder="Enter your password" required minlength="8" autocomplete="current-password">
                    <button type="button" class="btn btn-outline-secondary border-start-0 text-muted" onclick="togglePassword()">
                      <i class="fas fa-eye" id="passwordIcon"></i>
                    </button>
                  </div>
                </div>

                <div class="d-flex justify-content-between align-items-center mb-4">
                  <div class="form-check">
                    <input id="rememberMe" name="rememberMe" class="form-check-input" type="checkbox" <?php echo isset($_POST['rememberMe']) ? 'checked' : ''; ?>>
                    <label for="rememberMe" class="form-check-label text-muted">Remember me</label>
                  </div>
                  <a href="forgot-password.html" class="text-primary text-decoration-none fw-medium">Forgot password?</a>
                </div>

                <button type="submit" name="loginSubmit" class="btn btn-primary w-100 py-2 mb-3 fw-medium">
                  <i class="fas fa-sign-in-alt me-2"></i>Sign In
                </button>

                <div class="d-flex align-items-center mb-3">
                  <hr class="flex-grow-1">
                  <span class="px-3 text-muted text-uppercase small fw-medium">or continue with</span>
                  <hr class="flex-grow-1">
                </div>

                <button type="button" class="btn btn-outline-secondary w-100 py-2 mb-4 fw-medium">
                  <i class="fab fa-google me-2 text-danger"></i>Sign in with Google
                </button>

                <div class="text-center pt-3 border-top">
                  <p class="text-secondary mb-0">
                    Don't have an account?
                    <a href="../register/index.php" class="text-primary fw-semibold text-decoration-none">
                      Create one now <i class="fas fa-arrow-right ms-1"></i>
                    </a>
                  </p>
                </div>
              </form>

            </div>
          </div>
        </div>
      </div>
    </div>
  </section>

  <?php // Contains Bottom-Credits and JS imports
    require_once '../../components/bottom-credits.php';
    require_once '../../components/footer.php';
  ?>

  <script src="../../assets/js/index.js" type="text/javascript"></script>
  <script type="text/javascript"> // Login Exclusive JS
    function togglePassword() {
      const passwordInput = document.getElementById('loginPassword');
      const passwordIcon = document.getElementById('passwordIcon');
      if (passwordInput.type === 'password') {
        passwordInput.type = 'text';
        passwordIcon.className = 'fas fa-eye-slash';
      } else {
        passwordInput.type = 'password';
        passwordIcon.className = 'fas fa-eye';
      }
    }

    document.addEventListener('DOMContentLoaded', function() {
      const passwordInput = document.getElementById('loginPassword');
      if (passwordInput) {
        passwordInput.addEventListener('keypress', function(e) {
          if (e.key === 'Enter') {
            document.querySelector('button[type="submit"]').click();
          }
        });
      }
    });

    document.addEventListener('DOMContentLoaded', function() {
      const emailInput = document.getElementById('loginEmail');
      if (emailInput) {
        emailInput.addEventListener('blur', function() {
          const email = this.value;
          if (email) {
          }
        });
      }
    });
  </script>
</body>
</html>