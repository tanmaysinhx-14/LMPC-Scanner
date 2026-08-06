<?php // Bootstrapper
  require __DIR__ . '/../../bootstrap.php';

  $bootstrapData = bootstrapAccounts();

  extract($bootstrapData);
?>

<?php // Backend for Citizen Registration
  if(isset($_POST['registerCitizen'])) {
    $name = sanitizeInput($_POST['fullName']);
    $email = sanitizeInput($_POST['email']);
    $phone = sanitizeInput($_POST['phone']);
    $city = sanitizeInput($_POST['city']);
    $ward_id = !empty($_POST['ward_id']) ? (int)$_POST['ward_id'] : null;
    $password = sanitizeInput($_POST['password']);
    $confirmPassword = sanitizeInput($_POST['confirmPassword']);
    $role = sanitizeInput($_POST['userRole']);
    $termsAccepted = isset($_POST['termsAccepted']);

    if (!$termsAccepted) {
      setToast('Agree to Terms and Conditions.', 'danger');
    }
    elseif ($password !== $confirmPassword) {
      setToast('Passwords entered do not match.', 'danger');
    } 
    else {
      $password_hash = password_hash($password, PASSWORD_BCRYPT);

      try {
        $stmt = $db->prepare("SELECT id FROM users WHERE email = :email");
        $stmt->execute(['email' => $email]);

        if ($stmt->fetch()) {
          setToast('Account exists with this email.', 'danger');
        } 
        else {
          $insertStmt = $db->prepare("INSERT INTO users (name, email, password_hash, role, ward_id, city, phone) VALUES (:name, :email, :password_hash, :role, :ward_id, :city, :phone)");
          $insertStmt->execute([
            'name' => $name,
            'email' => $email,
            'password_hash' => $password_hash,
            'role' => $role,
            'ward_id' => $ward_id,
            'city' => $city,
            'phone' => $phone
          ]);

          setToast('User registered successfully.', 'success');
        }
      } 
      catch (PDOException $e) {
        setToast('Database error occurred. Error: ' . $e->getMessage(), 'danger');
      }
    }
  }
?>

<?php // Header (contains Unified Page Meta-Data and CSS imports)
  require_once '../../components/header.php';
