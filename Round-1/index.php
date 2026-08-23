<?php // Bootstrapper + Backend Integration
  require __DIR__ . '/bootstrap.php';

  $bootstrapData = bootstrapAccounts([
    'require_login' => false,
  ]);

  extract($bootstrapData);
?>

<?php // Backend orchestration
  $landingData = fetchLandingPageData($db instanceof PDO ? $db : null);
  $landingStats = $landingData['stats'];
  $landingTotal = $landingData['total'];
  $landingResolved = $landingData['resolved'];
  $landingResolutionRate = $landingData['resolutionRate'];
?>

<?php // Main HTML ?>
<!doctype html>
<html lang="en" data-theme="light">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="description" content="CivicConnect - Crowdsourced Civic Issue Reporting & Resolution Platform">
  <meta name="theme-color" content="#4F46E5">

  <title>CivicConnect - Report & Track Civic Issues</title>

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

  <link rel="stylesheet" type="text/css" href="<?= htmlspecialchars($urlForAssets . 'css/bootstrap.css', ENT_QUOTES, 'UTF-8') ?>">
  <link rel="stylesheet" type="text/css" href="<?= htmlspecialchars($urlForAssets . 'css/style.css', ENT_QUOTES, 'UTF-8') ?>">
  <link rel="stylesheet" type="text/css" href="<?= htmlspecialchars($urlForAssets . 'css/civic-ui.css', ENT_QUOTES, 'UTF-8') ?>">

  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>

