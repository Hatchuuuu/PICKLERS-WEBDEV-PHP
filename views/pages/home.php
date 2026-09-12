<?php
declare(strict_types=1);
/**
 * Picklers - Home View Template
 * @var string $site_name
 * @var string $tagline
 * @var array|null $currentUser
 * @var array $facilities
 * @var array $matches
 * @var array $brands
 */
$brands = $brands ?? [];
$facilities = $facilities ?? [];
$matches = $matches ?? [];
$faqs = $faqs ?? [];
?>
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo $site_name; ?> - <?php echo $tagline; ?></title>
  <meta name="description" content="The #1 Philippines Pickleball Booking App. Discover courts, book seamlessly, join open play sessions, and connect with players nationwide.">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Montserrat:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="style.css?v=<?php echo time(); ?>">
</head>
<body>

  <!-- ========================================================================
       Scroll-Linked Sticky Navigation Bar
       ======================================================================== -->
  <header class="site-header" id="siteHeader">
    <div class="container nav-inner">
      <a href="index.php" class="brand-wrap">
        <img src="assets/images/PICKLERS_OFFICIAL_LOGO.svg" alt="Picklers Logo" class="brand-icon" onerror="this.src='assets/images/PICKLERS_LOGO.png'">
        <span class="brand-name">PICKLERS</span>
      </a>

      <div class="nav-actions">
        <!-- Theme Toggle Switch -->
        <button type="button" class="theme-switch" id="themeSwitch" aria-label="Toggle Dark/Light Mode" title="Toggle theme">
          <div class="theme-switch-knob">
            <svg class="theme-moon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/></svg>
            <svg class="theme-sun" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2"/><path d="M12 20v2"/><path d="m4.93 4.93 1.41 1.41"/><path d="m17.66 17.66 1.41 1.41"/><path d="M2 12h2"/><path d="M20 12h2"/><path d="m6.34 17.66-1.41 1.41"/><path d="m19.07 4.93-1.41 1.41"/></svg>
          </div>
        </button>

        <?php if ($currentUser): ?>
          <a href="app.php" class="btn-nav-login" style="background:#10B981; color:#FFFFFF; border:none; padding:8px 18px; border-radius:12px; font-weight:800; display:inline-flex; align-items:center; gap:8px;">
            <img src="<?php echo htmlspecialchars($currentUser['avatar_url']); ?>" style="width:22px; height:22px; border-radius:50%; object-fit:cover;">
            <span>Open App →</span>
          </a>
        <?php else: ?>
          <a href="auth.php" class="btn-nav-login">Log In</a>
          <a href="auth.php?intent=signup" class="btn-nav-signup">Sign Up</a>
        <?php endif; ?>
      </div>

      <button type="button" class="mobile-menu-btn" id="mobileToggle" aria-label="Toggle Menu">
        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="4" x2="20" y1="12" y2="12"/><line x1="4" x2="20" y1="6" y2="6"/><line x1="4" x2="20" y1="18" y2="18"/></svg>
      </button>
    </div>
  </header>

  <!-- ========================================================================
       Hero Section
       ======================================================================== -->
  <section class="hero-section">

    <!-- 1. Tracing Border Pill Badge -->
    <div class="hero-badge-wrap reveal-on-scroll">
      <div class="hero-badge-body">
        <span class="pulse-dot">
          <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4.9 19.1C1 15.2 1 8.8 4.9 4.9"/><path d="M7.8 16.2c-2.3-2.3-2.3-6.1 0-8.5"/><circle cx="12" cy="12" r="2"/><path d="M16.2 7.8c2.3 2.3 2.3 6.1 0 8.5"/><path d="M19.1 4.9C23 8.8 23 15.1 19.1 19"/></svg>
        </span>
        <span>#1 Philippines Pickleball Booking App</span>
      </div>
      <div class="hero-badge-tracer">
        <div class="hero-badge-tracer-beam"></div>
      </div>
    </div>

    <!-- 2. Giant Glowing Title -->
    <h1 class="hero-title reveal-on-scroll">PICKLERS</h1>

    <!-- 3. Subtags: FIND • BOOK • PLAY -->
    <div class="hero-subtags reveal-on-scroll">
      <span>FIND</span>
      <span class="hero-dot"></span>
      <span>BOOK</span>
      <span class="hero-dot"></span>
      <span>PLAY</span>
    </div>

    <!-- 4. Hero Subtitle Description -->
    <p class="hero-desc reveal-on-scroll">
      Book premium pickleball courts across the Philippines, join open play sessions,
      connect with players, and manage everything in one place.
    </p>

    <!-- 5. Interactive Action Buttons with authentic kid-jump & button-shine -->
    <div class="hero-buttons reveal-on-scroll">
      <a href="app.php?tab=play" class="btn-hero-primary animate-kid-jump with-shine" style="animation-delay: 1.5s;">
        <div class="shine-ray"></div>
        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="4" rx="2" ry="2"/><line x1="16" x2="16" y1="2" y2="6"/><line x1="8" x2="8" y1="2" y2="6"/><line x1="3" x2="21" y1="10" y2="10"/><path d="m9 16 2 2 4-4"/></svg>
        <span>Book a Court</span>
      </a>

      <a href="app.php?tab=explore" class="btn-hero-secondary animate-kid-jump with-shine" style="animation-delay: 2.8s;">
        <div class="shine-ray" style="animation-delay: 3s;"></div>
        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="10" cy="7" r="4"/><path d="M10.3 15H5a4 4 0 0 0-4 4v2"/><circle cx="17" cy="17" r="3"/><path d="m21 21-1.9-1.9"/></svg>
        <span>Join Open Play</span>
      </a>
    </div>

    <!-- 6. Owner Callout Pill -->
    <a href="auth.php?intent=owner" class="owner-callout-btn with-shine reveal-on-scroll">
      <div class="shine-ray" style="animation-delay: 5s;"></div>
      <div class="owner-callout-icon">
        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="16" height="20" x="4" y="2" rx="2" ry="2"/><path d="M9 22v-4h6v4"/><path d="M8 6h.01"/><path d="M16 6h.01"/><path d="M8 10h.01"/><path d="M16 10h.01"/><path d="M8 14h.01"/><path d="M16 14h.01"/></svg>
      </div>
      <div class="owner-callout-text">
        <span class="owner-callout-label">Are you a Court Owner?</span>
        <span class="owner-callout-action">List your court</span>
      </div>
      <svg class="owner-callout-arrow" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
    </a>
  </section>

  <!-- ========================================================================
       Trusted Partners Marquee (Infinite Seamless Flow)
       ======================================================================== -->
  <section class="trusted-section">
    <div class="trusted-header reveal-on-scroll">
      <div class="trusted-shield-badge">
        <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#00D98B" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><path d="m9 12 2 2 4-4"/></svg>
      </div>
      <div class="trusted-line-row">
        <div class="trusted-line"></div>
        <div class="trusted-label">TRUSTED BY FAMOUS BRANDS & FACILITIES</div>
        <div class="trusted-line"></div>
      </div>
    </div>

    <div class="marquee-container" id="marqueeContainer">
      <div class="marquee-track">
        <!-- Loop 1 -->
        <?php foreach ($brands as $brand): ?>
          <div class="brand-card">
            <div class="brand-logo-box">
              <img src="<?php echo $brand['logo']; ?>" alt="<?php echo $brand['label']; ?>" class="brand-logo-img">
            </div>
            <span class="brand-tag"><?php echo $brand['label']; ?></span>
          </div>
        <?php endforeach; ?>
        <!-- Loop 2 (Duplicate for seamless infinite wrap) -->
        <?php foreach ($brands as $brand): ?>
          <div class="brand-card">
            <div class="brand-logo-box">
              <img src="<?php echo $brand['logo']; ?>" alt="<?php echo $brand['label']; ?>" class="brand-logo-img">
            </div>
            <span class="brand-tag"><?php echo $brand['label']; ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </section>

  <!-- ========================================================================
       Stats Counter Bar with Conic-Gradient Tracing Beams
       ======================================================================== -->
  <section class="stats-section">
    <div class="stats-grid">
      <!-- Card 1 -->
      <div class="stat-card reveal-on-scroll">
        <div class="stat-tracing-beam">
          <div class="stat-beam-rotor"></div>
        </div>
        <div class="stat-top-highlight"></div>
        <div class="stat-number">
          <span class="countup-val" data-target="142">0</span>+
        </div>
        <div class="stat-label">Venues</div>
      </div>

      <!-- Card 2 -->
      <div class="stat-card reveal-on-scroll">
        <div class="stat-tracing-beam">
          <div class="stat-beam-rotor"></div>
        </div>
        <div class="stat-top-highlight"></div>
        <div class="stat-number">
          <span class="countup-val" data-target="12450" data-separator=",">0</span>+
        </div>
        <div class="stat-label">Players</div>
      </div>

      <!-- Card 3 -->
      <div class="stat-card reveal-on-scroll">
        <div class="stat-tracing-beam">
          <div class="stat-beam-rotor"></div>
        </div>
        <div class="stat-top-highlight"></div>
        <div class="stat-number">
          <span class="countup-val" data-target="5">0</span>–<span class="countup-val" data-target="10">0</span>%
        </div>
        <div class="stat-label">Service Fee</div>
      </div>

      <!-- Card 4 -->
      <div class="stat-card reveal-on-scroll">
        <div class="stat-tracing-beam">
          <div class="stat-beam-rotor"></div>
        </div>
        <div class="stat-top-highlight"></div>
        <div class="stat-number">
          <span class="countup-val" data-target="200">0</span><small>/day</small>
        </div>
        <div class="stat-label">Signups</div>
      </div>
    </div>
  </section>

  <!-- ========================================================================
       Courts & Open Play Section
       ======================================================================== -->
  <section class="venues-section container" id="venues">
    <div class="section-head reveal-on-scroll">
      <h2 class="section-title">Play Pickleball, Anywhere.</h2>
      <p class="section-subtitle">Discover premium facilities and join active matches near you.</p>
    </div>

    <!-- Segmented Sliding Pill Switcher -->
    <div class="tab-switcher-wrap reveal-on-scroll">
      <div class="pill-switcher">
        <div class="pill-slider" id="pillSlider"></div>
        <button type="button" class="pill-btn active" id="btnFacilities" onclick="switchTab('facilities')">Pickle Facilities</button>
        <button type="button" class="pill-btn" id="btnOpenPlay" onclick="switchTab('open-play')">Open Play</button>
      </div>
    </div>

    <!-- 1. Facilities Grid View -->
    <div class="facilities-main-grid" id="facilitiesGrid">
      <?php foreach ($facilities as $f): ?>
        <?php
          $typeStr = $f['type'] ?? 'Indoor';
          $typeNormalized = (stripos($typeStr, 'indoor') !== false) ? 'Indoor' : 'Outdoor';
          $minP = (float)($f['min_price'] ?? $f['price_numeric'] ?? 200);
          $maxP = (float)($f['max_price'] ?? $f['price_numeric'] ?? $minP);
          $ratingNum = (float)($f['rating'] ?? 4.8);
        ?>
        <div class="app-facility-card reveal-on-scroll">
          <div class="card-thumb-wrap">
            <img src="<?php echo htmlspecialchars($f['image'] ?? ''); ?>" alt="<?php echo htmlspecialchars($f['name'] ?? ''); ?>" class="card-thumb-img" loading="lazy">
            <button type="button" class="card-heart-btn" onclick="toggleFav(this, event)" aria-label="Favorite">
              <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"/></svg>
            </button>
          </div>
          <div class="card-body">
            <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:4px;">
              <h3 class="card-title" style="margin:0;"><?php echo htmlspecialchars($f['name'] ?? ''); ?></h3>
            </div>
            <div class="card-location">
              <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg>
              <span><?php echo htmlspecialchars($f['location'] ?? ''); ?></span>
            </div>
            <div style="font-size:12px; color:rgba(255,255,255,0.7); display:flex; align-items:center; gap:8px; margin-bottom:14px; flex-wrap:wrap;">
              <span style="color:#F59E0B; font-weight:800;">★ <?php echo number_format($ratingNum, 1); ?></span>
              <span style="color:var(--ink-secondary);">(<?php echo $f['reviews'] ?? 100; ?> reviews)</span>
              <span style="color:#475569;">•</span>
              <span style="color:var(--ink-secondary);"><?php echo htmlspecialchars((string)($f['transit'] ?? '🚗 15 min')); ?></span>
            </div>
            <div class="card-meta-row" style="gap:8px;">
              <div>
                <div style="font-size:10px; color:rgba(255,255,255,0.45); text-transform:uppercase; font-weight:700;"><?php echo ($minP < $maxP) ? 'Rates' : 'Rate'; ?></div>
                <div class="card-price-cyan">
                  <?php if ($minP < $maxP): ?>
                    ₱<?php echo number_format($minP, 0); ?> - ₱<?php echo number_format($maxP, 0); ?><span style="font-size:12px; color:rgba(255,255,255,0.5);">/hr</span>
                  <?php else: ?>
                    ₱<?php echo number_format($minP, 0); ?><span style="font-size:12px; color:rgba(255,255,255,0.5);">/hr</span>
                  <?php endif; ?>
                </div>
              </div>
              <div style="display:flex; align-items:center; gap:6px;">
                <a href="app.php?tab=play" class="btn-view-courts" style="text-decoration:none;">
                  <span>View Courts</span>
                  <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>
                </a>
              </div>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <!-- 2. Open Play Grid View (Initially Hidden) -->
    <div class="open-play-grid" id="openPlayGrid" style="display: none;">
      <?php foreach ($matches as $m):
          $cardTitle = !empty($m['title']) ? $m['title'] : ($m['facility_name'] ?? 'Open Play Session');
          $facilityName = $m['facility_name'] ?? 'A-Courts Dumaguete';
          $currentPlayers = intval($m['current_players'] ?? 0);
          $maxPlayers = intval($m['max_players'] ?? $m['max_slots'] ?? 4);
          $isFull = $currentPlayers >= $maxPlayers;
          $spotsLeft = max(0, $maxPlayers - $currentPlayers);
          $priceVal = is_numeric($m['price'] ?? null) ? (float)$m['price'] : 150;
          $hostName = $m['host'] ?? 'Coach Marco';
          $levelName = $m['level'] ?? 'All Levels';
          $locationName = $m['location'] ?? 'Bantayan, Dumaguete City';
          $dateVal = $m['date'] ?? 'Today';
          $timeVal = $m['time'] ?? '6:00 PM - 8:00 PM';
        ?>
        <div class="openplay-card match-card reveal-on-scroll">
          <div>
            <!-- Card Header: Title + Level Badge -->
            <div class="openplay-card-header">
              <h3 class="openplay-card-title"><?php echo htmlspecialchars($cardTitle); ?></h3>
              <span class="openplay-level-badge"><?php echo htmlspecialchars($levelName); ?></span>
            </div>
          </div>

            <!-- Details List -->
            <div class="openplay-details-list" style="margin-top: 14px;">
              <div class="openplay-detail-item loc">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg>
                <span><?php echo htmlspecialchars($locationName); ?></span>
              </div>
              <div class="openplay-detail-item date">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="4" rx="2" ry="2"/><line x1="16" x2="16" y1="2" y2="6"/><line x1="8" x2="8" y1="2" y2="6"/><line x1="3" x2="21" y1="10" y2="10"/></svg>
                <span><?php echo htmlspecialchars($dateVal); ?></span>
              </div>
              <div class="openplay-detail-item time">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                <span><?php echo htmlspecialchars($timeVal); ?></span>
              </div>
              <div class="openplay-host-price-row">
                <div class="openplay-detail-item host">
                  <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                  <span>Host: <strong><?php echo htmlspecialchars($hostName); ?></strong></span>
                </div>
                <div class="openplay-price-wrap">
                  <span class="openplay-price-val">₱<?php echo number_format($priceVal, 0); ?></span>
                  <span class="openplay-price-label">your share</span>
                </div>
              </div>
            </div>
          </div>

          <!-- Card Footer -->
          <div class="openplay-card-footer">
            <!-- Spots Indicator -->
            <div class="openplay-spots-group">
              <div class="openplay-spots-circle">
                <?php echo $currentPlayers; ?>/<?php echo $maxPlayers; ?>
              </div>
              <div class="openplay-spots-info">
                <span class="openplay-spots-filled"><?php echo $currentPlayers; ?> of <?php echo $maxPlayers; ?> spots filled</span>
                <span class="openplay-spots-remain">
                  <?php if ($isFull): ?>
                    Session full
                  <?php else: ?>
                    <?php echo $spotsLeft; ?> <?php echo ($spotsLeft === 1) ? 'spot' : 'spots'; ?> left
                  <?php endif; ?>
                </span>
              </div>
            </div>

            <!-- Action Button -->
            <div class="openplay-actions-group">
              <?php if ($isFull): ?>
                <button type="button" class="btn-openplay-full" disabled>Full</button>
              <?php else: ?>
                <button type="button" class="btn-openplay-join"
                  onclick="(function(){var d={matchId:'<?php echo htmlspecialchars($m['id'] ?? '', ENT_QUOTES); ?>',facilityName:'<?php echo htmlspecialchars(addslashes($facilityName ?? ''), ENT_QUOTES); ?>',matchType:'Open Play Session',date:'<?php echo htmlspecialchars(addslashes($dateVal ?? ''), ENT_QUOTES); ?>',time:'<?php echo htmlspecialchars(addslashes($timeVal ?? ''), ENT_QUOTES); ?>',price:<?php echo (float)($priceVal ?? 200); ?>,level:'<?php echo htmlspecialchars(addslashes($levelName ?? 'All Levels'), ENT_QUOTES); ?>',location:'<?php echo htmlspecialchars(addslashes($locationName ?? ''), ENT_QUOTES); ?>'};sessionStorage.setItem('picklers_openplay_checkout',JSON.stringify(d));window.location.href='app.php?tab=play&checkout=openplay';})()">Join Match</button>
              <?php endif; ?>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </section>

  <!-- ========================================================================
       Bento Grid Features ("Built for the modern player.")
       ======================================================================== -->
  <section class="bento-section container" id="features">
    <div class="section-head reveal-on-scroll">
      <h2 class="section-title">Built for the modern player.</h2>
      <p class="section-subtitle">Everything you need to elevate your pickleball experience, wrapped in an interface you'll actually love using.</p>
    </div>

    <div class="bento-grid">
      <!-- Feature 1 (Large - 2 cols) -->
      <div class="bento-card bento-col-span-2 reveal-on-scroll">
        <!-- SVG Watermark: Trophy -->
        <svg class="bento-watermark" style="color: #00D98B;" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6"/><path d="M18 9h1.5a2.5 2.5 0 0 0 0-5H18"/><path d="M4 22h16"/><path d="M10 14.66V17c0 .55-.45 1-1 1H7v2h10v-2h-2c-.55 0-1-.45-1-1v-2.34"/><path d="M6 4h12a2 2 0 0 1 2 2v6a6 6 0 0 1-12 0V6a2 2 0 0 1 2-2Z"/></svg>
        <div class="bento-badge-icon badge-emerald">
          <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><path d="m9 12 2 2 4-4"/></svg>
        </div>
        <h3 class="bento-title">Open Play Matchmaking</h3>
        <p class="bento-desc">Join active open play sessions and queue up seamlessly with other players across partner venues in your area.</p>
      </div>

      <!-- Feature 2 (Small) -->
      <div class="bento-card reveal-on-scroll">
        <!-- SVG Watermark: Credit Card -->
        <svg class="bento-watermark" style="color: #3b82f6;" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" x2="22" y1="10" y2="10"/></svg>
        <div class="bento-badge-icon badge-blue">
          <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" x2="22" y1="10" y2="10"/></svg>
        </div>
        <h3 class="bento-title">Split Payments</h3>
        <p class="bento-desc">No more chasing friends for GCash. Split court costs automatically at checkout.</p>
      </div>

      <!-- Feature 3 (Small) -->
      <div class="bento-card reveal-on-scroll">
        <!-- SVG Watermark: Zap -->
        <svg class="bento-watermark" style="color: #a855f7;" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M4 14a1 1 0 0 1-.78-1.63l9.9-10.2a.5.5 0 0 1 .86.46l-1.92 6.02A1 1 0 0 0 13 10h7a1 1 0 0 1 .78 1.63l-9.9 10.2a.5.5 0 0 1-.86-.46l1.92-6.02A1 1 0 0 0 11 14z"/></svg>
        <div class="bento-badge-icon badge-purple">
          <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 14a1 1 0 0 1-.78-1.63l9.9-10.2a.5.5 0 0 1 .86.46l-1.92 6.02A1 1 0 0 0 13 10h7a1 1 0 0 1 .78 1.63l-9.9 10.2a.5.5 0 0 1-.86-.46l1.92-6.02A1 1 0 0 0 11 14z"/></svg>
        </div>
        <h3 class="bento-title">Instant Booking</h3>
        <p class="bento-desc">Live availability sync. Secure your preferred court and slot in under 15 seconds flat.</p>
      </div>

      <!-- Feature 4 (Large - 2 cols) -->
      <div class="bento-card bento-col-span-2 reveal-on-scroll">
        <!-- SVG Watermark: Users -->
        <svg class="bento-watermark" style="color: #f97316;" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
        <div class="bento-badge-icon badge-orange">
          <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
        </div>
        <h3 class="bento-title">Vibrant Community</h3>
        <p class="bento-desc">Join clubs, climb local leaderboards, chat with players, and get notified when your favorite group hosts an open play.</p>
      </div>
    </div>
  </section>

  <!-- ========================================================================
       Testimonials - Loved by the Community
       ======================================================================== -->
  <section class="testimonials-section">
    <div class="testimonials-glow"></div>

    <div class="section-head reveal-on-scroll">
      <h2 class="section-title">Loved by the Community</h2>
      <p class="section-subtitle">Join thousands of players who found their match on Picklers.</p>
    </div>

    <div class="testimonials-grid">
      <div class="testimonial-card reveal-on-scroll">
        <div>
          <div class="stars-row">
            <?php for ($i=0; $i<5; $i++): ?>
              <svg viewBox="0 0 24 24"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
            <?php endfor; ?>
          </div>
          <p class="testimonial-quote">"Finding open play courts used to be such a hassle in group chats. Thanks to Picklers, booking courts and joining matches is now effortless!"</p>
        </div>
        <div class="author-row">
          <div class="author-avatar"><span>B</span></div>
          <div>
            <div class="author-name">Bob Joshua</div>
            <div class="author-role">Pickleball Enthusiast</div>
          </div>
        </div>
      </div>

      <div class="testimonial-card reveal-on-scroll">
        <div>
          <div class="stars-row">
            <?php for ($i=0; $i<5; $i++): ?>
              <svg viewBox="0 0 24 24"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
            <?php endfor; ?>
          </div>
          <p class="testimonial-quote">"The split payment feature alone makes this app worth it! No more paying upfront for the whole court or chasing friends for GCash transfers."</p>
        </div>
        <div class="author-row">
          <div class="author-avatar"><span>D</span></div>
          <div>
            <div class="author-name">Daniel Alfeche</div>
            <div class="author-role">Advanced Player (4.0)</div>
          </div>
        </div>
      </div>

      <div class="testimonial-card reveal-on-scroll">
        <div>
          <div class="stars-row">
            <?php for ($i=0; $i<5; $i++): ?>
              <svg viewBox="0 0 24 24"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
            <?php endfor; ?>
          </div>
          <p class="testimonial-quote">"Ever since listing our venue on Picklers, our morning court bookings surged by 40%. The manager dashboard is super intuitive and easy to use!"</p>
        </div>
        <div class="author-row">
          <div class="author-avatar"><span>K</span></div>
          <div>
            <div class="author-name">Kyle Asentista</div>
            <div class="author-role">Partner Facility</div>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- ========================================================================
       How It Works (3-Step Connected Flow)
       ======================================================================== -->
  <section class="how-section container">
    <div class="section-head reveal-on-scroll">
      <h2 class="section-title">How It Works</h2>
      <p class="section-subtitle">From finding a court to the first serve in three easy steps.</p>
    </div>

    <div class="how-grid">
      <div class="how-line"><div class="how-line-pulse"></div></div>

      <div class="how-card reveal-on-scroll">
        <div class="how-icon-box">
          <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
        </div>
        <h3 class="how-step-title">Find a Court</h3>
        <p class="how-step-desc">Browse premium pickleball facilities near you, check real-time slot availability, and compare rates.</p>
      </div>

      <div class="how-card reveal-on-scroll">
        <div class="how-icon-box">
          <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="4" rx="2" ry="2"/><line x1="16" x2="16" y1="2" y2="6"/><line x1="8" x2="8" y1="2" y2="6"/><line x1="3" x2="21" y1="10" y2="10"/></svg>
        </div>
        <h3 class="how-step-title">Book & Pay</h3>
        <p class="how-step-desc">Secure your court with a few taps. Split the fee with friends or keep it exclusive. Cashless and hassle-free.</p>
      </div>

      <div class="how-card reveal-on-scroll">
        <div class="how-icon-box">
          <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
        </div>
        <h3 class="how-step-title">Show Up & Play</h3>
        <p class="how-step-desc">Just arrive at the venue, present your digital court pass, and start playing. We take care of the rest.</p>
      </div>
    </div>
  </section>

  <!-- ========================================================================
       Frequently Asked Questions
       ======================================================================== -->
  <section class="faq-section container-compact">
    <div class="section-head reveal-on-scroll">
      <h2 class="section-title">Frequently Asked Questions</h2>
    </div>

    <!-- Accordion List -->
    <div class="faq-list">
      <?php foreach ($faqs as $i => $faq): ?>
        <div class="faq-item reveal-on-scroll" onclick="toggleFaq(this)">
          <div class="faq-question-btn">
            <span><?php echo htmlspecialchars($faq['q'] ?? $faq['question'] ?? ''); ?></span>
            <svg class="faq-chevron" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
          </div>
          <div class="faq-answer">
            <div class="faq-answer-inner">
              <?php echo htmlspecialchars($faq['a'] ?? $faq['answer'] ?? ''); ?>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <!-- Ask Prend Interactive Assistant Box -->
    <div class="prend-box reveal-on-scroll">
      <div class="prend-box-header">
        <img src="prend-chatbot-logo.svg" alt="Prend Mascot" class="prend-mascot" onerror="this.src='prend_logo.png'">
        <div>
          <h3 class="prend-title">Ask Prend Anything</h3>
          <p class="prend-sub">Can't find your answer? Ask Prend, our smart assistant.</p>
        </div>
      </div>

      <form class="prend-form" onsubmit="handlePrendSubmit(event)">
        <input type="text" id="prendInput" class="prend-input" placeholder="e.g. Can I bring my own paddle?">
        <button type="submit" class="prend-send-btn" aria-label="Send">
          <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12 7-7 7 7"/><path d="M12 19V5"/></svg>
        </button>
      </form>

      <div class="prend-response-wrap" id="prendResponse">
        <img src="prend-chatbot-logo.svg" alt="Prend" class="prend-response-avatar" onerror="this.src='prend_logo.png'">
        <div class="prend-response-text" id="prendResponseText"></div>
      </div>
    </div>
  </section>

  <!-- ========================================================================
       Footer
       ======================================================================== -->
  <footer class="site-footer">
    <div class="container">
      <div class="footer-grid">
        <div>
          <a href="#" class="brand-wrap">
            <img src="assets/images/PICKLERS_OFFICIAL_LOGO.svg" alt="Picklers Logo" class="brand-icon" onerror="this.src='assets/images/PICKLERS_LOGO.png'">
            <span class="brand-name">PICKLERS</span>
          </a>
          <p class="footer-brand-desc">
            The Philippines' premier pickleball booking and community platform. Find courts, join matches, and play.
          </p>
          <div class="footer-socials">
            <a href="#" class="social-link" aria-label="Instagram">
              <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="20" x="2" y="2" rx="5" ry="5"/><path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z"/><line x1="17.5" x2="17.51" y1="6.5" y2="6.5"/></svg>
            </a>
            <a href="#" class="social-link" aria-label="Facebook">
              <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"/></svg>
            </a>
            <a href="#" class="social-link" aria-label="Twitter">
              <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 4s-.7 2.1-2 3.4c1.6 10-9.4 17.3-18 11.6 2.2.1 4.4-.6 6-2C3 15.5.5 9.6 3 5c2.2 2.6 5.6 4.1 9 4-.9-4.2 4-6.6 7-3.8 1.1 0 3-1.2 3-1.2z"/></svg>
            </a>
          </div>
        </div>

        <div>
          <h4 class="footer-heading">Platform</h4>
          <ul class="footer-nav">
            <li><a href="#venues" onclick="switchTab('facilities')">Find Courts</a></li>
            <li><a href="#venues" onclick="switchTab('open-play')">Open Play</a></li>
            <li><a href="#venues">List Your Court</a></li>
            <li><a href="#venues">Pricing</a></li>
          </ul>
        </div>

        <div>
          <h4 class="footer-heading">Company</h4>
          <ul class="footer-nav">
            <li><a href="#">About Us</a></li>
            <li><a href="#">Careers</a></li>
            <li><a href="#">Contact Support</a></li>
            <li><a href="#">Privacy & Terms</a></li>
          </ul>
        </div>
      </div>

      <div class="footer-bottom">
        <div>© <?php echo date('Y'); ?> Picklers PH. All rights reserved.</div>
        <div class="footer-legal-links">
          <a href="#">Terms of Service</a>
          <a href="#">Privacy Policy</a>
        </div>
      </div>
    </div>
  </footer>

  <!-- ========================================================================
       Vanilla JavaScript (Exact Interactions & Micro-Animations)
       ======================================================================== -->
  <script>
    // Force scroll restoration to top on page refresh/reload
    if ('scrollRestoration' in history) {
      history.scrollRestoration = 'manual';
    }
    window.addEventListener('beforeunload', () => {
      window.scrollTo(0, 0);
    });
    window.addEventListener('load', () => {
      window.scrollTo(0, 0);
    });

    // 1. Theme Toggle with LocalStorage persistence
    const themeSwitch = document.getElementById('themeSwitch');
    const htmlEl = document.documentElement;

    const savedTheme = localStorage.getItem('picklers_theme') || 'dark';
    if (savedTheme === 'light') {
      htmlEl.classList.remove('dark');
      htmlEl.classList.add('light');
    } else {
      htmlEl.classList.remove('light');
      htmlEl.classList.add('dark');
    }

    if (themeSwitch) {
      themeSwitch.addEventListener('click', () => {
        const isDark = htmlEl.classList.contains('dark');
        if (isDark) {
          htmlEl.classList.remove('dark');
          htmlEl.classList.add('light');
          localStorage.setItem('picklers_theme', 'light');
        } else {
          htmlEl.classList.remove('light');
          htmlEl.classList.add('dark');
          localStorage.setItem('picklers_theme', 'dark');
        }
      });
    }

    // 2. Scroll-Linked Navbar Elevation & Height Shrink
    const siteHeader = document.getElementById('siteHeader');
    window.addEventListener('scroll', () => {
      if (window.scrollY > 30) {
        siteHeader.classList.add('scrolled');
      } else {
        siteHeader.classList.remove('scrolled');
      }
    }, { passive: true });

    // 3. Segmented Pill Tab Switcher (Pickle Facilities <-> Open Play)
    function switchTab(tab) {
      const slider = document.getElementById('pillSlider');
      const btnFac = document.getElementById('btnFacilities');
      const btnPlay = document.getElementById('btnOpenPlay');
      const facGrid = document.getElementById('facilitiesGrid');
      const playGrid = document.getElementById('openPlayGrid');

      if (tab === 'facilities') {
        slider.style.transform = 'translateX(0)';
        btnFac.classList.add('active');
        btnPlay.classList.remove('active');
        facGrid.style.display = 'grid';
        playGrid.style.display = 'none';
      } else {
        // Relative to the slider's own (now fluid) width so the control
        // stays aligned at every viewport size.
        slider.style.transform = 'translateX(100%)';
        btnFac.classList.remove('active');
        btnPlay.classList.add('active');
        facGrid.style.display = 'none';
        playGrid.style.display = 'grid';
      }
    }

    // 4. Favorite Heart Pop Animation
    function toggleFav(btn, e) {
      e.stopPropagation();
      btn.classList.toggle('favorited');
      btn.style.transform = 'scale(1.4)';
      setTimeout(() => {
        btn.style.transform = 'scale(1)';
      }, 250);
    }

    // 5. Accordion Expand/Collapse
    function toggleFaq(item) {
      const isOpen = item.classList.contains('open');
      document.querySelectorAll('.faq-item').forEach(i => i.classList.remove('open'));
      if (!isOpen) {
        item.classList.add('open');
      }
    }

    // 6. Interactive "Ask Prend" Knowledge Assistant
    const prendResponses = {
      greeting: "Hi, ma PREND! What's up! Ready to hit the kitchen line, or are you just here to ask me philosophical questions while avoiding your backhand drills? Let's play some pickleball!",
      paddle: "Hi, ma PREND! Yes, you can definitely bring your own USAPA-approved paddle and balls to any of our partner venues!",
      book: "Hi, ma PREND! To book a court, simply browse our facilities above, pick your preferred court, select your time slot, and confirm your payment via GCash or Pickle Credits!",
      refund: "Hi, ma PREND! Cancellations made at least 24 hours prior to your scheduled booking receive an instant 100% refund in Pickle Credits automatically.",
      rules: "Hi, ma PREND! Remember the golden rule: stay out of the Non-Volley Zone (the Kitchen) unless the ball bounces in it first!",
      wallet: "Hi, ma PREND! We support instant cashless top-ups and payments through GCash, Maya, and Pickle Credits.",
      default: "Hi, ma PREND! That's a great question! While the universe contemplates that mystery, I can tell you that the answer usually involves hitting a crisp third-shot drop right into your opponent's kitchen! Ask me anything about courts, bookings, or open play!"
    };

    function handlePrendSubmit(e) {
      e.preventDefault();
      const input = document.getElementById('prendInput');
      const val = input.value.trim().toLowerCase();
      if (!val) return;

      const wrap = document.getElementById('prendResponse');
      const text = document.getElementById('prendResponseText');

      wrap.classList.add('active');
      text.innerHTML = '<span style="color: var(--ink-muted);">Prend is thinking...</span>';

      setTimeout(() => {
        let reply = prendResponses.default;
        if (val.includes('hi') || val.includes('hello') || val.includes('hey') || val.includes('yo') || val.includes('musta')) {
          reply = prendResponses.greeting;
        } else if (val.includes('paddle') || val.includes('gear') || val.includes('equipment') || val.includes('ball')) {
          reply = prendResponses.paddle;
        } else if (val.includes('book') || val.includes('reserve') || val.includes('court') || val.includes('slot')) {
          reply = prendResponses.book;
        } else if (val.includes('cancel') || val.includes('refund')) {
          reply = prendResponses.refund;
        } else if (val.includes('rule') || val.includes('kitchen') || val.includes('score') || val.includes('serve')) {
          reply = prendResponses.rules;
        } else if (val.includes('wallet') || val.includes('pay') || val.includes('gcash') || val.includes('credit')) {
          reply = prendResponses.wallet;
        }

        text.innerHTML = '<strong>Prend:</strong> ' + reply;
      }, 400);
    }

    // 7. IntersectionObserver for Reveal Animations & CountUp
    const observerOptions = {
      threshold: 0.02,
      rootMargin: '100px 0px 50px 0px'
    };

    const revealObserver = new IntersectionObserver((entries) => {
      entries.forEach(entry => {
        if (entry.isIntersecting) {
          entry.target.classList.add('is-revealed');

          // Check if it has CountUp values
          const countUps = entry.target.querySelectorAll('.countup-val');
          countUps.forEach(c => animateCountUp(c));
          
          revealObserver.unobserve(entry.target);
        }
      });
    }, observerOptions);

    document.querySelectorAll('.reveal-on-scroll').forEach(el => {
      if (el.closest('.hero-section') || el.getBoundingClientRect().top < (window.innerHeight || 800) + 150) {
        el.classList.add('is-revealed');
        const countUps = el.querySelectorAll('.countup-val');
        countUps.forEach(c => animateCountUp(c));
      } else {
        revealObserver.observe(el);
      }
    });

    // CountUp Logic
    function animateCountUp(el) {
      const target = parseInt(el.getAttribute('data-target'), 10);
      const separator = el.getAttribute('data-separator') || '';
      const duration = 1800;
      const startTime = performance.now();

      function update(currentTime) {
        const elapsed = currentTime - startTime;
        const progress = Math.min(elapsed / duration, 1);
        // Easing out quint
        const ease = 1 - Math.pow(1 - progress, 5);
        const current = Math.floor(ease * target);

        el.textContent = separator ? current.toLocaleString() : current;

        if (progress < 1) {
          requestAnimationFrame(update);
        } else {
          el.textContent = separator ? target.toLocaleString() : target;
        }
      }

      requestAnimationFrame(update);
    }
  </script>
</body>
</html>