?>
<body class="d-flex flex-column min-vh-100">
  <nav class="navbar navbar-expand-lg sticky-top bg-body border-bottom shadow-sm">
    <div class="container">
      <a href="../../index.html" class="navbar-brand d-flex align-items-center gap-2 fw-bold text-primary">
        <span class="d-inline-flex align-items-center justify-content-center rounded-3 bg-primary text-white" style="width: 36px; height: 36px;">
          <i class="fas fa-city"></i>
        </span>
        CivicConnect
      </a>
      <div class="d-flex align-items-center gap-3">
        <button id="themeToggleBtn" class="btn btn-link text-body p-0 border-0" aria-label="Toggle theme">
          <i class="fas fa-moon fs-5" id="themeIcon"></i>
        </button>
        <a href="../login/index.php" class="btn btn-secondary rounded-pill px-4">
          <i class="fas fa-sign-in-alt me-2"></i> Sign In
        </a>
      </div>
    </div>
  </nav>

  <main class="flex-grow-1 d-flex align-items-center py-5">
    <div class="container">
      <div class="row justify-content-center">
        <div class="col-12 col-md-8 col-lg-6 col-xl-5">
          <div class="card border-0 shadow-lg rounded-4">
            <div class="card-body p-4 p-sm-5">

              <!-- Header -->
              <div class="text-center mb-4">
                <div class="d-inline-flex align-items-center justify-content-center bg-primary bg-opacity-10 text-primary rounded-circle mb-3" style="width: 64px; height: 64px;">
                  <i class="fas fa-user-plus fs-3"></i>
                </div>
                <h1 class="h3 fw-bold">Create Account</h1>
                <p class="text-secondary mb-0">Join CivicConnect and make your city better</p>
              </div>

              <!-- Error Messages -->
              <?php if (isset($error)): ?>
                <div class="alert alert-danger d-flex align-items-center gap-2 rounded-3">
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
                  <div class="input-group">
                    <span class="input-group-text bg-body-tertiary">
                      <i class="fas fa-user text-secondary"></i>
                    </span>
                    <input 
                      id="registerFullName" 
                      name="fullName" 
                      type="text" 
                      class="form-control" 
                      placeholder="Enter your full name" 
                      value="First Citizen"
                      required 
                      autocomplete="name" 
                      value="<?php echo isset($_POST['fullName']) ? htmlspecialchars($_POST['fullName']) : ''; ?>"
                    />
                  </div>
                  <div class="form-text">Your full name as it appears on official documents</div>
                </div>

                <!-- Email -->
                <div class="mb-3">
                  <label for="registerEmail" class="form-label fw-medium">
                    Email Address <span class="text-danger">*</span>
                  </label>
                  <div class="input-group">
                    <span class="input-group-text bg-body-tertiary">
                      <i class="fas fa-envelope text-secondary"></i>
                    </span>
                    <input 1
                      id="registerEmail" 
                      name="email" 
                      type="email" 
                      class="form-control" 
                      placeholder="Enter your email address" 
                      value="mail.citizen@gmail.com"
                      required 
                      autocomplete="email" 
                      value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>"
                    />
                  </div>
                  <div class="form-text">We'll send a verification email to this address</div>
                </div>

                <!-- Phone -->
                <div class="mb-3">
                  <label for="registerPhone" class="form-label fw-medium">
                    Phone Number <span class="text-danger">*</span>
                  </label>
                  <div class="input-group">
                    <span class="input-group-text bg-body-tertiary">
                      <i class="fas fa-phone text-secondary"></i>
                    </span>
                    <input 
                      id="registerPhone" 
                      name="phone" 
                      type="tel" 
                      class="form-control" 
                      placeholder="Enter your phone number" 
                      value="+91987654321"
                      required 
                      autocomplete="tel" 
                      value="<?php echo isset($_POST['phone']) ? htmlspecialchars($_POST['phone']) : ''; ?>"
                    />
                  </div>
                  <div class="form-text">We'll use this for important updates</div>
                </div>

                <!-- Password -->
                <div class="mb-3">
                  <label for="registerPassword" class="form-label fw-medium">
                    Password <span class="text-danger">*</span>
                  </label>
                  <div class="input-group">
                    <span class="input-group-text bg-body-tertiary"><i class="fas fa-lock text-secondary"></i></span>
                    <input 
                      id="registerPassword" 
                      name="password" 
                      type="password" 
                      class="form-control" 
                      placeholder="Create a strong password" 
                      value="Citizen@123"
                      required 
                      minlength="8" 
                      autocomplete="new-password"
                    />
                    <button class="btn btn-outline-secondary" type="button" onclick="togglePassword()">
                      <i class="fas fa-eye" id="passwordIcon"></i>
                    </button>
                  </div>
                  <div class="form-text">Must be at least 8 characters with numbers and special characters</div>
                </div>

                <!-- Confirm Password -->
                <div class="mb-3">
                  <label for="registerConfirmPassword" class="form-label fw-medium">
                    Confirm Password <span class="text-danger">*</span>
                  </label>
                  <div class="input-group">
                    <span class="input-group-text bg-body-tertiary">
                      <i class="fas fa-check-circle text-secondary"></i>
                    </span>
                    <input 
                      id="registerConfirmPassword" 
                      name="confirmPassword" 
                      type="password" 
                      class="form-control" 
                      placeholder="Confirm your password" 
                      value="Citizen@123"
                      required 
                      autocomplete="new-password"
                    />
                  </div>
                  <div id="confirmHelp" class="form-text">Passwords must match</div>
                </div>

                <!-- Role Selection -->
                <div class="mb-3">
                  <label for="userRole" class="form-label fw-medium">
                    I am a <span class="text-danger">*</span>
                  </label>
                  <div class="input-group">
                    <span class="input-group-text bg-body-tertiary"><i class="fas fa-user-tag text-secondary"></i></span>
                    <select id="userRole" name="userRole" class="form-select" required>
                      <option value="">Select your role...</option>
                      <option value="citizen" <?php echo (isset($_POST['userRole']) && $_POST['userRole'] === 'citizen') ? 'selected' : ''; ?>>Citizen</option>
                      <option value="authority" <?php echo (isset($_POST['userRole']) && $_POST['userRole'] === 'authority') ? 'selected' : ''; ?>>Municipal Authority</option>
                      <option value="admin" <?php echo (isset($_POST['userRole']) && $_POST['userRole'] === 'admin') ? 'selected' : ''; ?>>Administrator</option>
                    </select>
                  </div>
                  <div class="form-text">Select the role that best describes you</div>
                </div>

                <!-- City -->
                <div class="mb-3">
                  <label for="registerCity" class="form-label fw-medium">
                    City <span class="text-danger">*</span>
                  </label>
                  <div class="input-group">
                    <span class="input-group-text bg-body-tertiary"><i class="fas fa-map-marker-alt text-secondary"></i></span>
                    <input 
                      id="registerCity" 
                      name="city" 
                      type="text" 
                      class="form-control" 
                      placeholder="Enter or detect your city" 
                      required 
                      autocomplete="address-level2" 
                      value="<?php echo isset($_POST['city']) ? htmlspecialchars($_POST['city']) : ''; ?>"
                    >
                    <button class="btn btn-secondary" type="button" id="detectCityBtn">
                      <i class="fas fa-crosshairs"></i>
                    </button>
                  </div>
                  <div class="form-text">Your city of residence</div>
                </div>

                <!-- Terms -->
                <div class="mb-4">
                  <div class="form-check">
                    <input 
                      id="termsCheckbox" 
                      name="termsAccepted" 
                      class="form-check-input" 
                      type="checkbox" 
                      checked 
                      required
                    />
                    <label for="termsCheckbox" class="form-check-label text-secondary">
                      I agree to the <a href="#" class="text-primary text-decoration-none fw-medium">Terms of Service</a> and <a href="#" class="text-primary text-decoration-none fw-medium">Privacy Policy</a> <span class="text-danger">*</span>
                    </label>
                  </div>
                </div>

                <!-- Submit -->
                <button type="submit" name="registerCitizen" class="btn btn-primary w-100 py-2 mb-3 fw-medium">
                  <i class="fas fa-user-plus me-2"></i> Create Account
                </button>

                <!-- Login Link -->
                <div class="text-center pt-4">
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
      </div>
    </div>
  </main>

  <?php // Contains Bottom-Credits and JS imports
    require_once '../../components/bottom-credits.php';
    require_once '../../components/footer.php';
  ?>

  <script type="text/javascript" src="../../assets/js/index.js"></script>
  <script type="text/javascript"> // Registrations Exclusive JS
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

    // Password Confirmation Validation leveraging standard Bootstrap validation classes
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
            helpText.className = 'form-text';
          }
        });
      }
    });

    // City Data Retriever 
    document.addEventListener('DOMContentLoaded', function() {
      const cityInput = document.getElementById('registerCity');
      const detectCityBtn = document.getElementById('detectCityBtn');

      if (detectCityBtn && cityInput) {
        detectCityBtn.addEventListener('click', function() {
          if (!navigator.geolocation) {
            alert('Geolocation is not supported by your browser.');
            return;
          }

          const originalPlaceholder = cityInput.placeholder;
          cityInput.value = "";
          cityInput.placeholder = "Detecting city...";
          cityInput.readOnly = true;
          
          const icon = this.querySelector('i');
          icon.classList.remove('fa-crosshairs');
          icon.classList.add('fa-spinner', 'fa-spin');

          navigator.geolocation.getCurrentPosition(
            async (position) => {
              try {
                const lat = position.coords.latitude;
                const lng = position.coords.longitude;
                
                const response = await fetch(`https://nominatim.openstreetmap.org/reverse?format=json&lat=${lat}&lon=${lng}`);
                const data = await response.json();

                const city = data.address.city || data.address.town || data.address.village || data.address.county || "";

                if (city) {
                  cityInput.value = city;
                } else {
                  alert('Could not isolate a city name from the detected location.');
                }
              } catch (error) {
                alert('Error communicating with the mapping service.');
              } finally {
                cityInput.readOnly = false;
                cityInput.placeholder = originalPlaceholder;
                icon.classList.remove('fa-spinner', 'fa-spin');
                icon.classList.add('fa-crosshairs');
              }
            },
            (error) => {
              cityInput.readOnly = false;
              cityInput.placeholder = originalPlaceholder;
              icon.classList.remove('fa-spinner', 'fa-spin');
              icon.classList.add('fa-crosshairs');
              alert('Unable to retrieve location. Please check browser permissions.');
            },
            { timeout: 8000, enableHighAccuracy: true }
          );
        });
      }
    });

    // Enter key support
    document.addEventListener('DOMContentLoaded', function() {
      const confirmInput = document.getElementById('registerConfirmPassword');
      if (confirmInput) {
        confirmInput.addEventListener('keypress', function(e) {
          if (e.key === 'Enter') {
            e.preventDefault();
            document.querySelector('button[name="registerCitizen"]').click();
          }
        });
      }
    });
  </script>
</body>
</html>