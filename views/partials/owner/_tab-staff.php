<div class="owner-topbar">
  <div class="owner-topbar-title-wrap">
    <h1 class="owner-page-title">Staff Management</h1>
    <p class="owner-page-subtitle">Manage facility staff members and access.</p>
  </div>
  <button type="button" class="btn-walkin-open" onclick="openModal('addStaffModal')">+ Add Staff</button>
</div>


<!-- Staff Table / Roster -->
<div class="staff-roster-box">
  <div class="staff-roster-header">
    <h2 class="staff-roster-title">Facility Staff Roster</h2>
    <span class="staff-roster-count">Showing <?php echo count($staffMembers); ?> Registered Personnel</span>
  </div>

  <div class="staff-list-wrap">
    <?php if (empty($staffMembers)): ?>
    <div style="text-align:center; padding:48px 20px; color:var(--pk-text-muted, #94A3B8);">
      <p style="font-size:14px; font-weight:600;">No staff added yet.</p>
      <p style="font-size:13px; margin-top:4px;">Click "Add Staff" above to add your first team member.</p>
    </div>
    <?php else: ?>
    <?php foreach ($staffMembers as $st): ?>
      <div class="staff-member-card">
        <div class="staff-member-info">
          <?php 
            $stAvatar = trim($st['avatar_url'] ?? ($st['avatar'] ?? ''));
            $stInitial = strtoupper(substr(trim($st['name'] ?? 'S'), 0, 1)) ?: 'S';
          ?>
          <div class="staff-avatar-ring" style="position:relative; width:44px; height:44px; min-width:44px; border-radius:50%; padding:2px; background:linear-gradient(135deg, #00D98B 0%, #00E5FF 100%); box-shadow:0 0 12px rgba(0,217,139,0.4), 0 0 4px rgba(0,229,255,0.5); display:flex; align-items:center; justify-content:center; flex-shrink:0;">
            <div style="width:100%; height:100%; border-radius:50%; overflow:hidden; background:#08101F; display:flex; align-items:center; justify-content:center;">
              <?php if (!empty($stAvatar)): ?>
                <img src="<?php echo htmlspecialchars($stAvatar); ?>" alt="Avatar" style="width:100%; height:100%; object-fit:cover; object-position:center 20%; border-radius:50%;" onerror="this.style.display='none'; if(this.nextElementSibling) this.nextElementSibling.style.display='flex';">
                <span style="display:none; font-weight:900; font-size:16px; color:#00D98B; font-family:'Montserrat', sans-serif; text-transform:uppercase;"><?php echo $stInitial; ?></span>
              <?php else: ?>
                <span style="font-weight:900; font-size:16px; color:#00D98B; font-family:'Montserrat', sans-serif; text-transform:uppercase;"><?php echo $stInitial; ?></span>
              <?php endif; ?>
            </div>
          </div>
          <div>
            <div class="staff-name-text"><?php echo htmlspecialchars($st['name']); ?></div>
            <div class="staff-sub-detail">
              <?php echo htmlspecialchars($st['email']); ?> &bull; <span style="color:var(--pk-text-muted, #94A3B8);">Joined <?php echo htmlspecialchars((string)$st['date']); ?></span>
            </div>
          </div>
        </div>

        <div class="staff-member-badges-actions">
          <span class="staff-status-badge">
            <?php echo htmlspecialchars($st['status'] ?? 'Active'); ?>
          </span>
          <button type="button" class="staff-btn-revoke" onclick="promptRevokeStaff('<?php echo htmlspecialchars(addslashes((string)$st['id'])); ?>', '<?php echo htmlspecialchars(addslashes($st['name'])); ?>', this)">Revoke</button>
        </div>
      </div>
    <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>