<body>
  <nav id="landingNavbar" class="navbar navbar-expand-lg sticky-top bg-white bg-opacity-80 backdrop-blur border-bottom" role="navigation" aria-label="Main navigation">
    <div class="container-fluid px-4">
      <!-- Brand -->
      <a href="<?= htmlspecialchars($urlForRoot . '/', ENT_QUOTES, 'UTF-8') ?>" id="brandLink" class="navbar-brand d-flex align-items-center gap-2 fw-bold text-primary" aria-label="CivicConnect Home">
        <span class="brand-icon d-inline-flex align-items-center justify-content-center rounded-3 text-white" style="width:36px;height:36px;background:var(--color-primary-gradient);">
          <i class="fas fa-city"></i>
        </span>
        <span class="brand-text">CivicConnect</span>
      </a>

      <button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse" data-bs-target="#navLinks" aria-controls="navLinks" aria-expanded="false" aria-label="Toggle navigation">
        <span class="navbar-toggler-icon"></span>
      </button>

      <!-- Nav Links -->
      <div class="collapse navbar-collapse" id="navLinks">
        <ul class="navbar-nav mx-auto gap-3 gap-lg-4 mb-3 mb-lg-0">
          <li class="nav-item">
            <a href="#features" class="nav-link fw-medium text-secondary position-relative">Features</a>
          </li>
          <li class="nav-item">
            <a href="#how-it-works" class="nav-link fw-medium text-secondary position-relative">How It Works</a>
          </li>
          <li class="nav-item">
            <a href="#testimonials" class="nav-link fw-medium text-secondary position-relative">Testimonials</a>
          </li>
          <li class="nav-item">
            <a href="<?= htmlspecialchars($urlForPublicFeed, ENT_QUOTES, 'UTF-8') ?>" class="nav-link fw-medium text-secondary position-relative">Community Feed</a>
          </li>
        </ul>

        <!-- Actions -->
        <div class="d-flex align-items-center gap-2">
          <button
            id="themeToggleBtn"
            class="btn btn-link text-secondary p-2 rounded-circle border-0"
            aria-label="Toggle theme"
            title="Toggle dark/light mode"
            data-theme-toggle>
            <i class="fas fa-moon fs-5" id="themeIcon"></i>
          </button>

          <!-- Get Started Dropdown -->
          <div class="dropdown" id="getStartedDropdownContainer">
            <button
              id="getStartedDropdownBtn"
              class="btn btn-primary rounded-pill px-4"
              onclick="toggleGetStartedDropdown(event)"
              aria-expanded="false"
              aria-haspopup="true">
              Get Started
              <i class="fas fa-chevron-down ms-1"></i>
            </button>
            <div id="getStartedDropdown" class="dropdown-menu dropdown-menu-end shadow-lg border-0 rounded-3 py-2" role="menu">
              <a href="<?= htmlspecialchars($urlForRegister, ENT_QUOTES, 'UTF-8') ?>" class="dropdown-item py-2" role="menuitem">
                <i class="fas fa-user-plus me-2 text-primary"></i>
                Sign Up
              </a>
              <div class="dropdown-divider"></div>
            <a href="<?= htmlspecialchars($urlForLogin, ENT_QUOTES, 'UTF-8') ?>" class="dropdown-item py-2" role="menuitem">
                <i class="fas fa-sign-in-alt me-2 text-primary"></i>
                Log In
              </a>
            </div>
          </div>
        </div>
      </div>
    </div>
  </nav>

  <!-- ===== HERO SECTION ===== -->
  <section id="heroSection" class="hero-section py-5 px-4 overflow-hidden d-flex align-items-center" style="min-height: 819px;">
    <div class="container position-relative z-1">
      <div class="row align-items-center g-5">
        <div class="col-lg-7">
          <span class="badge-pulse d-inline-flex align-items-center gap-2 px-3 py-2 rounded-pill bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 mb-4">
            <span class="pulse-dot d-inline-block rounded-circle bg-primary" style="width:8px;height:8px;"></span>
            Smart City Solution
          </span>

          <h1 class="display-4 fw-bold mb-4">
            Report &amp; Track
            <span class="text-gradient">Civic Issues</span>
            in Your Community
          </h1>

          <p class="lead text-secondary mb-4" style="max-width:560px;">
            CivicConnect empowers citizens to report potholes, broken streetlights,
            water leakage, and garbage issues. Track progress in real-time and
            help build smarter cities together.
          </p>

          <div class="d-flex flex-wrap gap-3 mb-4">
            <a href="<?= htmlspecialchars($urlForRegister, ENT_QUOTES, 'UTF-8') ?>" id="heroGetStartedBtn" class="btn btn-primary btn-lg rounded-pill px-5">
              <i class="fas fa-arrow-right me-2"></i>
              Get Started
            </a>
            <a href="#how-it-works" id="heroLearnMoreBtn" class="btn btn-outline-secondary btn-lg rounded-pill px-5">
              <i class="fas fa-play-circle me-2"></i>
              Learn More
            </a>
          </div>

          <div class="d-flex gap-5 pt-3 border-top">
            <div>
              <span class="d-block display-6 fw-bold text-primary"><?php echo formatNumber($landingTotal); ?></span>
              <span class="text-secondary">Issues Reported</span>
            </div>
            <div>
              <span class="d-block display-6 fw-bold text-success"><?php echo formatNumber($landingResolved); ?></span>
              <span class="text-secondary">Issues Resolved</span>
            </div>
            <div>
              <span class="d-block display-6 fw-bold text-primary"><?php echo $landingResolutionRate; ?>%</span>
              <span class="text-secondary">Resolution Rate</span>
            </div>
          </div>
        </div>

        <!-- Right Side: Empty for now, will be filled by sticky sidebar -->
        <div class="col-lg-5">
          <!-- Content will be pushed to sticky sidebar -->
        </div>
      </div>
    </div>
  </section>



  <!-- ===== FEATURES SECTION ===== -->
  <section id="features" class="features-section py-5 bg-white">
    <div class="container">
      <div class="text-center mb-5">
        <span class="badge bg-primary bg-opacity-10 text-primary mb-3 px-3 py-2">Features</span>
        <h2 class="display-5 fw-bold mb-3">
          Everything You Need to
          <span class="text-gradient">Improve Your City</span>
        </h2>
        <p class="text-secondary" style="max-width:640px;margin:0 auto;">
          Powerful tools for citizens, administrators, and field workers to work together
        </p>
      </div>

      <div class="row g-4">
        <!-- Feature 1 -->
        <div class="col-md-6 col-lg-4">
          <div class="card border-0 shadow-sm rounded-4 h-100 p-4 hover-lift">
            <div class="feature-icon d-inline-flex align-items-center justify-content-center rounded-3 bg-primary bg-opacity-10 text-primary mb-3" style="width:56px;height:56px;">
              <i class="fas fa-camera fs-3"></i>
            </div>
            <h5 class="fw-semibold">AI-Powered Reporting</h5>
            <p class="text-secondary mb-0">Snap a photo and our AI automatically categorizes the issue. No manual labeling required.</p>
          </div>
        </div>

        <!-- Feature 2 -->
        <div class="col-md-6 col-lg-4">
          <div class="card border-0 shadow-sm rounded-4 h-100 p-4 hover-lift">
            <div class="feature-icon d-inline-flex align-items-center justify-content-center rounded-3 bg-primary bg-opacity-10 text-primary mb-3" style="width:56px;height:56px;">
              <i class="fas fa-location-dot fs-3"></i>
            </div>
            <h5 class="fw-semibold">Real-Time Tracking</h5>
            <p class="text-secondary mb-0">GPS-enabled location tracking. See reported issues on an interactive map with live status updates.</p>
          </div>
        </div>

        <!-- Feature 3 -->
        <div class="col-md-6 col-lg-4">
          <div class="card border-0 shadow-sm rounded-4 h-100 p-4 hover-lift">
            <div class="feature-icon d-inline-flex align-items-center justify-content-center rounded-3 bg-primary bg-opacity-10 text-primary mb-3" style="width:56px;height:56px;">
              <i class="fas fa-bolt fs-3"></i>
            </div>
            <h5 class="fw-semibold">Smart Prioritization</h5>
            <p class="text-secondary mb-0">AI assigns priority levels based on severity, location, and impact to ensure urgent issues are addressed first.</p>
          </div>
        </div>

        <!-- Feature 4 -->
        <div class="col-md-6 col-lg-4">
          <div class="card border-0 shadow-sm rounded-4 h-100 p-4 hover-lift">
            <div class="feature-icon d-inline-flex align-items-center justify-content-center rounded-3 bg-primary bg-opacity-10 text-primary mb-3" style="width:56px;height:56px;">
              <i class="fas fa-users fs-3"></i>
            </div>
            <h5 class="fw-semibold">Crowdsourced Transparency</h5>
            <p class="text-secondary mb-0">Upvote issues, add comments, and see what your community cares about most.</p>
          </div>
        </div>

        <!-- Feature 5 -->
        <div class="col-md-6 col-lg-4">
          <div class="card border-0 shadow-sm rounded-4 h-100 p-4 hover-lift">
            <div class="feature-icon d-inline-flex align-items-center justify-content-center rounded-3 bg-primary bg-opacity-10 text-primary mb-3" style="width:56px;height:56px;">
              <i class="fas fa-bell fs-3"></i>
            </div>
            <h5 class="fw-semibold">Instant Notifications</h5>
            <p class="text-secondary mb-0">Get notified when your issue is assigned, in progress, or resolved. Real-time updates via email and in-app.</p>
          </div>
        </div>

        <!-- Feature 6 -->
        <div class="col-md-6 col-lg-4">
          <div class="card border-0 shadow-sm rounded-4 h-100 p-4 hover-lift">
            <div class="feature-icon d-inline-flex align-items-center justify-content-center rounded-3 bg-primary bg-opacity-10 text-primary mb-3" style="width:56px;height:56px;">
              <i class="fas fa-chart-line fs-3"></i>
            </div>
            <h5 class="fw-semibold">Data Insights</h5>
            <p class="text-secondary mb-0">Analytics dashboards help municipalities identify patterns, allocate resources, and improve city planning.</p>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- ===== HOW IT WORKS SECTION ===== -->
  <section id="how-it-works" class="py-5 bg-light">
    <div class="container">
      <div class="text-center mb-5">
        <span class="badge bg-primary bg-opacity-10 text-primary mb-3 px-3 py-2">How It Works</span>
        <h2 class="display-5 fw-bold mb-3">
          Simple <span class="text-gradient">3-Step</span> Process
        </h2>
        <p class="text-secondary" style="max-width:640px;margin:0 auto;">
          From reporting to resolution — transparent and efficient
        </p>
      </div>

      <div class="row g-4">
        <!-- Step 1 -->
        <div class="col-md-4">
          <div class="text-center p-4">
            <div class="d-inline-flex align-items-center justify-content-center rounded-circle bg-primary text-white fs-2 fw-bold mb-3" style="width:64px;height:64px;">1</div>
            <div class="d-inline-flex align-items-center justify-content-center rounded-3 bg-primary bg-opacity-10 text-primary mb-3" style="width:56px;height:56px;">
              <i class="fas fa-upload fs-3"></i>
            </div>
            <h5 class="fw-semibold">Report Issue</h5>
            <p class="text-secondary mb-0">Snap a photo, add location via GPS, and submit a brief description. AI auto-categorizes your issue.</p>
          </div>
        </div>

        <!-- Step 2 -->
        <div class="col-md-4">
          <div class="text-center p-4">
            <div class="d-inline-flex align-items-center justify-content-center rounded-circle bg-primary text-white fs-2 fw-bold mb-3" style="width:64px;height:64px;">2</div>
            <div class="d-inline-flex align-items-center justify-content-center rounded-3 bg-primary bg-opacity-10 text-primary mb-3" style="width:56px;height:56px;">
              <i class="fas fa-tasks fs-3"></i>
            </div>
            <h5 class="fw-semibold">Administrators Coordinate Work</h5>
            <p class="text-secondary mb-0">Administrators review city-wide issues, allocate work to field workers, and keep citizens informed in real time.</p>
          </div>
        </div>

        <!-- Step 3 -->
        <div class="col-md-4">
          <div class="text-center p-4">
            <div class="d-inline-flex align-items-center justify-content-center rounded-circle bg-primary text-white fs-2 fw-bold mb-3" style="width:64px;height:64px;">3</div>
            <div class="d-inline-flex align-items-center justify-content-center rounded-3 bg-primary bg-opacity-10 text-primary mb-3" style="width:56px;height:56px;">
              <i class="fas fa-check-circle fs-3"></i>
            </div>
            <h5 class="fw-semibold">Get Notified</h5>
            <p class="text-secondary mb-0">Receive instant notifications when your issue is resolved. Rate the resolution and give feedback.</p>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- ===== TESTIMONIALS SECTION ===== -->
  <section id="testimonials" class="py-5 bg-white">
    <div class="container">
      <div class="text-center mb-5">
        <span class="badge bg-primary bg-opacity-10 text-primary mb-3 px-3 py-2">Testimonials</span>
        <h2 class="display-5 fw-bold mb-3">
          What People Are <span class="text-gradient">Saying</span>
        </h2>
        <p class="text-secondary" style="max-width:640px;margin:0 auto;">
          Join thousands of satisfied citizens using CivicConnect
        </p>
      </div>

      <div class="row g-4">
        <!-- Testimonial 1 -->
        <div class="col-md-4">
          <div class="card border-0 shadow-sm rounded-4 p-4 h-100 bg-white bg-opacity-75 backdrop-blur">
            <div class="text-warning mb-3">
              <i class="fas fa-star"></i>
              <i class="fas fa-star"></i>
              <i class="fas fa-star"></i>
              <i class="fas fa-star"></i>
              <i class="fas fa-star"></i>
            </div>
            <p class="mb-3">"I reported a pothole on my street and it was fixed within 3 days! The AI categorization was spot on and I loved getting updates."</p>
            <div class="d-flex align-items-center gap-3 mt-auto">
              <div class="avatar d-inline-flex align-items-center justify-content-center rounded-circle bg-primary text-white fw-semibold" style="width:40px;height:40px;">AK</div>
              <div>
                <div class="fw-semibold">Amit Kumar</div>
                <div class="text-secondary small">Resident, Delhi</div>
              </div>
            </div>
          </div>
        </div>

        <!-- Testimonial 2 -->
        <div class="col-md-4">
          <div class="card border-0 shadow-sm rounded-4 p-4 h-100 bg-white bg-opacity-75 backdrop-blur">
            <div class="text-warning mb-3">
              <i class="fas fa-star"></i>
              <i class="fas fa-star"></i>
              <i class="fas fa-star"></i>
              <i class="fas fa-star"></i>
              <i class="fas fa-star"></i>
            </div>
            <p class="mb-3">"As a municipal officer, CivicConnect has revolutionized how we track and resolve complaints. The dashboard gives us clear visibility."</p>
            <div class="d-flex align-items-center gap-3 mt-auto">
              <div class="avatar d-inline-flex align-items-center justify-content-center rounded-circle bg-primary text-white fw-semibold" style="width:40px;height:40px;">PS</div>
              <div>
                <div class="fw-semibold">Priya Sharma</div>
                <div class="text-secondary small">Municipal Officer</div>
              </div>
            </div>
          </div>
        </div>

        <!-- Testimonial 3 -->
        <div class="col-md-4">
          <div class="card border-0 shadow-sm rounded-4 p-4 h-100 bg-white bg-opacity-75 backdrop-blur">
            <div class="text-warning mb-3">
              <i class="fas fa-star"></i>
              <i class="fas fa-star"></i>
              <i class="fas fa-star"></i>
              <i class="fas fa-star"></i>
              <i class="fas fa-star"></i>
            </div>
            <p class="mb-3">"The transparency is amazing. I can see what issues my neighbors are reporting and upvote them. Real community collaboration!"</p>
            <div class="d-flex align-items-center gap-3 mt-auto">
              <div class="avatar d-inline-flex align-items-center justify-content-center rounded-circle bg-primary text-white fw-semibold" style="width:40px;height:40px;">RJ</div>
              <div>
                <div class="fw-semibold">Rahul Joshi</div>
                <div class="text-secondary small">Community Leader</div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- ===== CTA SECTION ===== -->
  <section class="py-5 bg-primary bg-opacity-10 position-relative overflow-hidden">
    <div class="position-absolute top-0 end-0 w-25 h-25 rounded-circle bg-primary bg-opacity-10" style="transform:translate(50%,-50%);"></div>
    <div class="position-absolute bottom-0 start-0 w-25 h-25 rounded-circle bg-primary bg-opacity-10" style="transform:translate(-50%,50%);"></div>
    <div class="container position-relative z-1">
      <div class="row justify-content-center">
        <div class="col-lg-8">
          <div class="card border-0 shadow-lg rounded-4 p-5 text-center bg-white bg-opacity-75 backdrop-blur">
            <h2 class="display-5 fw-bold mb-4">
              Ready to Make Your <span class="text-gradient">City Better</span>?
            </h2>
            <p class="lead text-secondary mb-4">
              Join CivicConnect today and be part of the change. Report issues,
              track progress, and help build smarter cities.
            </p>
            <div class="d-flex flex-wrap gap-3 justify-content-center">
              <a href="<?= htmlspecialchars($urlForRegister, ENT_QUOTES, 'UTF-8') ?>" id="ctaGetStartedBtn" class="btn btn-primary btn-lg rounded-pill px-5">
                <i class="fas fa-rocket me-2"></i>
                Get Started Free
              </a>
              <a href="<?= htmlspecialchars($urlForLogin, ENT_QUOTES, 'UTF-8') ?>" id="ctaLoginBtn" class="btn btn-outline-primary btn-lg rounded-pill px-5">
                <i class="fas fa-sign-in-alt me-2"></i>
                Sign In
              </a>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- ===== FOOTER ===== -->
  <footer id="mainFooter" class="bg-dark text-white-50 py-5">
    <div class="container">
      <div class="row g-4">
        <div class="col-lg-4">
          <div class="d-flex align-items-center gap-2 mb-3">
            <span class="d-inline-flex align-items-center justify-content-center rounded-3 text-white" style="width:36px;height:36px;background:var(--color-primary-gradient);">
              <i class="fas fa-city"></i>
            </span>
            <span class="text-white fs-5 fw-bold">CivicConnect</span>
          </div>
          <p>Empowering citizens to build smarter, cleaner, and safer cities through technology and collaboration.</p>
          <div class="d-flex gap-3">
            <a href="#" class="text-white-50 hover-text-white" aria-label="Facebook"><i class="fab fa-facebook-f"></i></a>
            <a href="#" class="text-white-50 hover-text-white" aria-label="Twitter"><i class="fab fa-twitter"></i></a>
            <a href="#" class="text-white-50 hover-text-white" aria-label="LinkedIn"><i class="fab fa-linkedin-in"></i></a>
            <a href="#" class="text-white-50 hover-text-white" aria-label="YouTube"><i class="fab fa-youtube"></i></a>
          </div>
        </div>

        <div class="col-6 col-md-3">
          <h5 class="text-white mb-3">Platform</h5>
          <ul class="list-unstyled">
            <li><a href="<?= htmlspecialchars($urlForPublicFeed, ENT_QUOTES, 'UTF-8') ?>" class="text-white-50 text-decoration-none hover-text-white">Community Feed</a></li>
            <li><a href="<?= htmlspecialchars($urlForHeatmap, ENT_QUOTES, 'UTF-8') ?>" class="text-white-50 text-decoration-none hover-text-white">City Pulse</a></li>
            <li><a href="<?= htmlspecialchars($urlForReport, ENT_QUOTES, 'UTF-8') ?>" class="text-white-50 text-decoration-none hover-text-white">Report an Issue</a></li>
            <li><a href="<?= htmlspecialchars($urlForRegister, ENT_QUOTES, 'UTF-8') ?>" class="text-white-50 text-decoration-none hover-text-white">Create an Account</a></li>
          </ul>
        </div>

        <div class="col-6 col-md-3">
          <h5 class="text-white mb-3">Support</h5>
          <ul class="list-unstyled">
            <li><a href="<?= htmlspecialchars($urlForLogin, ENT_QUOTES, 'UTF-8') ?>" class="text-white-50 text-decoration-none hover-text-white">Account Access</a></li>
            <li><a href="<?= htmlspecialchars($urlForPublicFeed, ENT_QUOTES, 'UTF-8') ?>" class="text-white-50 text-decoration-none hover-text-white">Track Reports</a></li>
            <li><a href="#how-it-works" class="text-white-50 text-decoration-none hover-text-white">How It Works</a></li>
            <li><a href="<?= htmlspecialchars($urlForHeatmap, ENT_QUOTES, 'UTF-8') ?>" class="text-white-50 text-decoration-none hover-text-white">Map Help</a></li>
          </ul>
        </div>

        <div class="col-6 col-md-2">
          <h5 class="text-white mb-3">Company</h5>
          <ul class="list-unstyled">
            <li><a href="#features" class="text-white-50 text-decoration-none hover-text-white">About the Platform</a></li>
            <li><a href="#testimonials" class="text-white-50 text-decoration-none hover-text-white">Community Stories</a></li>
            <li><a href="<?= htmlspecialchars($urlForLogin, ENT_QUOTES, 'UTF-8') ?>" class="text-white-50 text-decoration-none hover-text-white">Staff Access</a></li>
            <li><a href="<?= htmlspecialchars($urlForRegister, ENT_QUOTES, 'UTF-8') ?>" class="text-white-50 text-decoration-none hover-text-white">Join CivicConnect</a></li>
          </ul>
        </div>
      </div>

      <hr class="border-white-10 my-4">
      <div class="text-center">
        <p class="mb-0">&copy; 2026 CivicConnect. All rights reserved. Built for smarter cities.</p>
      </div>
    </div>
  </footer>

  <?php // Bottom scripts ?>
  <script type="text/javascript" src="<?= htmlspecialchars($urlForAssets . 'js/bootstrap.js', ENT_QUOTES, 'UTF-8') ?>"></script>

  <script type="text/javascript">
    function toggleGetStartedDropdown(event) {
      event.preventDefault();
      event.stopPropagation();

      const dropdown = document.getElementById("getStartedDropdown");
      const btn = document.getElementById("getStartedDropdownBtn");

      dropdown.classList.toggle("show");

      const isExpanded = dropdown.classList.contains("show");
      btn.setAttribute("aria-expanded", isExpanded);

      const chevron = btn.querySelector(".fa-chevron-down");
      if (chevron) {
        chevron.style.transform = isExpanded ? "rotate(180deg)" : "rotate(0deg)";
        chevron.style.transition = "transform 0.3s ease";
      }
    }

    document.addEventListener("click", function(event) {
      const container = document.getElementById("getStartedDropdownContainer");
      const dropdown = document.getElementById("getStartedDropdown");
      const btn = document.getElementById("getStartedDropdownBtn");

      if (container && !container.contains(event.target)) {
        dropdown.classList.remove("show");
        btn.setAttribute("aria-expanded", "false");
        const chevron = btn.querySelector(".fa-chevron-down");
        if (chevron) chevron.style.transform = "rotate(0deg)";
      }
    });

    document.addEventListener("keydown", function(event) {
      if (event.key === "Escape") {
        const dropdown = document.getElementById("getStartedDropdown");
        const btn = document.getElementById("getStartedDropdownBtn");
        if (dropdown && dropdown.classList.contains("show")) {
          dropdown.classList.remove("show");
          btn.setAttribute("aria-expanded", "false");
          const chevron = btn.querySelector(".fa-chevron-down");
          if (chevron) chevron.style.transform = "rotate(0deg)";
        }
      }
    });

    document.addEventListener("DOMContentLoaded", function() {
      const themeToggle = document.getElementById("themeToggleBtn");
      const themeIcon = document.getElementById("themeIcon");
      const html = document.documentElement;

      const savedTheme = localStorage.getItem("theme");
      if (savedTheme) {
        html.setAttribute("data-theme", savedTheme);
        updateThemeIcon(savedTheme);
      }

      themeToggle.addEventListener("click", function() {
        const currentTheme = html.getAttribute("data-theme");
        const newTheme = currentTheme === "dark" ? "light" : "dark";
        html.setAttribute("data-theme", newTheme);
        localStorage.setItem("theme", newTheme);
        updateThemeIcon(newTheme);
      });

      function updateThemeIcon(theme) {
        themeIcon.className = theme === "dark" ? "fas fa-sun" : "fas fa-moon";
      }
    });

    document.addEventListener("DOMContentLoaded", function() {
      const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
          if (entry.isIntersecting) {
            entry.target.classList.add('visible');
          }
        });
      }, {
        threshold: 0.1
      });

      const dashboardCard = document.getElementById('dashboardCard');
      if (dashboardCard) {
        observer.observe(dashboardCard);
      }
    });
  </script>
</body>

</html>
