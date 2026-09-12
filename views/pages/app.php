<?php
declare(strict_types=1);
/**
 * Picklers - Player App Shell
 *
 * Orchestrator view: assembles the app from focused partial files.
 *
 * @var array  $currentUser
 * @var string $activeTab
 * @var array  $userNotifications
 * @var int    $unreadNotifsCount
 * @var string $csrfToken
 * @var \Picklers\Core\Database $db
 *
 * Partials directory: views/partials/app/
 */

$partialsDir = VIEWS_PATH . '/partials/app';
?>
<!DOCTYPE html>
<html lang="en" class="dark">
<?php require $partialsDir . '/_head.php'; ?>
<body class="app-body">

  <?php require $partialsDir . '/_sidebar.php'; ?>

  <?php require $partialsDir . '/_mobile-header.php'; ?>

  <!-- Main Content Router -->
  <main class="app-main">
    <div class="app-content-container">
      <?php require $partialsDir . '/_tab-play.php'; ?>
      <?php require $partialsDir . '/_tab-explore.php'; ?>
      <?php require $partialsDir . '/_tab-wallet.php'; ?>
      <?php require $partialsDir . '/_tab-bookings.php'; ?>
      <?php require $partialsDir . '/_tab-settings.php'; ?>
    </div>
  </main>

  <?php require $partialsDir . '/_bottom-nav.php'; ?>

  <?php require $partialsDir . '/_modals.php'; ?>

  <?php require $partialsDir . '/_scripts.php'; ?>

</body>
</html>
