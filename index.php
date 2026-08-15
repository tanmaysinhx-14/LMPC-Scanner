<?php // Bootstrapper + Backend Integration
  require __DIR__ . '/bootstrap.php';
  $landingStats = getIssueStats(connectDatabase());
  $landingTotal = (int) ($landingStats['total'] ?? 0);
  $landingResolved = (int) ($landingStats['resolved'] ?? 0);
  $landingResolutionRate = $landingTotal > 0
    ? (int) round(($landingResolved / $landingTotal) * 100)
    : 0;
?>
<!doctype html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="CivicConnect - Crowdsourced Civic Issue Reporting & Resolution Platform">
    <meta name="theme-color" content="#4F46E5">
    
    <title>CivicConnect - Report & Track Civic Issues</title>
    
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Main Styles -->
    <link rel="stylesheet" href="assets/css/style.css">
    
    <style>
        /* ===== CUSTOM SCROLLBAR ===== */
        ::-webkit-scrollbar {
            width: 8px;
        }
        ::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 10px;
        }
        ::-webkit-scrollbar-thumb {
            background: linear-gradient(135deg, #4F46E5, #7C3AED);
            border-radius: 10px;
            transition: background 0.3s;
        }
        ::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(135deg, #3730A3, #6D28D9);
        }
        /* Firefox scrollbar */
        * {
            scrollbar-width: thin;
            scrollbar-color: #4F46E5 #f1f1f1;
        }

        /* ===== FEED STYLES ===== */
        .feed-section {
            background: #f6f7f8;
            padding: 40px 0;
            min-height: 100vh;
        }
        .feed-post {
            background: #ffffff;
            border-radius: 16px;
            border: 1px solid #e9edf0;
            padding: 16px 20px;
            transition: all 0.2s ease;
            margin-bottom: 16px;
        }
        .feed-post:hover {
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            border-color: #d4d9de;
        }
        .post-subreddit {
            font-weight: 600;
            font-size: 13px;
            color: #1a1a1b;
        }
        .post-subreddit span {
            color: #787c7e;
            font-weight: 400;
        }
        .post-title {
            font-size: 18px;
            font-weight: 600;
            color: #1a1a1b;
            margin: 4px 0 6px;
            cursor: pointer;
            transition: color 0.15s;
        }
        .post-title:hover {
            color: #4F46E5;
        }
        .post-meta {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 8px 12px;
            font-size: 12px;
            color: #787c7e;
            margin-bottom: 12px;
        }
        .post-meta .author {
            color: #1a1a1b;
            font-weight: 500;
        }
        .post-meta .badge-location {
            background: #e9edf0;
            color: #1a1a1b;
            font-weight: 500;
            padding: 2px 10px;
            border-radius: 20px;
            font-size: 11px;
        }
        .post-meta .badge-type {
            background: #4F46E5;
            color: white;
            font-weight: 500;
            padding: 2px 10px;
            border-radius: 20px;
            font-size: 11px;
        }
        .post-image {
            border-radius: 12px;
            margin: 8px 0 12px;
            width: 100%;
            max-height: 360px;
            object-fit: cover;
            background: #e9edf0;
        }
        .post-actions {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 4px;
            padding-top: 10px;
            border-top: 1px solid #edf0f2;
        }
        .vote-btn {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            background: none;
            border: none;
            padding: 6px 12px;
            border-radius: 24px;
            font-size: 14px;
            font-weight: 600;
            color: #787c7e;
            cursor: pointer;
            transition: all 0.15s;
        }
        .vote-btn:hover {
            background: #f0f1f3;
        }
        .vote-btn.upvote:hover {
            color: #ff4500;
        }
        .vote-btn.downvote:hover {
            color: #7193ff;
        }
        .vote-btn.voted-up {
            color: #ff4500;
            background: #fff0ed;
        }
        .vote-btn.voted-down {
            color: #7193ff;
            background: #edf2ff;
        }
        .vote-count {
            font-weight: 700;
            font-size: 14px;
            min-width: 24px;
            text-align: center;
            color: #1a1a1b;
        }
        .action-btn {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            background: none;
            border: none;
            padding: 6px 14px;
            border-radius: 24px;
            font-size: 13px;
            color: #787c7e;
            cursor: pointer;
            transition: all 0.15s;
        }
        .action-btn:hover {
            background: #f0f1f3;
            color: #1a1a1b;
        }

        /* ===== STICKY RIGHT SIDEBAR ===== */
        .sticky-sidebar {
            position: sticky;
            top: 80px;
            align-self: flex-start;
        }

        /* ===== ISSUE DASHBOARD CARD ===== */
        .dashboard-card {
            background: #ffffff;
            border-radius: 16px;
            border: 1px solid #e9edf0;
            padding: 0;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
            transition: all 0.3s ease;
        }
        .dashboard-card:hover {
            box-shadow: 0 8px 24px rgba(0,0,0,0.08);
        }
        .dashboard-card .card-header {
            background: transparent;
            border-bottom: 1px solid #e9edf0;
            padding: 16px 20px;
            font-weight: 600;
        }
        .dashboard-card .card-body {
            padding: 20px;
        }
        .dashboard-stat {
            text-align: center;
        }
        .dashboard-stat .number {
            font-size: 28px;
            font-weight: 700;
            line-height: 1.2;
        }
        .dashboard-stat .label {
            font-size: 13px;
            color: #787c7e;
        }

        /* ===== HEATMAP STYLES ===== */
        .map-card {
            background: #ffffff;
            border-radius: 16px;
            border: 1px solid #e9edf0;
            padding: 20px;
            margin-top: 20px;
        }
        .map-title {
            font-size: 18px;
            font-weight: 700;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .map-title i {
            color: #ff4500;
        }
        .map-container {
            position: relative;
            width: 100%;
            aspect-ratio: 1/1;
            background: #eef2f5;
            border-radius: 12px;
            overflow: hidden;
        }
        .map-container svg {
            width: 100%;
            height: 100%;
        }
        .map-legend {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            margin-top: 14px;
            font-size: 12px;
            color: #787c7e;
        }
        .legend-gradient {
            width: 140px;
            height: 8px;
            border-radius: 4px;
            background: linear-gradient(to right, #e5f5e5, #ffeb99, #ffb366, #ff6b6b);
        }
        .map-stats {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-top: 14px;
        }
        .map-stat {
            background: #f6f7f8;
            padding: 10px 14px;
            border-radius: 10px;
            text-align: center;
        }
        .map-stat .num {
            font-weight: 700;
            font-size: 18px;
            color: #1a1a1b;
        }
        .map-stat .label {
            font-size: 11px;
            color: #787c7e;
        }
        .map-stat .num.high { color: #ff4500; }
        .map-stat .num.medium { color: #ffb366; }
        .map-stat .num.low { color: #f59e0b; }
        .map-stat .num.total { color: #4F46E5; }

        /* ===== IMAGE SECTION INSIDE MAP ===== */
        .map-image-section {
            position: absolute;
            bottom: 12px;
            left: 12px;
            right: 12px;
            background: rgba(0, 0, 0, 0.7);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            border-radius: 10px;
            padding: 12px 16px;
            display: flex;
            align-items: center;
            gap: 12px;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        .map-image-section .map-image-icon {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            background: rgba(255, 255, 255, 0.1);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 18px;
            flex-shrink: 0;
        }
        .map-image-section .map-image-content {
            flex: 1;
        }
        .map-image-section .map-image-title {
            color: white;
            font-size: 13px;
            font-weight: 600;
            margin: 0;
        }
        .map-image-section .map-image-subtitle {
            color: rgba(255, 255, 255, 0.6);
            font-size: 11px;
            margin: 0;
        }
        .map-image-section .map-image-preview {
            width: 48px;
            height: 48px;
            border-radius: 8px;
            object-fit: cover;
            border: 2px solid rgba(255, 255, 255, 0.2);
            cursor: pointer;
            transition: all 0.3s;
        }
        .map-image-section .map-image-preview:hover {
            border-color: white;
            transform: scale(1.05);
        }

        /* ===== SORT BAR ===== */
        .sort-bar {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 8px;
            padding: 12px 0 16px;
            border-bottom: 1px solid #e9edf0;
            margin-bottom: 16px;
        }
        .sort-btn {
            padding: 6px 16px;
            border-radius: 24px;
            border: 1px solid #e9edf0;
            background: white;
            font-size: 13px;
            font-weight: 500;
            color: #787c7e;
            cursor: pointer;
            transition: all 0.15s;
        }
        .sort-btn:hover {
            background: #f0f1f3;
            border-color: #d4d9de;
        }
        .sort-btn.active {
            background: #4F46E5;
            border-color: #4F46E5;
            color: white;
        }

        /* ===== ANIMATIONS ===== */
        .slide-in-right {
            opacity: 0;
            transform: translateX(60px);
            transition: all 0.8s cubic-bezier(0.16, 1, 0.3, 1);
        }
        .slide-in-right.visible {
            opacity: 1;
            transform: translateX(0);
        }

        /* ===== RESPONSIVE ===== */
        @media (max-width: 992px) {
            .sticky-sidebar {
                position: relative;
                top: 0;
                margin-top: 20px;
            }
        }
        @media (max-width: 576px) {
            .feed-section {
                padding: 20px 0;
            }
            .feed-post {
                padding: 12px 14px;
            }
            .post-title {
                font-size: 16px;
            }
            .post-image {
                max-height: 200px;
            }
            .post-actions {
                gap: 2px;
            }
            .vote-btn, .action-btn {
                padding: 4px 8px;
                font-size: 12px;
            }
            .map-stats {
                grid-template-columns: 1fr 1fr;
            }
            .map-image-section {
                padding: 8px 12px;
            }
            .map-image-section .map-image-preview {
                width: 36px;
                height: 36px;
            }
            .dashboard-stat .number {
                font-size: 22px;
            }
        }
    </style>
</head>
<body>

    <!-- ============================================
    LANDING PAGE - CivicConnect
    ============================================ -->

    <!-- ===== NAVBAR ===== -->
    <nav id="landingNavbar" class="navbar navbar-expand-lg sticky-top bg-white bg-opacity-80 backdrop-blur border-bottom" role="navigation" aria-label="Main navigation">
        <div class="container-fluid px-4">
            <!-- Brand -->
            <a href="<?= htmlspecialchars(civicRoute('home'), ENT_QUOTES, 'UTF-8') ?>" id="brandLink" class="navbar-brand d-flex align-items-center gap-2 fw-bold text-primary" aria-label="CivicConnect Home">
                <span class="brand-icon d-inline-flex align-items-center justify-content-center rounded-3 text-white" style="width:36px;height:36px;background:var(--color-primary-gradient);">
                    <i class="fas fa-city"></i>
                </span>
                <span class="brand-text">CivicConnect</span>
            </a>
            
            <!-- Navbar Toggler -->
            <button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse" data-bs-target="#navLinks" aria-controls="navLinks" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            
            <!-- Nav Links -->
            <div class="collapse navbar-collapse" id="navLinks">
                <ul class="navbar-nav mx-auto gap-3 gap-lg-4">
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
                        <a href="<?= htmlspecialchars(civicRoute('feed'), ENT_QUOTES, 'UTF-8') ?>" class="nav-link fw-medium text-secondary position-relative">Community Feed</a>
                    </li>
                </ul>
            </div>
            
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
                        <a href="<?= htmlspecialchars(civicRoute('register'), ENT_QUOTES, 'UTF-8') ?>" class="dropdown-item py-2" role="menuitem">
                            <i class="fas fa-user-plus me-2 text-primary"></i>
                            Sign Up
                        </a>
                        <div class="dropdown-divider"></div>
                        <a href="<?= htmlspecialchars(civicRoute('login'), ENT_QUOTES, 'UTF-8') ?>" class="dropdown-item py-2" role="menuitem">
                            <i class="fas fa-sign-in-alt me-2 text-primary"></i>
                            Log In
                        </a>
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
                            <a href="<?= htmlspecialchars(civicRoute('register'), ENT_QUOTES, 'UTF-8') ?>" id="heroGetStartedBtn" class="btn btn-primary btn-lg rounded-pill px-5">
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
                            <a href="<?= htmlspecialchars(civicRoute('register'), ENT_QUOTES, 'UTF-8') ?>" id="ctaGetStartedBtn" class="btn btn-primary btn-lg rounded-pill px-5">
                                <i class="fas fa-rocket me-2"></i>
                                Get Started Free
                            </a>
                            <a href="<?= htmlspecialchars(civicRoute('login'), ENT_QUOTES, 'UTF-8') ?>" id="ctaLoginBtn" class="btn btn-outline-primary btn-lg rounded-pill px-5">
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
                        <li><a href="<?= htmlspecialchars(civicRoute('feed'), ENT_QUOTES, 'UTF-8') ?>" class="text-white-50 text-decoration-none hover-text-white">Community Feed</a></li>
                        <li><a href="<?= htmlspecialchars(civicRoute('pulse'), ENT_QUOTES, 'UTF-8') ?>" class="text-white-50 text-decoration-none hover-text-white">City Pulse</a></li>
                        <li><a href="<?= htmlspecialchars(civicRoute('report'), ENT_QUOTES, 'UTF-8') ?>" class="text-white-50 text-decoration-none hover-text-white">Report an Issue</a></li>
                        <li><a href="<?= htmlspecialchars(civicRoute('register'), ENT_QUOTES, 'UTF-8') ?>" class="text-white-50 text-decoration-none hover-text-white">Create an Account</a></li>
                    </ul>
                </div>
                
                <div class="col-6 col-md-3">
                    <h5 class="text-white mb-3">Support</h5>
                    <ul class="list-unstyled">
                        <li><a href="<?= htmlspecialchars(civicRoute('login'), ENT_QUOTES, 'UTF-8') ?>" class="text-white-50 text-decoration-none hover-text-white">Account Access</a></li>
                        <li><a href="<?= htmlspecialchars(civicRoute('feed'), ENT_QUOTES, 'UTF-8') ?>" class="text-white-50 text-decoration-none hover-text-white">Track Reports</a></li>
                        <li><a href="#how-it-works" class="text-white-50 text-decoration-none hover-text-white">How It Works</a></li>
                        <li><a href="<?= htmlspecialchars(civicRoute('pulse'), ENT_QUOTES, 'UTF-8') ?>" class="text-white-50 text-decoration-none hover-text-white">Map Help</a></li>
                    </ul>
                </div>
                
                <div class="col-6 col-md-2">
                    <h5 class="text-white mb-3">Company</h5>
                    <ul class="list-unstyled">
                        <li><a href="#features" class="text-white-50 text-decoration-none hover-text-white">About the Platform</a></li>
                        <li><a href="#testimonials" class="text-white-50 text-decoration-none hover-text-white">Community Stories</a></li>
                        <li><a href="<?= htmlspecialchars(civicRoute('login'), ENT_QUOTES, 'UTF-8') ?>" class="text-white-50 text-decoration-none hover-text-white">Staff Access</a></li>
                        <li><a href="<?= htmlspecialchars(civicRoute('register'), ENT_QUOTES, 'UTF-8') ?>" class="text-white-50 text-decoration-none hover-text-white">Join CivicConnect</a></li>
                    </ul>
                </div>
            </div>
            
            <hr class="border-white-10 my-4">
            <div class="text-center">
                <p class="mb-0">&copy; 2026 CivicConnect. All rights reserved. Built for smarter cities.</p>
            </div>
        </div>
    </footer>

    <!-- ============================================
    SCRIPTS
    ============================================ -->
    
    <!-- Bootstrap 5 JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // ============================================
        // DROPDOWN TOGGLE FUNCTIONS
        // ============================================

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

        document.addEventListener("click", function (event) {
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

        document.addEventListener("keydown", function (event) {
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

        // ============================================
        // THEME TOGGLE
        // ============================================
        document.addEventListener("DOMContentLoaded", function () {
            const themeToggle = document.getElementById("themeToggleBtn");
            const themeIcon = document.getElementById("themeIcon");
            const html = document.documentElement;

            const savedTheme = localStorage.getItem("theme");
            if (savedTheme) {
                html.setAttribute("data-theme", savedTheme);
                updateThemeIcon(savedTheme);
            }

            themeToggle.addEventListener("click", function () {
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

        // ============================================
        // ============================================
        // SCROLL ANIMATION - Slide in from right
        // ============================================
        document.addEventListener("DOMContentLoaded", function () {
            const observer = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        entry.target.classList.add('visible');
                    }
                });
            }, { threshold: 0.1 });

            const dashboardCard = document.getElementById('dashboardCard');
            if (dashboardCard) {
                observer.observe(dashboardCard);
            }
        });

        // ============================================
        // REDDIT-STYLE FEED DATA (50 Posts)
        // ============================================
        const locations = [
            "Anna Nagar", "T Nagar", "Mylapore", "Adyar", "Velachery", "Tambaram",
            "Porur", "Guindy", "Egmore", "Nungambakkam", "Kilpauk", "Saidapet",
            "Royapettah", "Triplicane", "Kodambakkam", "Alwarpet", "Besant Nagar",
            "Thiruvanmiyur", "Perungudi", "Sholinganallur", "Medavakkam", "Kovilambakkam",
            "Chromepet", "Pallavaram", "Kundrathur", "Mugappair", "Ambattur", "Avadi",
            "Poonamallee", "Kattupakkam", "Villivakkam", "Kolathur", "Perambur", "Purasawalkam"
        ];

        const issueTypes = [
            "Pothole", "Broken Streetlight", "Water Leakage", "Garbage Collection",
            "Drainage Blockage", "Road Damage", "Tree Fall", "Electricity Outage",
            "Sewage Overflow", "Public Toilet Issue", "Footpath Damage", "Signal Malfunction",
            "Noise Complaint", "Park Maintenance", "Bus Stop Damage", "Graffiti Removal"
        ];

        const usernames = [
            "city_watcher", "civic_mind", "urban_hero", "street_smart", "clean_city",
            "delhi_dweller", "mumbai_maverick", "chennai_citizen", "bangalore_boy",
            "hyderabad_hero", "pune_pioneer", "kolkata_knight", "ahmedabad_activist",
            "surat_superstar", "jaipur_journalist", "lucknow_lover", "patna_patriot",
            "bhopal_brave", "nagpur_navigator", "indore_innovator"
        ];

        const titles = [
            "Huge pothole causing traffic jam",
            "Streetlight broken for 2 weeks",
            "Water pipe burst on main road",
            "Garbage piling up for days",
            "Sewage overflow on footpath",
            "Tree branch fell on road",
            "No electricity since morning",
            "Footpath completely damaged",
            "Traffic signal not working",
            "Loud noise from construction",
            "Park benches are broken",
            "Bus stop shelter damaged",
            "Graffiti on heritage wall",
            "Drain water flooding street",
            "Road repair needed urgently",
            "Street dog issue in colony",
            "Overflowing public toilet",
            "Pothole near school entrance",
            "Broken manhole cover",
            "Cracked footpath tiles",
            "Blocked drainage pipe",
            "Fallen tree blocking road",
            "Streetlight flickering all night",
            "Garbage truck missing route",
            "Water pressure too low"
        ];

        const postImages = [
            'https://picsum.photos/seed/issue1/800/400',
            'https://picsum.photos/seed/issue2/800/400',
            'https://picsum.photos/seed/issue3/800/400',
            'https://picsum.photos/seed/issue4/800/400',
            'https://picsum.photos/seed/issue5/800/400',
            'https://picsum.photos/seed/issue6/800/400',
            'https://picsum.photos/seed/issue7/800/400',
            'https://picsum.photos/seed/issue8/800/400',
            'https://picsum.photos/seed/issue9/800/400',
            'https://picsum.photos/seed/issue10/800/400',
            'https://picsum.photos/seed/issue11/800/400',
            'https://picsum.photos/seed/issue12/800/400',
            'https://picsum.photos/seed/issue13/800/400',
            'https://picsum.photos/seed/issue14/800/400',
            'https://picsum.photos/seed/issue15/800/400',
            'https://picsum.photos/seed/issue16/800/400',
            'https://picsum.photos/seed/issue17/800/400',
            'https://picsum.photos/seed/issue18/800/400',
            'https://picsum.photos/seed/issue19/800/400',
            'https://picsum.photos/seed/issue20/800/400',
            'https://picsum.photos/seed/issue21/800/400',
            'https://picsum.photos/seed/issue22/800/400',
            'https://picsum.photos/seed/issue23/800/400',
            'https://picsum.photos/seed/issue24/800/400',
            'https://picsum.photos/seed/issue25/800/400',
            'https://picsum.photos/seed/issue26/800/400',
            'https://picsum.photos/seed/issue27/800/400',
            'https://picsum.photos/seed/issue28/800/400',
            'https://picsum.photos/seed/issue29/800/400',
            'https://picsum.photos/seed/issue30/800/400',
            'https://picsum.photos/seed/issue31/800/400',
            'https://picsum.photos/seed/issue32/800/400',
            'https://picsum.photos/seed/issue33/800/400',
            'https://picsum.photos/seed/issue34/800/400',
            'https://picsum.photos/seed/issue35/800/400',
            'https://picsum.photos/seed/issue36/800/400',
            'https://picsum.photos/seed/issue37/800/400',
            'https://picsum.photos/seed/issue38/800/400',
            'https://picsum.photos/seed/issue39/800/400',
            'https://picsum.photos/seed/issue40/800/400',
            'https://picsum.photos/seed/issue41/800/400',
            'https://picsum.photos/seed/issue42/800/400',
            'https://picsum.photos/seed/issue43/800/400',
            'https://picsum.photos/seed/issue44/800/400',
            'https://picsum.photos/seed/issue45/800/400',
            'https://picsum.photos/seed/issue46/800/400',
            'https://picsum.photos/seed/issue47/800/400',
            'https://picsum.photos/seed/issue48/800/400',
            'https://picsum.photos/seed/issue49/800/400',
            'https://picsum.photos/seed/issue50/800/400'
        ];

        function getRandomItem(arr) {
            return arr[Math.floor(Math.random() * arr.length)];
        }

        function getRandomInt(min, max) {
            return Math.floor(Math.random() * (max - min + 1)) + min;
        }

        function generatePosts(count) {
            const posts = [];
            const usedTitles = new Set();
            
            for (let i = 0; i < count; i++) {
                let title = getRandomItem(titles);
                let counter = 1;
                while (usedTitles.has(title + (counter > 1 ? ' ' + counter : ''))) {
                    counter++;
                    title = title + ' ' + counter;
                }
                const finalTitle = title + (counter > 1 ? ' ' + counter : '');
                usedTitles.add(finalTitle);
                
                const location = getRandomItem(locations);
                const username = getRandomItem(usernames);
                const type = getRandomItem(issueTypes);
                const upvotes = getRandomInt(10, 450);
                const comments = getRandomInt(2, 60);
                const timeAgo = getRandomInt(1, 48);
                
                posts.push({
                    id: i + 1,
                    title: finalTitle,
                    author: username,
                    location: location,
                    type: type,
                    upvotes: upvotes,
                    comments: comments,
                    timeAgo: timeAgo + 'h',
                    image: postImages[i % postImages.length]
                });
            }
            return posts;
        }

        // Render posts
        let currentPosts = [];

        function renderPosts(posts) {
            const container = document.getElementById('feedPosts');
            currentPosts = posts;

            // The live community feed is a separate database-backed page.
            // Keep this legacy helper harmless if an old cached script calls it.
            if (!container) return;
            
            container.innerHTML = '';
            
            posts.forEach(post => {
                const postEl = document.createElement('div');
                postEl.className = 'feed-post';
                postEl.innerHTML = `
                    <div class="d-flex align-items-center gap-2 mb-1">
                        <span class="post-subreddit">r/CivicConnect <span>• Posted by u/${post.author}</span></span>
                    </div>
                    <div class="post-title">${post.title}</div>
                    <div class="post-meta">
                        <span class="author">u/${post.author}</span>
                        <span>•</span>
                        <span class="badge-location"><i class="fas fa-map-pin me-1"></i>${post.location}</span>
                        <span>•</span>
                        <span class="badge-type">${post.type}</span>
                        <span>•</span>
                        <span>${post.timeAgo} ago</span>
                    </div>
                    <img src="${post.image}" alt="${post.title}" class="post-image" loading="lazy">
                    <div class="post-actions">
                        <button class="vote-btn upvote" onclick="handleVote(this, 'up')">
                            <i class="fas fa-arrow-up"></i>
                        </button>
                        <span class="vote-count" id="voteCount_${post.id}">${post.upvotes}</span>
                        <button class="vote-btn downvote" onclick="handleVote(this, 'down')">
                            <i class="fas fa-arrow-down"></i>
                        </button>
                        <button class="action-btn">
                            <i class="far fa-comment"></i> ${post.comments}
                        </button>
                        <button class="action-btn">
                            <i class="fas fa-share-alt"></i> Share
                        </button>
                        <button class="action-btn">
                            <i class="far fa-bookmark"></i> Save
                        </button>
                    </div>
                `;
                container.appendChild(postEl);
            });
        }

        // Handle voting
        function handleVote(btn, direction) {
            const post = btn.closest('.feed-post');
            const voteCount = post.querySelector('.vote-count');
            const upvoteBtn = post.querySelector('.upvote');
            const downvoteBtn = post.querySelector('.downvote');
            
            let currentValue = parseInt(voteCount.textContent);
            
            if (btn.classList.contains('voted-up') || btn.classList.contains('voted-down')) {
                if (btn.classList.contains('voted-up')) {
                    currentValue -= 1;
                    btn.classList.remove('voted-up');
                } else if (btn.classList.contains('voted-down')) {
                    currentValue += 1;
                    btn.classList.remove('voted-down');
                }
                voteCount.textContent = currentValue;
                return;
            }
            
            if (direction === 'up') {
                if (downvoteBtn.classList.contains('voted-down')) {
                    downvoteBtn.classList.remove('voted-down');
                    currentValue += 1;
                }
                btn.classList.add('voted-up');
                currentValue += 1;
            } else {
                if (upvoteBtn.classList.contains('voted-up')) {
                    upvoteBtn.classList.remove('voted-up');
                    currentValue -= 1;
                }
                btn.classList.add('voted-down');
                currentValue -= 1;
            }
            
            voteCount.textContent = currentValue;
        }

        // ============================================
        // FILTER POSTS - FIXED
        // ============================================
        function filterPosts(filter) {
            const buttons = document.querySelectorAll('.sort-btn');
            buttons.forEach(btn => btn.classList.remove('active'));
            
            // Find and activate the clicked button
            buttons.forEach(btn => {
                const btnText = btn.textContent.trim();
                if ((filter === 'hot' && btnText.includes('Hot')) ||
                    (filter === 'new' && btnText.includes('New')) ||
                    (filter === 'top' && btnText.includes('Top')) ||
                    (filter === 'rising' && btnText.includes('Rising'))) {
                    btn.classList.add('active');
                }
            });
            
            let sorted = [...currentPosts];
            if (filter === 'hot') {
                sorted.sort((a, b) => (b.upvotes + b.comments) - (a.upvotes + a.comments));
            } else if (filter === 'new') {
                sorted.sort((a, b) => b.id - a.id);
            } else if (filter === 'top') {
                sorted.sort((a, b) => b.upvotes - a.upvotes);
            } else if (filter === 'rising') {
                sorted.sort((a, b) => (b.upvotes / (parseInt(b.timeAgo) || 1)) - (a.upvotes / (parseInt(a.timeAgo) || 1)));
            }
            renderPosts(sorted);
        }

        // Load posts on page load
        document.addEventListener('DOMContentLoaded', function() {
            const posts = generatePosts(50);
            renderPosts(posts);
        });
    </script>
</body>
</html>
