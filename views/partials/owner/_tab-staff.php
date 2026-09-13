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
          <div class="staff-avatar-box">
            <span><?php echo strtoupper(substr($st['name'], 0, 1)); ?></span>
          </div>
          <div>
            <div class="staff-name-text"><?php echo htmlspecialchars($st['name']); ?></div>
            <div class="staff-sub-detail">
              <?php echo htmlspecialchars($st['email']); ?> &bull; <span style="color:var(--pk-text-muted, #94A3B8);">Joined <?php echo $st['date']; ?></span>
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
