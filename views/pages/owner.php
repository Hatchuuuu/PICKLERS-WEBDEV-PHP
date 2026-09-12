<?php
declare(strict_types=1);
/**
 * Picklers - Facility Owner Portal (Modular Orchestrator)
 * @var string $activeTab
 * @var array $currentFacility
 * @var array $facilities
 * @var array $metrics
 * @var array $liveCourts
 * @var array $courts
 * @var array $bookings
 * @var array $pendingRequests
 * @var array $openPlayMatches
 * @var array $tournaments
 * @var array $earnings
 * @var array $conversations
 * @var array $staffMembers
 * @var array $amenities
 * @var array|null $currentUser
 */
$tab = $activeTab ?? 'dashboard';
?>
<!DOCTYPE html>
<html lang="en" class="dark">
<?php require VIEWS_PATH . '/partials/owner/_head.php'; ?>
<body>

  <!-- Mobile Top Header Bar -->
  <?php require VIEWS_PATH . '/partials/owner/_mobile-header.php'; ?>

  <!-- 1. Owner Fixed Left Sidebar -->
  <?php require VIEWS_PATH . '/partials/owner/_sidebar.php'; ?>

  <!-- 2. Main Content Area -->
  <main class="owner-main-content">
    <?php if ($tab === 'dashboard'): ?>
      <?php require VIEWS_PATH . '/partials/owner/_tab-dashboard.php'; ?>
    <?php elseif ($tab === 'courts'): ?>
      <?php require VIEWS_PATH . '/partials/owner/_tab-courts.php'; ?>
    <?php elseif ($tab === 'tournaments'): ?>
      <?php require VIEWS_PATH . '/partials/owner/_tab-tournaments.php'; ?>
    <?php elseif ($tab === 'staff'): ?>
      <?php require VIEWS_PATH . '/partials/owner/_tab-staff.php'; ?>
    <?php elseif ($tab === 'earnings'): ?>
      <?php require VIEWS_PATH . '/partials/owner/_tab-earnings.php'; ?>
    <?php elseif ($tab === 'messages'): ?>
      <?php require VIEWS_PATH . '/partials/owner/_tab-messages.php'; ?>
    <?php elseif ($tab === 'settings'): ?>
      <?php require VIEWS_PATH . '/partials/owner/_tab-settings.php'; ?>
    <?php endif; ?>
  </main>

  <!-- 3. Mobile Fixed Bottom Navigation -->
  <?php require VIEWS_PATH . '/partials/owner/_bottom-nav.php'; ?>

  <!-- 4. Modals -->
  <?php require VIEWS_PATH . '/partials/owner/_modals.php'; ?>

  <!-- 5. Scripts -->
  <?php require VIEWS_PATH . '/partials/owner/_scripts.php'; ?>

</body>
</html>
