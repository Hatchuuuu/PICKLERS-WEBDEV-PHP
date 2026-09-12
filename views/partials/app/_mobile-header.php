  <!-- ========================================================================
       2. Mobile Sticky Header (<768px)
       ======================================================================== -->
  <header class="app-mobile-header">
    <a href="app.php" style="display:flex; align-items:center; text-decoration:none;">
      <img src="<?= \Picklers\Helpers\Url::asset('images/PICKLERS_OFFICIAL_LOGO.svg') ?>" alt="Picklers Logo" style="width:44px; height:44px; margin-left:-2px; margin-right:-2px; object-fit:contain; flex-shrink:0;" onerror="this.src='<?= \Picklers\Helpers\Url::asset('images/PICKLERS_LOGO.png') ?>'">
      <span class="sidebar-brand-name" style="font-size:20px;">PICKLERS</span>
    </a>
    <div style="display:flex; align-items:center; gap:10px;">
      <button type="button" class="notif-bell-btn" onclick="openModal('notifModal')" title="Notifications">
        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg>
        <?php if (!empty($unreadNotifsCount) && $unreadNotifsCount > 0): ?><div class="notif-unread-dot"></div><?php endif; ?>
      </button>
      <div class="user-avatar-wrap" style="width:34px; height:34px;">
        <?php
          $avatarUrl = trim($currentUser['avatar_url'] ?? '');
          $hasCustomAvatar = !empty($avatarUrl) && stripos($avatarUrl, 'unsplash.com') === false;
          $initial = strtoupper(substr(trim($currentUser['name'] ?? 'P'), 0, 1)) ?: 'P';
        ?>
        <?php if ($hasCustomAvatar): ?>
          <img id="mobileHeaderAvatarImg" src="<?php echo htmlspecialchars($avatarUrl); ?>" alt="Avatar" class="user-avatar-img">
        <?php else: ?>
          <div style="width:100%; height:100%; border-radius:50%; background:#0E1A2D; border:1.5px solid #00D98B; display:flex; align-items:center; justify-content:center; font-weight:900; font-size:14px; color:#00D98B; font-family:'Montserrat', sans-serif; text-transform:uppercase;"><?php echo $initial; ?></div>
        <?php endif; ?>
      </div>
    </div>
  </header>

