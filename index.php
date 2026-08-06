<?php // Bootstrapper + Backend Integration
  require __DIR__ . '/bootstrap.php';
  
  $stats = getIssueStats($db);
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="light">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="description" content="CivicConnect - Crowdsourced Civic Issue Reporting & Resolution Platform">
  <meta name="theme-color" content="#4F46E5">

  <title>CivicConnect - Report & Track Civic Issues</title>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">
  <script src="https://kit.fontawesome.com/dba62debdb.js" crossorigin="anonymous"></script>
</head>

<body class="bg-body">

  <!-- ===== NAVBAR ===== -->
  <nav id="landingNavbar" class="navbar navbar-expand-lg sticky-top bg-body border-bottom shadow-sm">
    <div class="container-fluid px-4">
      <!-- Brand -->
      <a href="index.html" class="navbar-brand d-flex align-items-center gap-2 fw-bold text-primary">
        <span class="d-inline-flex align-items-center justify-content-center rounded-3 text-white bg-primary" style="width:36px;height:36px;">
          <i class="fas fa-city"></i>
        </span>
        <span>CivicConnect</span>
      </a>

      <!-- Navbar Toggler -->
      <button class="navbar-toggler border-0 shadow-none" type="button" data-bs-toggle="collapse" data-bs-target="#navLinks" aria-controls="navLinks" aria-expanded="false" aria-label="Toggle navigation">
        <span class="navbar-toggler-icon"></span>
      </button>

      <!-- Nav Links -->
      <div class="collapse navbar-collapse" id="navLinks">
        <ul class="navbar-nav mx-auto gap-3 gap-lg-4 my-2 my-lg-0">
          <li class="nav-item">
            <a href="#features" class="nav-link fw-medium active">Features</a>
          </li>
          <li class="nav-item">
            <a href="#how-it-works" class="nav-link fw-medium">How It Works</a>
          </li>
          <li class="nav-item">
            <a href="#testimonials" class="nav-link fw-medium">Testimonials</a>
          </li>
        </ul>
        
        <!-- Actions -->
        <div class="d-flex align-items-center gap-3">
          <button id="themeToggleBtn" class="btn btn-link text-body p-0 border-0" aria-label="Toggle theme">
            <i class="fas fa-moon fs-5" id="themeIcon"></i>
          </button>

          <!-- Get Started Native Bootstrap Dropdown -->
          <div class="dropdown">
            <button class="btn btn-primary rounded-pill px-4 dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
              Get Started
            </button>
            <ul class="dropdown-menu dropdown-menu-end shadow border-0 mt-2">
              <li>
                <a class="dropdown-item py-2 d-flex align-items-center" href="pages/register/index.php">
                  <i class="fas fa-user-plus me-2 text-primary"></i> Sign Up
                </a>
              </li>
              <li><hr class="dropdown-divider"></li>
              <li>
                <a class="dropdown-item py-2 d-flex align-items-center" href="pages/login/index.php">
                  <i class="fas fa-sign-in-alt me-2 text-primary"></i> Log In
                </a>
              </li>
            </ul>
          </div>
        </div>
      </div>
    </div>
  </nav>

  <!-- ===== HERO SECTION ===== -->
  <section id="heroSection" class="py-5 overflow-hidden d-flex align-items-center bg-body-tertiary min-vh-100">
    <div class="container">
      <div class="row align-items-center g-5 py-5">
        <div class="col-lg-6">
          <span class="badge bg-primary-subtle text-primary border border-primary-subtle rounded-pill px-3 py-2 mb-4 d-inline-flex align-items-center gap-2">
            <span class="d-inline-block rounded-circle bg-primary" style="width:8px;height:8px;"></span>
            Smart City Solution
          </span>

          <h1 class="display-4 fw-bold mb-4">
            Report &amp; Track <span class="text-primary">Civic Issues</span> in Your Community
          </h1>

          <p class="lead text-body-secondary mb-4">
            CivicConnect empowers citizens to report potholes, broken streetlights,
            water leakage, and garbage issues. Track progress in real-time and
            help build smarter cities together.
          </p>

          <div class="d-flex flex-wrap gap-3 mb-5">
            <a href="pages/register/index.php" class="btn btn-primary btn-lg rounded-pill px-5">
              <i class="fas fa-arrow-right me-2"></i> Get Started
            </a>
            <a href="#how-it-works" class="btn btn-outline-secondary btn-lg rounded-pill px-5">
              <i class="fas fa-play-circle me-2"></i> Learn More
            </a>
          </div>

          <div class="row g-4 pt-4 border-top">
            <div class="col-auto">
              <span class="d-block display-6 fw-bold text-primary"><?php echo formatNumber($stats['total'] ?? 0); ?></span>
              <span class="text-body-secondary">Issues Reported</span>
            </div>
            <div class="col-auto px-lg-4">
              <span class="d-block display-6 fw-bold text-success"><?php echo formatNumber($stats['resolved'] ?? 0); ?></span>
              <span class="text-body-secondary">Issues Resolved</span>
            </div>
            <div class="col-auto">
              <span class="d-block display-6 fw-bold text-primary"><?php echo htmlspecialchars($stats['satisfaction'] ?? '0%'); ?></span>
              <span class="text-body-secondary">Satisfaction Rate</span>
            </div>
          </div>
        </div>

        <div class="col-lg-6">
          <div class="card shadow-lg border-0 rounded-4 overflow-hidden">
            <div class="card-header bg-body-secondary border-0 d-flex align-items-center gap-2 p-3">
              <span class="d-inline-block rounded-circle bg-danger" style="width:12px;height:12px;"></span>
              <span class="d-inline-block rounded-circle bg-warning" style="width:12px;height:12px;"></span>
              <span class="d-inline-block rounded-circle bg-success" style="width:12px;height:12px;"></span>
              <span class="ms-2 fw-semibold">Issue Dashboard</span>
            </div>
            <div class="card-body p-4">
              <div class="row g-3 mb-4">
                <div class="col-4 text-center">
                  <span class="d-block display-5 fw-bold text-primary"><?php echo htmlspecialchars($stats['open'] ?? 0); ?></span>
                  <span class="text-body-secondary small">Open Issues</span>
                </div>
                <div class="col-4 text-center border-start border-end">
                  <span class="d-block display-5 fw-bold text-warning"><?php echo htmlspecialchars($stats['in_progress'] ?? 0); ?></span>
                  <span class="text-body-secondary small">In Progress</span>
                </div>
                <div class="col-4 text-center">
                  <span class="d-block display-5 fw-bold text-success"><?php echo htmlspecialchars($stats['resolved'] ?? 0); ?></span>
                  <span class="text-body-secondary small">Resolved</span>
                </div>
              </div>
              <div class="position-relative bg-body-tertiary rounded-3 p-4" style="min-height:250px;">
                <div class="position-absolute rounded-circle bg-danger shadow-sm" style="width:20px;height:20px;top:20%;left:30%;"></div>
                <div class="position-absolute rounded-circle bg-warning shadow-sm" style="width:20px;height:20px;top:40%;left:60%;"></div>
                <div class="position-absolute rounded-circle bg-success shadow-sm" style="width:20px;height:20px;top:70%;left:40%;"></div>
                <div class="position-absolute rounded-circle bg-info shadow-sm" style="width:20px;height:20px;top:30%;left:75%;"></div>
                <div class="position-absolute text-muted small fw-medium" style="bottom:15px;left:50%;transform:translateX(-50%);">
                  <i class="fas fa-map-marker-alt me-1"></i> Live Area Map
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- ===== FEATURES SECTION ===== -->
  <section id="features" class="py-5 py-lg-5 my-5 bg-body">
    <div class="container">
      <div class="text-center mb-5">
        <span class="badge bg-primary-subtle text-primary mb-3 px-3 py-2 rounded-pill">Features</span>
        <h2 class="display-5 fw-bold mb-3">
          Everything You Need to <span class="text-primary">Improve Your City</span>
        </h2>
        <p class="text-body-secondary mx-auto" style="max-width:640px;">
          Powerful tools for citizens, authorities, and administrators to work together efficiently.
        </p>
      </div>

      <div class="row g-4">
        <!-- Feature 1 -->
        <div class="col-md-6 col-lg-4">
          <div class="card border-0 shadow-sm rounded-4 h-100 p-4">
            <div class="d-inline-flex align-items-center justify-content-center rounded-3 bg-primary-subtle text-primary mb-4" style="width:56px;height:56px;">
              <i class="fas fa-camera fs-4"></i>
            </div>
            <h5 class="fw-semibold">AI-Powered Reporting</h5>
            <p class="text-body-secondary mb-0">Snap a photo and our AI automatically categorizes the issue. No manual labeling required.</p>
          </div>
        </div>
        <!-- Feature 2 -->
        <div class="col-md-6 col-lg-4">
          <div class="card border-0 shadow-sm rounded-4 h-100 p-4">
            <div class="d-inline-flex align-items-center justify-content-center rounded-3 bg-primary-subtle text-primary mb-4" style="width:56px;height:56px;">
              <i class="fas fa-location-dot fs-4"></i>
            </div>
            <h5 class="fw-semibold">Real-Time Tracking</h5>
            <p class="text-body-secondary mb-0">GPS-enabled location tracking. See reported issues on an interactive map with live status updates.</p>
          </div>
        </div>
        <!-- Feature 3 -->
        <div class="col-md-6 col-lg-4">
          <div class="card border-0 shadow-sm rounded-4 h-100 p-4">
            <div class="d-inline-flex align-items-center justify-content-center rounded-3 bg-primary-subtle text-primary mb-4" style="width:56px;height:56px;">
              <i class="fas fa-bolt fs-4"></i>
            </div>
            <h5 class="fw-semibold">Smart Prioritization</h5>
            <p class="text-body-secondary mb-0">AI assigns priority levels based on severity, location, and impact to ensure urgent issues are addressed first.</p>
          </div>
        </div>
        <!-- Feature 4 -->
        <div class="col-md-6 col-lg-4">
          <div class="card border-0 shadow-sm rounded-4 h-100 p-4">
            <div class="d-inline-flex align-items-center justify-content-center rounded-3 bg-primary-subtle text-primary mb-4" style="width:56px;height:56px;">
              <i class="fas fa-users fs-4"></i>
            </div>
            <h5 class="fw-semibold">Crowdsourced Transparency</h5>
            <p class="text-body-secondary mb-0">Upvote issues, add comments, and see what your community cares about most.</p>
          </div>
        </div>
        <!-- Feature 5 -->
        <div class="col-md-6 col-lg-4">
          <div class="card border-0 shadow-sm rounded-4 h-100 p-4">
            <div class="d-inline-flex align-items-center justify-content-center rounded-3 bg-primary-subtle text-primary mb-4" style="width:56px;height:56px;">
              <i class="fas fa-bell fs-4"></i>
            </div>
            <h5 class="fw-semibold">Instant Notifications</h5>
            <p class="text-body-secondary mb-0">Get notified when your issue is assigned, in progress, or resolved. Real-time updates via email and in-app.</p>
          </div>
        </div>
        <!-- Feature 6 -->
        <div class="col-md-6 col-lg-4">
          <div class="card border-0 shadow-sm rounded-4 h-100 p-4">
            <div class="d-inline-flex align-items-center justify-content-center rounded-3 bg-primary-subtle text-primary mb-4" style="width:56px;height:56px;">
              <i class="fas fa-chart-line fs-4"></i>
            </div>
            <h5 class="fw-semibold">Data Insights</h5>
            <p class="text-body-secondary mb-0">Analytics dashboards help municipalities identify patterns, allocate resources, and improve city planning.</p>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- ===== HOW IT WORKS SECTION ===== -->
  <section id="how-it-works" class="py-5 py-lg-5 bg-body-tertiary border-top border-bottom">
    <div class="container py-4">
      <div class="text-center mb-5">
        <span class="badge bg-primary-subtle text-primary mb-3 px-3 py-2 rounded-pill">How It Works</span>
        <h2 class="display-5 fw-bold mb-3">
          Simple <span class="text-primary">3-Step</span> Process
        </h2>
        <p class="text-body-secondary mx-auto" style="max-width:640px;">
          From reporting to resolution — transparent and efficient.
        </p>
      </div>

      <div class="row g-5">
        <!-- Step 1 -->
        <div class="col-md-4 text-center">
          <div class="d-inline-flex align-items-center justify-content-center rounded-circle bg-primary text-white fs-3 fw-bold mb-4 shadow" style="width:72px;height:72px;">1</div>
          <h5 class="fw-semibold">Report Issue</h5>
          <p class="text-body-secondary">Snap a photo, add location via GPS, and submit a brief description. AI auto-categorizes your issue.</p>
        </div>
        <!-- Step 2 -->
        <div class="col-md-4 text-center">
          <div class="d-inline-flex align-items-center justify-content-center rounded-circle bg-primary text-white fs-3 fw-bold mb-4 shadow" style="width:72px;height:72px;">2</div>
          <h5 class="fw-semibold">Authority Takes Action</h5>
          <p class="text-body-secondary">Municipal authorities prioritize, assign to relevant departments, and update status in real-time.</p>
        </div>
        <!-- Step 3 -->
        <div class="col-md-4 text-center">
          <div class="d-inline-flex align-items-center justify-content-center rounded-circle bg-primary text-white fs-3 fw-bold mb-4 shadow" style="width:72px;height:72px;">3</div>
          <h5 class="fw-semibold">Get Notified</h5>
          <p class="text-body-secondary">Receive instant notifications when your issue is resolved. Rate the resolution and give feedback.</p>
        </div>
      </div>
    </div>
  </section>

  <!-- ===== TESTIMONIALS SECTION ===== -->
  <section id="testimonials" class="py-5 py-lg-5 my-5 bg-body">
    <div class="container">
      <div class="text-center mb-5">
        <span class="badge bg-primary-subtle text-primary mb-3 px-3 py-2 rounded-pill">Testimonials</span>
        <h2 class="display-5 fw-bold mb-3">
          What People Are <span class="text-primary">Saying</span>
        </h2>
        <p class="text-body-secondary mx-auto" style="max-width:640px;">
          Join thousands of satisfied citizens using CivicConnect.
        </p>
      </div>

      <div class="row g-4">
        <div class="col-md-4">
          <div class="card border-0 shadow-sm rounded-4 p-4 h-100 bg-body-tertiary">
            <div class="text-warning mb-3 fs-5">
              <i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i>
            </div>
            <p class="mb-4 text-body-secondary flex-grow-1">"I reported a pothole on my street and it was fixed within 3 days! The AI categorization was spot on and I loved getting updates."</p>
            <div class="d-flex align-items-center gap-3 mt-auto">
              <div class="d-inline-flex align-items-center justify-content-center rounded-circle bg-primary text-white fw-semibold" style="width:48px;height:48px;">AK</div>
              <div>
                <div class="fw-semibold mb-0">Amit Kumar</div>
                <div class="text-body-secondary small">Resident, Delhi</div>
              </div>
            </div>
          </div>
        </div>

        <div class="col-md-4">
          <div class="card border-0 shadow-sm rounded-4 p-4 h-100 bg-body-tertiary">
            <div class="text-warning mb-3 fs-5">
              <i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i>
            </div>
            <p class="mb-4 text-body-secondary flex-grow-1">"As a municipal officer, CivicConnect has revolutionized how we track and resolve complaints. The dashboard gives us clear visibility."</p>
            <div class="d-flex align-items-center gap-3 mt-auto">
              <div class="d-inline-flex align-items-center justify-content-center rounded-circle bg-primary text-white fw-semibold" style="width:48px;height:48px;">PS</div>
              <div>
                <div class="fw-semibold mb-0">Priya Sharma</div>
                <div class="text-body-secondary small">Municipal Officer</div>
              </div>
            </div>
          </div>
        </div>

        <div class="col-md-4">
          <div class="card border-0 shadow-sm rounded-4 p-4 h-100 bg-body-tertiary">
            <div class="text-warning mb-3 fs-5">
              <i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i>
            </div>
            <p class="mb-4 text-body-secondary flex-grow-1">"The transparency is amazing. I can see what issues my neighbors are reporting and upvote them. Real community collaboration!"</p>
            <div class="d-flex align-items-center gap-3 mt-auto">
              <div class="d-inline-flex align-items-center justify-content-center rounded-circle bg-primary text-white fw-semibold" style="width:48px;height:48px;">RJ</div>
              <div>
                <div class="fw-semibold mb-0">Rahul Joshi</div>
                <div class="text-body-secondary small">Community Leader</div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- ===== CTA SECTION ===== -->
  <section class="py-5 bg-primary-subtle position-relative overflow-hidden">
    <div class="container position-relative z-1 py-5">
      <div class="row justify-content-center">
        <div class="col-lg-8 text-center">
          <div class="card border-0 shadow-sm rounded-4 p-5 bg-body">
            <h2 class="display-5 fw-bold mb-4">
              Ready to Make Your <span class="text-primary">City Better</span>?
            </h2>
            <p class="lead text-body-secondary mb-5">
              Join CivicConnect today and be part of the change. Report issues, track progress, and help build smarter cities.
            </p>
            <div class="d-flex flex-wrap gap-3 justify-content-center">
              <a href="pages/register/index.php" class="btn btn-primary btn-lg rounded-pill px-5">
                <i class="fas fa-rocket me-2"></i> Get Started Free
              </a>
              <a href="pages/login/index.php" class="btn btn-outline-primary btn-lg rounded-pill px-5">
                <i class="fas fa-sign-in-alt me-2"></i> Sign In
              </a>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- ===== FOOTER ===== -->
  <footer class="bg-dark text-secondary py-5">
    <div class="container py-4">
      <div class="row g-4">
        <div class="col-lg-4">
          <div class="d-flex align-items-center gap-2 mb-4">
            <span class="d-inline-flex align-items-center justify-content-center rounded-3 bg-primary text-white" style="width:36px;height:36px;">
              <i class="fas fa-city"></i>
            </span>
            <span class="text-white fs-5 fw-bold">CivicConnect</span>
          </div>
          <p class="mb-4 pe-lg-4">Empowering citizens to build smarter, cleaner, and safer cities through technology and collaboration.</p>
          <div class="d-flex gap-3">
            <a href="#" class="btn btn-outline-secondary border-0 rounded-circle"><i class="fab fa-facebook-f"></i></a>
            <a href="#" class="btn btn-outline-secondary border-0 rounded-circle"><i class="fab fa-twitter"></i></a>
            <a href="#" class="btn btn-outline-secondary border-0 rounded-circle"><i class="fab fa-linkedin-in"></i></a>
            <a href="#" class="btn btn-outline-secondary border-0 rounded-circle"><i class="fab fa-youtube"></i></a>
          </div>
        </div>

        <div class="col-6 col-md-3">
          <h5 class="text-white mb-4">Platform</h5>
          <ul class="list-unstyled d-flex flex-column gap-2">
            <li><a href="#features" class="text-secondary text-decoration-none">Features</a></li>
            <li><a href="#how-it-works" class="text-secondary text-decoration-none">How It Works</a></li>
            <li><a href="#" class="text-secondary text-decoration-none">Pricing</a></li>
            <li><a href="#" class="text-secondary text-decoration-none">FAQ</a></li>
          </ul>
        </div>

        <div class="col-6 col-md-3">
          <h5 class="text-white mb-4">Support</h5>
          <ul class="list-unstyled d-flex flex-column gap-2">
            <li><a href="#" class="text-secondary text-decoration-none">Help Center</a></li>
            <li><a href="#" class="text-secondary text-decoration-none">Contact Us</a></li>
            <li><a href="#" class="text-secondary text-decoration-none">Privacy Policy</a></li>
            <li><a href="#" class="text-secondary text-decoration-none">Terms of Service</a></li>
          </ul>
        </div>

        <div class="col-6 col-md-2">
          <h5 class="text-white mb-4">Company</h5>
          <ul class="list-unstyled d-flex flex-column gap-2">
            <li><a href="#" class="text-secondary text-decoration-none">About Us</a></li>
            <li><a href="#" class="text-secondary text-decoration-none">Careers</a></li>
            <li><a href="#" class="text-secondary text-decoration-none">Blog</a></li>
            <li><a href="#" class="text-secondary text-decoration-none">Press Kit</a></li>
          </ul>
        </div>
      </div>

      <hr class="border-secondary my-4">
      <div class="text-center small">
        <p class="mb-0">&copy; 2026 CivicConnect. All rights reserved. Built with ❤️ for smarter cities.</p>
      </div>
    </div>
  </footer>

  <!-- Bootstrap 5 JS Bundle with Popper -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
  
  <script>
    // Navbar links active state handler
    document.addEventListener('DOMContentLoaded', function() {
      const navLinks = document.querySelectorAll('.navbar-nav .nav-link');
      navLinks.forEach(function(link) {
        link.addEventListener('click', function() {
          navLinks.forEach(l => l.classList.remove('active'));
          this.classList.add('active');
        });
      });
    });
  </script>
</body>
</html>