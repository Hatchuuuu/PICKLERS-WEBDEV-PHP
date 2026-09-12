  <aside class="owner-sidebar">
    <div class="owner-sidebar-header">
      <a href="<?= \Picklers\Helpers\Url::to('/owner') ?>" class="owner-sidebar-brand">
        <img src="<?= \Picklers\Helpers\Url::asset('images/PICKLERS_OFFICIAL_LOGO.svg') ?>" alt="Picklers Logo" onerror="this.src='<?= \Picklers\Helpers\Url::asset('images/PICKLERS_LOGO.png') ?>'">
        <span class="owner-brand-title">PICKLERS</span>
      </a>
      <button type="button" class="notif-bell-btn owner-bell-btn" onclick="openModal('notifModal')" title="Notifications">
        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg>
        <?php if (!empty($unreadNotifsCount) && $unreadNotifsCount > 0): ?><div class="notif-unread-dot"></div><?php endif; ?>
      </button>
    </div>

      <!-- Navigation Tabs (Strictly 5 Primary Modules) -->
    <nav class="owner-sidebar-nav">
      <a href="<?= \Picklers\Helpers\Url::to('owner?tab=dashboard') ?>" class="owner-nav-btn <?php echo $tab === 'dashboard' ? 'active' : ''; ?>">
        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/></svg>
        <span>Dashboard</span>
      </a>

      <a href="<?= \Picklers\Helpers\Url::to('owner?tab=courts') ?>" class="owner-nav-btn <?php echo $tab === 'courts' ? 'active' : ''; ?>">
        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2.5"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="8" x2="21" y2="8"/><line x1="3" y1="16" x2="21" y2="16"/><line x1="12" y1="3" x2="12" y2="8"/><line x1="12" y1="16" x2="12" y2="21"/></svg>
        <span>My Courts</span>
      </a>

      <a href="<?= \Picklers\Helpers\Url::to('owner?tab=tournaments') ?>" class="owner-nav-btn <?php echo $tab === 'tournaments' ? 'active' : ''; ?>">
        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6"/><path d="M18 9h1.5a2.5 2.5 0 0 0 0-5H18"/><path d="M4 22h16"/><path d="M10 14.66V17c0 .55-.47.98-.97 1.21C7.85 18.75 7 20.24 7 22"/><path d="M14 14.66V17c0 .55.47.98.97 1.21C16.15 18.75 17 20.24 17 22"/><path d="M18 2H6v7a6 6 0 0 0 12 0V2Z"/></svg>
        <span>Tournaments</span>
      </a>

      <a href="<?= \Picklers\Helpers\Url::to('owner?tab=staff') ?>" class="owner-nav-btn <?php echo $tab === 'staff' ? 'active' : ''; ?>">
        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
        <span>Staff</span>
      </a>

      <a href="<?= \Picklers\Helpers\Url::to('owner?tab=settings') ?>" class="owner-nav-btn <?php echo $tab === 'settings' ? 'active' : ''; ?>">
        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/></svg>
        <span>Settings</span>
      </a>
    </nav>

    <!-- Persistent Console Switcher & User Indicator -->
    <div class="owner-sidebar-footer">
      <?php if (($currentUser['role'] ?? '') === 'admin' || !empty($currentUser['is_admin'])): ?>
        <a href="<?= \Picklers\Helpers\Url::to('admin') ?>" class="owner-footer-link" title="Switch to Admin Platform">
          <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/></svg>
          <span>Admin Console</span>
        </a>
      <?php endif; ?>

      <a href="<?= \Picklers\Helpers\Url::to('app') ?>" class="owner-footer-link" title="Switch to Player View">
        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="16" height="20" x="4" y="2" rx="2" ry="2"/><path d="M9 22v-4h6v4"/><path d="M8 6h.01"/><path d="M16 6h.01"/><path d="M12 6h.01"/><path d="M12 10h.01"/><path d="M12 14h.01"/><path d="M16 10h.01"/><path d="M16 14h.01"/><path d="M8 10h.01"/><path d="M8 14h.01"/></svg>
        <span>Switch to Player View</span>
      </a>

      <a href="javascript:void(0)" onclick="openModal('ownerLogoutModal')" class="owner-footer-link logout" style="margin-top:2px;">
        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" x2="9" y1="12" y2="12"/></svg>
        <span>Sign Out</span>
      </a>

      <!-- User Card -->
      <div class="user-profile-bar">
        <div class="user-avatar-circle-sm" id="ownerSidebarUserAvatar">
          <?php
            $avatarUrl = trim($currentUser['avatar_url'] ?? '');
            $hasCustomAvatar = !empty($avatarUrl) && stripos($avatarUrl, 'unsplash.com') === false;
            $initial = strtoupper(substr(trim($currentUser['name'] ?? 'O'), 0, 1)) ?: 'O';
          ?>
          <?php if ($hasCustomAvatar): ?>
            <img src="<?php echo htmlspecialchars($avatarUrl); ?>" alt="Avatar" style="width:100%; height:100%; border-radius:50%; object-fit:cover;">
          <?php else: ?>
            <span style="font-weight:900; font-size:15px; color:#00D98B; font-family:'Montserrat', sans-serif; text-transform:uppercase;"><?php echo $initial; ?></span>
          <?php endif; ?>
        </div>
        <div class="user-info-text">
          <div class="user-name-line"><?php echo htmlspecialchars($currentUser['name'] ?? 'Ignacio Reyes'); ?></div>
          <div class="user-email-line"><?php echo htmlspecialchars($currentUser['email'] ?? 'ignacio.reyes@incredoball.ph'); ?></div>
        </div>
      </div>
    </div>
  </aside>
