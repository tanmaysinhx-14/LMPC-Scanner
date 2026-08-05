<?php // Backend for Registration
<<<<<<< HEAD:pages/citizen/register.php
  require_once __DIR__ . '/../functions/database/database.php';
  require_once __DIR__ . '/../functions/validations/validations.php';
=======
  require_once __DIR__ . '\..\..\functions\database\database.php';
  require_once __DIR__ . '\..\..\functions\validations\validations.php';
>>>>>>> abc6026 (..):register/index.php

  $db = connectDatabase();

  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullName = $_POST['fullName'] ?? '';
    $email = $_POST['email'] ?? '';
    $phone = $_POST['phone'] ?? '';
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirmPassword'] ?? '';
    $role = $_POST['userRole'] ?? '';
    $termsAccepted = isset($_POST['termsAccepted']);

    $isRegistrationFormValidated = true; 

    if (empty($fullName) || empty($email) || empty($phone) || empty($password) || empty($confirmPassword) || empty($role)) {
      $isRegistrationFormValidated = false;
      die("Please fill in all required fields.");
    }

    if (!validateEmail($email)) {
      $isRegistrationFormValidated = false;
      die("Please enter a valid email address.");
    }

    if (!validatePassword($password)) {
      $isRegistrationFormValidated = false;
      die("Password must be at least 8 characters long, contain at least one number, and one symbol.");
    }

    if ($password !== $confirmPassword) {
      $isRegistrationFormValidated = false;
      die("Passwords do not match!");
    }

    if (!$termsAccepted) {
      $isRegistrationFormValidated = false;
      die("Please accept the Terms of Service and Privacy Policy.");
    }

    if ($isRegistrationFormValidated) {
      // Main Registration Logic goes here ...
      // Redirect to login page after successful registration
      header("Location: login.php");
      exit();
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

  <!-- Font Awesome Icons -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

  <!-- Main Styles -->
  <link rel="stylesheet" href="../assets/css/style.css">
</head>

<body>

  <!-- ============================================
    REGISTRATION PAGE - CivicConnect
    ============================================ -->

  <!-- ===== NAVBAR ===== -->
  <nav id="registerNavbar" class="navbar" role="navigation" aria-label="Main navigation">
    <div class="container-fluid d-flex align-center justify-between">
      <!-- Brand -->
      <a href="../../index.html" id="brandLink" class="navbar-brand" aria-label="CivicConnect Home">
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

        <a href="login.php" id="loginNavBtn" class="btn btn-secondary">
          <i class="fas fa-sign-in-alt" aria-hidden="true"></i>
          Sign In
        </a>
      </div>
    </div>
  </nav>

  <!-- ===== REGISTRATION SECTION ===== -->
  <section class="auth-section" aria-labelledby="registerTitle">
    <div class="container">
      <div class="auth-container">
        <div class="auth-card card card-glass animate-fade-in-up">
          <!-- Header -->
          <div class="auth-header text-center">
            <div class="auth-icon-wrapper">
              <i class="fas fa-user-plus" aria-hidden="true"></i>
            </div>
            <h1 id="registerTitle" class="auth-title">Create Account</h1>
            <p class="auth-subtitle">Join CivicConnect and make your city better</p>
          </div>

          <!-- Registration Form -->
          <form id="registerForm" class="auth-form" method="POST" action="./index.php" novalidate>
            <!-- Full Name -->
            <div class="form-group">
              <label for="registerFullName" class="form-label">
                Full Name
                <span class="required">*</span>
              </label>
              <div class="form-input-wrapper">
                <span class="input-icon">
                  <i class="fas fa-user" aria-hidden="true"></i>
                </span>
                <input
                  id="registerFullName"
                  name="fullName"
                  class="form-input"
                  type="text"
                  placeholder="Enter your full name"
                  required
                  autocomplete="name"
                  aria-describedby="nameHelp">
              </div>
              <span id="nameHelp" class="form-hint">Your full name as it appears on official documents</span>
            </div>

            <!-- Email -->
            <div class="form-group">
              <label for="registerEmail" class="form-label">
                Email Address
                <span class="required">*</span>
              </label>
              <div class="form-input-wrapper">
                <span class="input-icon">
                  <i class="fas fa-envelope" aria-hidden="true"></i>
                </span>
                <input
                  id="registerEmail"
                  name="email"
                  class="form-input"
                  type="email"
                  placeholder="Enter your email address"
                  required
                  autocomplete="email"
                  aria-describedby="emailHelp">
              </div>
              <span id="emailHelp" class="form-hint">We'll send a verification email to this address</span>
            </div>

            <!-- Phone Number -->
            <div class="form-group">
              <label for="registerPhone" class="form-label">
                Phone Number
                <span class="required">*</span>
              </label>
              <div class="form-input-wrapper">
                <span class="input-icon">
                  <i class="fas fa-phone" aria-hidden="true"></i>
                </span>
                <input
                  id="registerPhone"
                  name="phone"
                  class="form-input"
                  type="tel"
                  placeholder="Enter your phone number"
                  required
                  autocomplete="tel"
                  aria-describedby="phoneHelp">
              </div>
              <span id="phoneHelp" class="form-hint">We'll use this for important updates</span>
            </div>

            <!-- Password -->
            <div class="form-group">
              <label for="registerPassword" class="form-label">
                Password
                <span class="required">*</span>
              </label>
              <div class="form-input-wrapper">
                <span class="input-icon">
                  <i class="fas fa-lock" aria-hidden="true"></i>
                </span>
                <input
                  id="registerPassword"
                  name="password"
                  class="form-input"
                  type="password"
                  placeholder="Create a strong password"
                  required
                  minlength="8"
                  autocomplete="new-password"
                  aria-describedby="passwordHelp">
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
                Must be at least 8 characters with numbers and special characters
              </span>
            </div>

            <!-- Confirm Password -->
            <div class="form-group">
              <label for="registerConfirmPassword" class="form-label">
                Confirm Password
                <span class="required">*</span>
              </label>
              <div class="form-input-wrapper">
                <span class="input-icon">
                  <i class="fas fa-check-circle" aria-hidden="true"></i>
                </span>
                <input
                  id="registerConfirmPassword"
                  name="confirmPassword"
                  class="form-input"
                  type="password"
                  placeholder="Confirm your password"
                  required
                  autocomplete="new-password"
                  aria-describedby="confirmHelp">
              </div>
              <span id="confirmHelp" class="form-hint">Passwords must match</span>
            </div>

            <!-- User Role Selection -->
            <div class="form-group">
              <label for="userRole" class="form-label">
                I am a
                <span class="required">*</span>
              </label>
              <select id="userRole" name="userRole" class="form-select" required>
                <option value="">Select your role...</option>
                <option value="citizen">Citizen</option>
                <option value="authority">Municipal Authority</option>
                <option value="admin">Administrator</option>
              </select>
              <span class="form-hint">Select the role that best describes you</span>
            </div>

            <!-- Terms & Conditions -->
            <div class="form-group">
              <div class="form-check">
                <input
                  id="termsCheckbox"
                  name="termsAccepted"
                  class="form-check-input"
                  type="checkbox"
                  required>
                <label for="termsCheckbox" class="form-check-label">
                  I agree to the
                  <strong><a href="#" id="termsLink">Terms of Service</a></strong>
                  and
                  <strong><a href="#" id="privacyLink">Privacy Policy</a></strong>
                  <span class="required">*</span>
                </label>
              </div>
            </div>

            <!-- Submit Button -->
            <button
              id="registerSubmitBtn"
              type="submit"
              name="registerSubmit"
              class="btn btn-primary btn-lg w-full auth-submit-btn">
              <i class="fas fa-user-plus" aria-hidden="true"></i>
              Create Account
            </button>

            <!-- Divider -->
            <div class="auth-divider">
              <span>or continue with</span>
            </div>

            <!-- Social Registration - Only Google (GitHub removed) -->
            <div class="social-login">
              <button type="button" id="googleRegisterBtn" class="btn btn-secondary social-btn" style="grid-column: 1 / -1;">
                <i class="fab fa-google" aria-hidden="true"></i>
                Sign up with Google
              </button>
            </div>

            <!-- Login Link -->
            <div class="auth-footer text-center">
              <p class="auth-footer-text">
                Already have an account?
                <a href="login.php" id="signInLink" class="auth-link">
                  Sign in here
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
    document.addEventListener('DOMContentLoaded', function() {
      const themeToggle = document.getElementById('themeToggleBtn');
      const themeIcon = document.getElementById('themeIcon');
      const html = document.documentElement;

      if (!themeToggle || !themeIcon) return;

      // Check saved theme
      const savedTheme = localStorage.getItem('theme');
      if (savedTheme) {
        html.setAttribute('data-theme', savedTheme);
        updateThemeIcon(savedTheme);
      }

      // Toggle theme
      themeToggle.addEventListener('click', function() {
        const currentTheme = html.getAttribute('data-theme');
        const newTheme = currentTheme === 'dark' ? 'light' : 'dark';

        html.setAttribute('data-theme', newTheme);
        localStorage.setItem('theme', newTheme);
        updateThemeIcon(newTheme);
      });

      function updateThemeIcon(theme) {
        if (theme === 'dark') {
          themeIcon.className = 'fas fa-sun';
        } else {
          themeIcon.className = 'fas fa-moon';
        }
      }
    });

    // ============================================
    // PASSWORD VISIBILITY TOGGLE
    // ============================================
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

    // ============================================
    // PASSWORD CONFIRMATION VALIDATION
    // ============================================
    document.addEventListener('DOMContentLoaded', function() {
      const passwordInput = document.getElementById('registerPassword');
      const confirmInput = document.getElementById('registerConfirmPassword');
      const helpText = document.getElementById('confirmHelp');

      if (confirmInput) {
        confirmInput.addEventListener('input', function() {
          const password = passwordInput.value;
          const confirmPassword = this.value;

          if (confirmPassword && password !== confirmPassword) {
            this.classList.add('error');
            this.classList.remove('success');
            helpText.textContent = 'Passwords do not match!';
            helpText.style.color = 'var(--color-danger)';
          } else if (confirmPassword && password === confirmPassword) {
            this.classList.remove('error');
            this.classList.add('success');
            helpText.textContent = 'Passwords match! ✓';
            helpText.style.color = 'var(--color-success)';
          } else {
            this.classList.remove('error', 'success');
            helpText.textContent = 'Passwords must match';
            helpText.style.color = '';
          }
        });
      }
    });

    // ============================================
    // REGISTRATION FORM SUBMISSION
    // ============================================
    document.addEventListener('DOMContentLoaded', function() {
      const form = document.getElementById('registerForm');

      if (form) {
        form.addEventListener('submit', function(e) {
          // Let PHP handle the submission
          // No need to prevent default for PHP form submission
          // Just show loading state
          
          const submitBtn = document.getElementById('registerSubmitBtn');
          const originalText = submitBtn.innerHTML;
          submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Creating account...';
          submitBtn.disabled = true;
          submitBtn.classList.add('loading');
          
          // Form will submit to PHP normally
          // The loading state will be shown until page reloads
        });
      }
    });

    // ============================================
    // ENTER KEY SUPPORT
    // ============================================
    document.addEventListener('DOMContentLoaded', function() {
      const confirmInput = document.getElementById('registerConfirmPassword');
      if (confirmInput) {
        confirmInput.addEventListener('keypress', function(e) {
          if (e.key === 'Enter') {
            document.getElementById('registerSubmitBtn').click();
          }
        });
      }
    });
  </script>
</body>

</html>