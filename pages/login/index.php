<?php // Backend for Login
  require_once __DIR__ . '/../../functions/database/database.php';
  require_once __DIR__ . '/../../functions/validations/validations.php';
  require_once __DIR__ . '/../../functions/utility/utility.php';

  $db = connectDatabase();

  if (isset($_POST['registerCitizen'])) {
    $email    = sanitizeInput($_POST['email'] ?? '');
    $password = sanitizeInput($_POST['password'] ?? '');

    $isLoginPageValidated = true;

    if (empty($email) || empty($password)) {
      $isLoginPageValidated = false;
      die("Please fill in all required fields.");
    }

    if (!validateEmail($email)) {
      $isLoginPageValidated = false;
      die("Please enter a valid email address.");
    }

    if (!validatePassword($password)) {
      $isLoginPageValidated = false;
      die("Password must be at least 8 characters long, contain at least one number, and one symbol.");
    }

    if ($isLoginPageValidated) {
      redirect('../dashboard?type=citizen', 0);
    }
  }
?>

<!doctype html>
<html lang="en" data-theme="light">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta
    name="description"
    content="Sign in to CivicConnect - Report & Track Civic Issues" />
  <meta name="theme-color" content="#4F46E5" />

  <title>CivicConnect - Sign In</title>

  <!-- Google Fonts -->
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link
    href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap"
    rel="stylesheet" />

  <!-- Font Awesome Icons -->
  <link
    rel="stylesheet"
    href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />

  <!-- Main Styles -->
  <link rel="stylesheet" href="../../assets/css/style.css" />
</head>

<body>
  <!-- ============================================
    LOGIN PAGE - CivicConnect
    ============================================ -->

  <!-- ===== NAVBAR ===== -->
  <nav
    id="loginNavbar"
    class="navbar"
    role="navigation"
    aria-label="Main navigation">
    <div class="container-fluid d-flex align-center justify-between">
      <!-- Brand -->
      <a
        href="../../index.php"
        id="brandLink"
        class="navbar-brand"
        aria-label="CivicConnect Home">
        <span class="brand-icon" aria-hidden="true">
          <i class="fas fa-city"></i>
        </span>
        <span class="brand-text">CivicConnect</span>
      </a>

      <!-- Actions -->
      <div class="navbar-actions">
        <button
          id="themeToggleBtn"
          class="theme-toggle"
          aria-label="Toggle theme"
          title="Toggle dark/light mode"
          data-theme-toggle>
          <i class="fas fa-moon" id="themeIcon"></i>
        </button>

<<<<<<< HEAD:pages/login/index.php
        <a href="../register/" id="registerNavBtn" class="btn btn-primary">
=======
        <a href="register.php" id="registerNavBtn" class="btn btn-primary">
>>>>>>> abc6026 (..):login/index.php
          <i class="fas fa-user-plus" aria-hidden="true"></i>
          Create Account
        </a>
      </div>
    </div>
  </nav>

  <!-- ===== LOGIN SECTION ===== -->
  <section class="auth-section" aria-labelledby="loginTitle">
    <div class="container">
      <div class="auth-container">
        <div class="auth-card card card-glass animate-fade-in-up">
          <!-- Header -->
          <div class="auth-header text-center">
            <div class="auth-icon-wrapper">
              <i class="fas fa-sign-in-alt" aria-hidden="true"></i>
            </div>
            <h1 id="loginTitle" class="auth-title">Welcome Back</h1>
            <p class="auth-subtitle">Sign in to your CivicConnect account</p>
          </div>

          <!-- Login Form -->
          <form id="loginForm" class="auth-form" method="POST" action="./">
            <!-- Email -->
            <div class="form-group">
              <label for="loginEmail" class="form-label">
                Email Address
                <span class="required">*</span>
              </label>
              <div class="form-input-wrapper">
                <span class="input-icon">
                  <i class="fas fa-envelope" aria-hidden="true"></i>
                </span>
                <input
                  id="loginEmail"
                  name="email"
                  class="form-input"
                  type="email"
                  name="email"
                  placeholder="Enter your email address"
                  value="sample.citizen@gmail.com"
                  required
                  autocomplete="email"
                  aria-describedby="emailHelp" />
              </div>
              <span id="emailHelp" class="form-hint">We'll never share your email</span>
            </div>

            <!-- Password -->
            <div class="form-group">
              <label for="loginPassword" class="form-label">
                Password
                <span class="required">*</span>
              </label>
              <div class="form-input-wrapper">
                <span class="input-icon">
                  <i class="fas fa-lock" aria-hidden="true"></i>
                </span>
                <input
                  id="loginPassword"
                  name="password"
                  class="form-input"
                  type="password"
                  name="password"
                  placeholder="Enter your password"
                  value="Citizen@123"
                  required
                  minlength="8"
                  autocomplete="current-password"
                  aria-describedby="passwordHelp" />
                <button
                  type="button"
                  id="togglePasswordBtn"
                  class="password-toggle"
                  aria-label="Toggle password visibility"
                  onclick="togglePassword()">
                  <i class="fas fa-eye" id="passwordIcon"></i>
                </button>
              </div>
              <span id="passwordHelp" class="form-hint">
                Password must be at least 8 characters
              </span>
            </div>

            <!-- Remember Me & Forgot Password -->
            <div class="form-options">
              <div class="form-check">
                <input
                  id="rememberMe"
                  name="rememberMe"
                  class="form-check-input"
                  type="checkbox" />
                <label for="rememberMe" class="form-check-label">Remember me</label>
              </div>
              <a
