<?php // Backend for Registration
  require_once __DIR__ . '/../../functions/database/database.php';
  require_once __DIR__ . '/../../functions/validations/validations.php';
  require_once __DIR__ . '/../../functions/utility/response.php';

  $pdo = connectDatabase();

  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullName = sanitizeInput($_POST['fullName'] ?? '');
    $email = sanitizeInput($_POST['email'] ?? '');
    $phone = sanitizeInput($_POST['phone'] ?? '');
    $password = sanitizeInput($_POST['password'] ?? '');
    $confirmPassword = sanitizeInput($_POST['confirmPassword'] ?? '');
    $role = sanitizeInput($_POST['userRole'] ?? '');
    $termsAccepted = isset($_POST['termsAccepted']);

    $isRegistrationFormValidated = true; 

    if (empty($fullName) || empty($email) || empty($phone) || empty($password) || empty($confirmPassword) || empty($role)) {
      $isRegistrationFormValidated = false;
      $error = "Please fill in all required fields.";
    } elseif (!validateEmail($email)) {
      $isRegistrationFormValidated = false;
      $error = "Please enter a valid email address.";
    } elseif (!validatePassword($password)) {
      $isRegistrationFormValidated = false;
      $error = "Password must be at least 8 characters long, contain at least one number, and one symbol.";
    } elseif ($password !== $confirmPassword) {
      $isRegistrationFormValidated = false;
      $error = "Passwords do not match!";
    } elseif (!$termsAccepted) {
      $isRegistrationFormValidated = false;
      $error = "Please accept the Terms of Service and Privacy Policy.";
    } else {
      // Check if email already exists
      $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
      $stmt->execute([$email]);
      if ($stmt->fetch()) {
        $isRegistrationFormValidated = false;
        $error = "This email is already registered. Please use a different email or login.";
      }
    }

    if ($isRegistrationFormValidated) {
      // Hash the password
      $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
      
      // Insert user into database
      $stmt = $pdo->prepare("
        INSERT INTO users (full_name, email, phone, password_hash, role, created_at) 
        VALUES (?, ?, ?, ?, ?, NOW())
      ");
      
      try {
        $stmt->execute([$fullName, $email, $phone, $hashedPassword, $role]);
        header("Location: ../login/index.php?success=Account created successfully! Please login with your credentials.");
        exit();
      } catch (PDOException $e) {
        $error = "Registration failed. Please try again.";
      }
    }
  }
?>


<!DOCTYPE html>
<html lang="en" data-theme="light">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="description" content="Create your CivicConnect account - Report & Track Civic Issues">
  <meta name="theme-color" content="#4F46E5">

  <title>CivicConnect - Create Account</title>

  <!-- Google Fonts -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

  <!-- Bootstrap 5 CSS -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">

  <!-- Font Awesome Icons -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

  <!-- Custom Styles -->
  <link rel="stylesheet" href="../../assets/css/style.css">
</head>

