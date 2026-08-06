<?php // Backend for Login
  session_start();
  require_once __DIR__ . '/../../functions/database/database.php';
  require_once __DIR__ . '/../../functions/validations/validations.php';
  require_once __DIR__ . '/../../functions/utility/response.php';

  $pdo = connectDatabase();

  // Check for success message from registration
  $successMessage = '';
  if (isset($_GET['success'])) {
    $successMessage = htmlspecialchars($_GET['success']);
  }

  // Initialize variables
  $error = '';
  $email = '';
  $role = '';

  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = sanitizeInput($_POST['email'] ?? '');
    $password = sanitizeInput($_POST['password'] ?? '');
    $selectedRole = sanitizeInput($_POST['role'] ?? '');
    $rememberMe = isset($_POST['rememberMe']);

    // Validate inputs
    if (empty($email) || empty($password) || empty($selectedRole)) {
      $error = "Please fill in all required fields.";
    } elseif (!validateEmail($email)) {
      $error = "Please enter a valid email address.";
    } elseif (!validatePassword($password)) {
      $error = "Password must be at least 8 characters long, contain at least one number, and one symbol.";
    } else {
      // Check if user exists with this email
      $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
      $stmt->execute([$email]);
      $user = $stmt->fetch();

      if (!$user) {
        $error = "No account found with this email address.";
      } else {
        // Verify password (assuming password is hashed with password_hash)
        // For demo, we'll check plain text (in production, use password_verify)
        if ($password !== $user['password_hash'] && !password_verify($password, $user['password_hash'])) {
          $error = "Invalid password. Please try again.";
        } elseif ($user['role'] !== $selectedRole) {
          // Check if the selected role matches the user's actual role
          $error = "You are registered as a " . ucfirst($user['role']) . ". Please select the correct role.";
        } else {
          // Login successful - Set session variables
          $_SESSION['user_id'] = $user['id'];
          $_SESSION['user_name'] = $user['full_name'];
          $_SESSION['user_email'] = $user['email'];
          $_SESSION['user_role'] = $user['role'];
          $_SESSION['logged_in'] = true;

          // Set remember me cookie if checked
          if ($rememberMe) {
            setcookie('user_email', $email, time() + (86400 * 30), "/");
            setcookie('user_role', $user['role'], time() + (86400 * 30), "/");
          }

          // Redirect based on role
          switch ($user['role']) {
            case 'citizen':
              header("Location: ../citizen/dashboard.php");
              break;
            case 'authority':
              header("Location: ../authority/dashboard.php");
              break;
            case 'admin':
              header("Location: ../admin/dashboard.php");
              break;
            default:
              header("Location: ../citizen/dashboard.php");
          }
          exit();
        }
      }
    }
  }

  // Check for remember me cookie
  if (isset($_COOKIE['user_email']) && isset($_COOKIE['user_role'])) {
    $email = $_COOKIE['user_email'];
    $role = $_COOKIE['user_role'];
  }
?>

<!doctype html>
<html lang="en" data-theme="light">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="description" content="Sign in to CivicConnect - Report & Track Civic Issues" />
  <meta name="theme-color" content="#4F46E5" />

  <title>CivicConnect - Sign In</title>

  <!-- Google Fonts -->
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />

  <!-- Bootstrap 5 CSS -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />

  <!-- Font Awesome Icons -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />

  <!-- Custom Styles -->
  <link rel="stylesheet" href="../../assets/css/style.css" />
</head>