<<<<<<< HEAD:pages/login/index.php
                href="../changePassword/"
=======
                href="forgot-password.php"
>>>>>>> abc6026 (..):login/index.php
                id="forgotPasswordLink"
                class="forgot-password">
                Forgot password?
              </a>
            </div>

            <!-- Submit Button -->
            <button
              id="loginSubmitBtn"
              type="submit"
              class="btn btn-primary btn-lg w-full auth-submit-btn"
              name="registerCitizen">
              <i class="fas fa-sign-in-alt" aria-hidden="true"></i>
              Sign In
            </button>

            <!-- Divider -->
            <div class="auth-divider">
              <span>or continue with</span>
            </div>

            <!-- Social Login - Only Google (GitHub removed) -->
            <div class="social-login">
              <button
                type="button"
                id="googleLoginBtn"
                class="btn btn-secondary social-btn"
                style="grid-column: 1 / -1">
                <i class="fab fa-google" aria-hidden="true"></i>
                Sign in with Google
              </button>
            </div>

            <!-- Sign Up Link -->
            <div class="auth-footer text-center">
              <p class="auth-footer-text">
                Don't have an account?
<<<<<<< HEAD:pages/login/index.php
                <a href="../register/" id="signUpLink" class="auth-link">
=======
                <a href="register.php" id="signUpLink" class="auth-link">
>>>>>>> abc6026 (..):login/index.php
                  Create one now
                  <i class="fas fa-arrow-right" aria-hidden="true"></i>
                </a>
              </p>
            </div>
          </form>
        </div>
      </div>
    </div>
  </section>

  <!-- ===== FOOTER ===== -->
  <footer id="authFooter" class="auth-footer-section" role="contentinfo">
    <div class="container">
      <div class="footer-bottom text-center">
        <p>&copy; 2026 CivicConnect. All rights reserved.</p>
      </div>
    </div>
  </footer>

  <!-- ============================================
    SCRIPTS
    ============================================ -->
  <script>
    // ============================================
    // THEME TOGGLE
    // ============================================
    document.addEventListener("DOMContentLoaded", function() {
      const themeToggle = document.getElementById("themeToggleBtn");
      const themeIcon = document.getElementById("themeIcon");
      const html = document.documentElement;

      if (!themeToggle || !themeIcon) return;

      // Check saved theme
      const savedTheme = localStorage.getItem("theme");
      if (savedTheme) {
        html.setAttribute("data-theme", savedTheme);
        updateThemeIcon(savedTheme);
      }

      // Toggle theme
      themeToggle.addEventListener("click", function() {
        const currentTheme = html.getAttribute("data-theme");
        const newTheme = currentTheme === "dark" ? "light" : "dark";

        html.setAttribute("data-theme", newTheme);
        localStorage.setItem("theme", newTheme);
        updateThemeIcon(newTheme);
      });

      function updateThemeIcon(theme) {
        if (theme === "dark") {
          themeIcon.className = "fas fa-sun";
        } else {
          themeIcon.className = "fas fa-moon";
        }
      }
    });

    // ============================================
    // PASSWORD VISIBILITY TOGGLE
    // ============================================
    function togglePassword() {
      const passwordInput = document.getElementById("loginPassword");
      const passwordIcon = document.getElementById("passwordIcon");

      if (passwordInput.type === "password") {
        passwordInput.type = "text";
        passwordIcon.className = "fas fa-eye-slash";
      } else {
        passwordInput.type = "password";
        passwordIcon.className = "fas fa-eye";
      }
    }

    // ============================================
    // ENTER KEY SUPPORT
    // ============================================
    document.addEventListener("DOMContentLoaded", function() {
      const passwordInput = document.getElementById("loginPassword");
      if (passwordInput) {
        passwordInput.addEventListener("keypress", function(e) {
          if (e.key === "Enter") {
            document.getElementById("loginSubmitBtn").click();
          }
        });
      }
    });
  </script>
</body>

</html>