<body>

  <!-- ============================================
    REGISTRATION PAGE - CivicConnect
    ============================================ -->

  <!-- ===== NAVBAR ===== -->
  <nav class="navbar navbar-expand-lg sticky-top bg-white bg-opacity-80 backdrop-blur border-bottom">
    <div class="container-fluid px-4">
      <a href="../../index.html" class="navbar-brand d-flex align-items-center gap-2 fw-bold text-primary">
        <span class="d-inline-flex align-items-center justify-content-center rounded-3 text-white" style="width:36px;height:36px;background:var(--color-primary-gradient);">
          <i class="fas fa-city"></i>
        </span>
        CivicConnect
      </a>
      <div class="d-flex align-items-center gap-2">
        <button id="themeToggleBtn" class="btn btn-link text-secondary p-2 rounded-circle border-0" aria-label="Toggle theme">
          <i class="fas fa-moon fs-5" id="themeIcon"></i>
        </button>
        <a href="../login/index.php" class="btn btn-secondary rounded-pill px-4">
          <i class="fas fa-sign-in-alt me-2"></i>
          Sign In
        </a>
      </div>
    </div>
  </nav>

  <!-- ===== REGISTRATION SECTION ===== -->
  <section class="auth-section">
    <div class="container">
      <div class="auth-container">
        <div class="auth-card">
          <!-- Header -->
          <div class="auth-header text-center">
            <div class="auth-icon-wrapper">
              <i class="fas fa-user-plus"></i>
            </div>
            <h1 class="auth-title">Create Account</h1>
            <p class="auth-subtitle">Join CivicConnect and make your city better</p>
          </div>

          <!-- Error Messages -->
          <?php if (isset($error)): ?>
            <div class="alert alert-danger d-flex align-items-center gap-2">
              <i class="fas fa-exclamation-circle"></i>
              <?php echo htmlspecialchars($error); ?>
            </div>
          <?php endif; ?>

          <!-- Registration Form -->
          <form method="POST" action="./index.php" novalidate>
            <!-- Full Name -->
            <div class="mb-3">
              <label for="registerFullName" class="form-label fw-medium">
                Full Name <span class="text-danger">*</span>
              </label>
              <div class="form-input-wrapper">
                <span class="input-icon"><i class="fas fa-user"></i></span>
                <input
                  id="registerFullName"
                  name="fullName"
                  type="text"
                  class="form-control"
                  placeholder="Enter your full name"
                  required
                  autocomplete="name"
                  value="<?php echo isset($_POST['fullName']) ? htmlspecialchars($_POST['fullName']) : ''; ?>">
              </div>
              <div class="form-text text-muted">Your full name as it appears on official documents</div>
            </div>

            <!-- Email -->
            <div class="mb-3">
              <label for="registerEmail" class="form-label fw-medium">
                Email Address <span class="text-danger">*</span>
              </label>
              <div class="form-input-wrapper">
                <span class="input-icon"><i class="fas fa-envelope"></i></span>
                <input
                  id="registerEmail"
                  name="email"
                  type="email"
                  class="form-control"
                  placeholder="Enter your email address"
                  required
                  autocomplete="email"
                  value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
              </div>
              <div class="form-text text-muted">We'll send a verification email to this address</div>
            </div>

            <!-- Phone -->
            <div class="mb-3">
              <label for="registerPhone" class="form-label fw-medium">
                Phone Number <span class="text-danger">*</span>
              </label>
              <div class="form-input-wrapper">
                <span class="input-icon"><i class="fas fa-phone"></i></span>
                <input
                  id="registerPhone"
                  name="phone"
                  type="tel"
                  class="form-control"
                  placeholder="Enter your phone number"
                  required
                  autocomplete="tel"
                  value="<?php echo isset($_POST['phone']) ? htmlspecialchars($_POST['phone']) : ''; ?>">
              </div>
              <div class="form-text text-muted">We'll use this for important updates</div>
            </div>

            <!-- Password -->
            <div class="mb-3">
              <label for="registerPassword" class="form-label fw-medium">
                Password <span class="text-danger">*</span>
              </label>
              <div class="form-input-wrapper">
                <span class="input-icon"><i class="fas fa-lock"></i></span>
                <input
                  id="registerPassword"
                  name="password"
                  type="password"
                  class="form-control"
                  placeholder="Create a strong password"
                  required
                  minlength="8"
                  autocomplete="new-password">
                <button type="button" class="password-toggle" onclick="togglePassword()">
                  <i class="fas fa-eye" id="passwordIcon"></i>
                </button>
              </div>
              <div class="form-text text-muted">Must be at least 8 characters with numbers and special characters</div>
            </div>

            <!-- Confirm Password -->
            <div class="mb-3">
              <label for="registerConfirmPassword" class="form-label fw-medium">
                Confirm Password <span class="text-danger">*</span>
              </label>
              <div class="form-input-wrapper">
                <span class="input-icon"><i class="fas fa-check-circle"></i></span>
                <input
                  id="registerConfirmPassword"
                  name="confirmPassword"
                  type="password"
                  class="form-control"
                  placeholder="Confirm your password"
                  required
                  autocomplete="new-password">
              </div>
              <div id="confirmHelp" class="form-text text-muted">Passwords must match</div>
            </div>

            <!-- Role Selection -->
            <div class="mb-3">
              <label for="userRole" class="form-label fw-medium">
                I am a <span class="text-danger">*</span>
              </label>
              <div class="form-input-wrapper">
                <span class="input-icon"><i class="fas fa-user-tag"></i></span>
                <select id="userRole" name="userRole" class="form-select" required style="padding-left: 44px; height: 48px;">
                  <option value="">Select your role...</option>
                  <option value="citizen" <?php echo (isset($_POST['userRole']) && $_POST['userRole'] === 'citizen') ? 'selected' : ''; ?>>Citizen</option>
                  <option value="authority" <?php echo (isset($_POST['userRole']) && $_POST['userRole'] === 'authority') ? 'selected' : ''; ?>>Municipal Authority</option>
                  <option value="admin" <?php echo (isset($_POST['userRole']) && $_POST['userRole'] === 'admin') ? 'selected' : ''; ?>>Administrator</option>
                </select>
              </div>
              <div class="form-text text-muted">Select the role that best describes you</div>
            </div>

            <!-- Terms -->
            <div class="mb-3">
              <div class="form-check">
                <input
                  id="termsCheckbox"
                  name="termsAccepted"
                  class="form-check-input"
                  type="checkbox"
                  <?php echo isset($_POST['termsAccepted']) ? 'checked' : ''; ?>
                  required>
                <label for="termsCheckbox" class="form-check-label">
                  I agree to the
                  <a href="#" class="text-primary fw-medium">Terms of Service</a>
                  and
                  <a href="#" class="text-primary fw-medium">Privacy Policy</a>
                  <span class="text-danger">*</span>
                </label>
              </div>
            </div>

            <!-- Submit -->
            <button type="submit" name="registerSubmit" class="btn btn-primary w-100 auth-submit-btn">
              <i class="fas fa-user-plus me-2"></i>
              Create Account
            </button>

            <!-- Divider -->
            <div class="d-flex align-items-center my-3">
              <hr class="flex-grow-1">
              <span class="px-3 text-muted text-uppercase small fw-medium">or continue with</span>
              <hr class="flex-grow-1">
            </div>

            <!-- Social -->
            <button type="button" class="btn btn-outline-secondary w-100 social-btn">
              <i class="fab fa-google me-2"></i>
              Sign up with Google
            </button>

            <!-- Login Link -->
            <div class="text-center mt-3 pt-3 border-top">
              <p class="text-secondary mb-0">
                Already have an account?
                <a href="../login/index.php" class="text-primary fw-semibold text-decoration-none">
                  Sign in here <i class="fas fa-arrow-right ms-1"></i>
                </a>
              </p>
            </div>
          </form>
        </div>
      </div>
    </div>
  </section>

  <!-- ===== FOOTER ===== -->
  <footer class="py-3 border-top">
    <div class="container text-center">
      <p class="text-muted mb-0 small">&copy; 2026 CivicConnect. All rights reserved.</p>
    </div>
  </footer>

  <!-- ============================================
    SCRIPTS
    ============================================ -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    // Theme Toggle
    document.addEventListener('DOMContentLoaded', function() {
      const themeToggle = document.getElementById('themeToggleBtn');
      const themeIcon = document.getElementById('themeIcon');
      const html = document.documentElement;

      if (!themeToggle || !themeIcon) return;

      const savedTheme = localStorage.getItem('theme');
      if (savedTheme) {
        html.setAttribute('data-theme', savedTheme);
        themeIcon.className = savedTheme === 'dark' ? 'fas fa-sun' : 'fas fa-moon';
      }

      themeToggle.addEventListener('click', function() {
        const currentTheme = html.getAttribute('data-theme');
        const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
        html.setAttribute('data-theme', newTheme);
        localStorage.setItem('theme', newTheme);
        themeIcon.className = newTheme === 'dark' ? 'fas fa-sun' : 'fas fa-moon';
      });
    });

    // Password Visibility Toggle
    function togglePassword() {
      const passwordInput = document.getElementById('registerPassword');
      const passwordIcon = document.getElementById('passwordIcon');
      if (passwordInput.type === 'password') {
        passwordInput.type = 'text';
        passwordIcon.className = 'fas fa-eye-slash';
      } else {
        passwordInput.type = 'password';
        passwordIcon.className = 'fas fa-eye';
      }
    }

    // Password Confirmation Validation
    document.addEventListener('DOMContentLoaded', function() {
      const passwordInput = document.getElementById('registerPassword');
      const confirmInput = document.getElementById('registerConfirmPassword');
      const helpText = document.getElementById('confirmHelp');

      if (confirmInput) {
        confirmInput.addEventListener('input', function() {
          const password = passwordInput.value;
          const confirmPassword = this.value;

          if (confirmPassword && password !== confirmPassword) {
            this.classList.add('is-invalid');
            this.classList.remove('is-valid');
            helpText.textContent = 'Passwords do not match!';
            helpText.className = 'form-text text-danger';
          } else if (confirmPassword && password === confirmPassword) {
            this.classList.remove('is-invalid');
            this.classList.add('is-valid');
            helpText.textContent = 'Passwords match! ✓';
            helpText.className = 'form-text text-success';
          } else {
            this.classList.remove('is-invalid', 'is-valid');
            helpText.textContent = 'Passwords must match';
            helpText.className = 'form-text text-muted';
          }
        });
      }
    });

    // Enter key support
    document.addEventListener('DOMContentLoaded', function() {
      const confirmInput = document.getElementById('registerConfirmPassword');
      if (confirmInput) {
        confirmInput.addEventListener('keypress', function(e) {
          if (e.key === 'Enter') {
            document.querySelector('button[type="submit"]').click();
          }
        });
      }
    });
  </script>
</body>

</html>