<body>
  <!-- ============================================
    LOGIN PAGE - CivicConnect
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
        <a href="../register/index.php" class="btn btn-primary rounded-pill px-4">
          <i class="fas fa-user-plus me-2"></i>
          Create Account
        </a>
      </div>
    </div>
  </nav>

  <!-- ===== LOGIN SECTION ===== -->
  <section class="auth-section">
    <div class="container">
      <div class="auth-container">
        <div class="auth-card">
          <!-- Header -->
          <div class="auth-header text-center">
            <div class="auth-icon-wrapper">
              <i class="fas fa-sign-in-alt"></i>
            </div>
            <h1 class="auth-title">Welcome Back</h1>
            <p class="auth-subtitle">Sign in to your CivicConnect account</p>
          </div>

          <!-- Success Message -->
          <?php if (!empty($successMessage)): ?>
            <div class="alert alert-success d-flex align-items-center gap-2">
              <i class="fas fa-check-circle"></i>
              <?php echo $successMessage; ?>
            </div>
          <?php endif; ?>

          <!-- Error Messages -->
          <?php if (!empty($error)): ?>
            <div class="alert alert-danger d-flex align-items-center gap-2">
              <i class="fas fa-exclamation-circle"></i>
              <?php echo htmlspecialchars($error); ?>
            </div>
          <?php endif; ?>

          <!-- Login Form -->
          <form method="POST" action="./index.php">
            <!-- Role Selection Dropdown -->
            <div class="mb-3">
              <label for="loginRole" class="form-label fw-medium">
                Login As <span class="text-danger">*</span>
              </label>
              <div class="form-input-wrapper">
                <span class="input-icon"><i class="fas fa-user-tag"></i></span>
                <select id="loginRole" name="role" class="form-select" required style="padding-left: 44px; height: 48px;">
                  <option value="">Select your role...</option>
                  <option value="citizen" <?php echo ($role === 'citizen' || (isset($_POST['role']) && $_POST['role'] === 'citizen')) ? 'selected' : ''; ?>>Citizen</option>
                  <option value="authority" <?php echo ($role === 'authority' || (isset($_POST['role']) && $_POST['role'] === 'authority')) ? 'selected' : ''; ?>>Municipal Authority</option>
                  <option value="admin" <?php echo ($role === 'admin' || (isset($_POST['role']) && $_POST['role'] === 'admin')) ? 'selected' : ''; ?>>Administrator</option>
                </select>
              </div>
              <div class="form-text text-muted">Select the role you want to login as</div>
            </div>

            <!-- Email -->
            <div class="mb-3">
              <label for="loginEmail" class="form-label fw-medium">
                Email Address <span class="text-danger">*</span>
              </label>
              <div class="form-input-wrapper">
                <span class="input-icon"><i class="fas fa-envelope"></i></span>
                <input
                  id="loginEmail"
                  name="email"
                  type="email"
                  class="form-control"
                  placeholder="Enter your email address"
                  required
                  autocomplete="email"
                  value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : htmlspecialchars($email); ?>">
              </div>
              <div class="form-text text-muted">We'll never share your email</div>
            </div>

            <!-- Password -->
            <div class="mb-3">
              <label for="loginPassword" class="form-label fw-medium">
                Password <span class="text-danger">*</span>
              </label>
              <div class="form-input-wrapper">
                <span class="input-icon"><i class="fas fa-lock"></i></span>
                <input
                  id="loginPassword"
                  name="password"
                  type="password"
                  class="form-control"
                  placeholder="Enter your password"
                  required
                  minlength="8"
                  autocomplete="current-password">
                <button type="button" class="password-toggle" onclick="togglePassword()">
                  <i class="fas fa-eye" id="passwordIcon"></i>
                </button>
              </div>
              <div class="form-text text-muted">Password must be at least 8 characters</div>
            </div>

            <!-- Remember Me & Forgot Password -->
            <div class="d-flex justify-content-between align-items-center mb-3">
              <div class="form-check">
                <input id="rememberMe" name="rememberMe" class="form-check-input" type="checkbox" <?php echo isset($_POST['rememberMe']) ? 'checked' : ''; ?>>
                <label for="rememberMe" class="form-check-label">Remember me</label>
              </div>
              <a href="forgot-password.html" class="text-primary text-decoration-none fw-medium">Forgot password?</a>
            </div>

            <!-- Submit Button -->
            <button type="submit" name="loginSubmit" class="btn btn-primary w-100 auth-submit-btn">
              <i class="fas fa-sign-in-alt me-2"></i>
              Sign In
            </button>

            <!-- Divider -->
            <div class="d-flex align-items-center my-3">
              <hr class="flex-grow-1">
              <span class="px-3 text-muted text-uppercase small fw-medium">or continue with</span>
              <hr class="flex-grow-1">
            </div>

            <!-- Social Login -->
            <button type="button" class="btn btn-outline-secondary w-100 social-btn">
              <i class="fab fa-google me-2"></i>
              Sign in with Google
            </button>

            <!-- Sign Up Link -->
            <div class="text-center mt-3 pt-3 border-top">
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

    // Enter key support
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

    // Auto-fill role based on email (optional feature)
    document.addEventListener('DOMContentLoaded', function() {
      const emailInput = document.getElementById('loginEmail');
      const roleSelect = document.getElementById('loginRole');
      
      // This is a demo feature - in production, you'd check the database
      emailInput.addEventListener('blur', function() {
        const email = this.value;
        if (email) {
          // You could make an AJAX call to check the user's role
          // For now, it's a placeholder
        }
      });
    });
  </script>
</body>

</html>