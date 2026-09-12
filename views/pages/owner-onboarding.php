<?php
declare(strict_types=1);
/**
 * Picklers — Owner Portal Onboarding / Empty State
 *
 * Reached only when an authenticated owner (or admin acting as one) has no
 * real facility row yet. This should not happen for any account approved
 * through admin_approve_owner_application (which now provisions the
 * facility as part of approval, atomically, before granting is_owner), but
 * exists so a genuinely empty account renders an honest explanation instead
 * of crashing on $facilities[0] or being handed a fabricated venue.
 *
 * @var array|null $currentUser
 * @var array|null $application  Most recent owner application on file, if any.
 */
?>
<!DOCTYPE html>
<html lang="en" class="dark">
<?php require VIEWS_PATH . '/partials/owner/_head.php'; ?>
<body>
  <main style="min-height:100vh; display:flex; align-items:center; justify-content:center; padding:24px; background:var(--pk-bg-page);">
    <div style="max-width:460px; width:100%; background:var(--pk-bg-card); border:1px solid var(--pk-border-card); border-radius:var(--pk-radius-lg); box-shadow:var(--pk-shadow-card); padding:36px 32px; text-align:center;">
      <div style="width:56px; height:56px; margin:0 auto 20px; border-radius:50%; background:var(--pk-brand-emerald-glow); display:flex; align-items:center; justify-content:center;">
        <svg xmlns="http://www.w3.org/2000/svg" width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="var(--pk-brand-emerald)" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18"/><path d="M5 21V7l8-4v18"/><path d="M19 21V11l-6-4"/></svg>
      </div>

      <?php if (($application['status'] ?? '') === 'pending_review'): ?>
        <h1 style="font-family:var(--pk-font-heading, inherit); font-size:20px; font-weight:800; color:var(--pk-text-primary); margin:0 0 10px;">Your application is under review</h1>
        <p style="font-size:14px; color:var(--pk-text-secondary); line-height:1.6; margin:0 0 24px;">
          We're reviewing <strong><?php echo htmlspecialchars((string)($application['facility_name'] ?? 'your facility')); ?></strong>. Once approved, it's provisioned and listed automatically — you'll get a notification and this page will show your dashboard.
        </p>
      <?php elseif (!empty($application)): ?>
        <h1 style="font-family:var(--pk-font-heading, inherit); font-size:20px; font-weight:800; color:var(--pk-text-primary); margin:0 0 10px;">No active facility yet</h1>
        <p style="font-size:14px; color:var(--pk-text-secondary); line-height:1.6; margin:0 0 24px;">
          Your most recent application (<strong><?php echo htmlspecialchars((string)($application['facility_name'] ?? '')); ?></strong>) is marked <strong><?php echo htmlspecialchars((string)($application['status'] ?? 'unknown')); ?></strong>. If you believe this is a mistake, please contact support or submit a new application.
        </p>
      <?php else: ?>
        <h1 style="font-family:var(--pk-font-heading, inherit); font-size:20px; font-weight:800; color:var(--pk-text-primary); margin:0 0 10px;">No facility on file</h1>
        <p style="font-size:14px; color:var(--pk-text-secondary); line-height:1.6; margin:0 0 24px;">
          Your account has owner access but no facility is registered yet. Submit an application to get your venue listed in Discover Courts.
        </p>
      <?php endif; ?>

      <div style="display:flex; flex-direction:column; gap:10px;">
        <a href="<?= \Picklers\Helpers\Url::to('owner-application.php') ?>" style="display:block; background:var(--pk-brand-emerald); color:#0A121F; font-weight:800; font-size:14px; padding:13px; border-radius:var(--pk-radius-md); text-decoration:none;">
          <?php echo empty($application) ? 'Start Your Application' : 'View Application Status'; ?>
        </a>
        <a href="<?= \Picklers\Helpers\Url::to('app.php') ?>" style="display:block; background:transparent; color:var(--pk-text-secondary); font-weight:700; font-size:13px; padding:11px; border-radius:var(--pk-radius-md); text-decoration:none; border:1px solid var(--pk-border-card);">
          Back to Player App
        </a>
      </div>
    </div>
  </main>
</body>
</html>
