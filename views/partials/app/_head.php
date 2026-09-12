<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <meta name="description" content="Picklers — The premier pickleball community platform. Book courts, join open play matches, top up wallet credits, and connect with players.">
  <meta name="color-scheme" content="dark light">
  <meta name="csrf-token" content="<?php echo htmlspecialchars($csrfToken); ?>">
  <title>PICKLERS App — Find Courts, Open Play, Bookings</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Montserrat:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= \Picklers\Helpers\Url::asset('style.css', true) ?>">
  <link rel="stylesheet" href="<?= \Picklers\Helpers\Url::asset('css/theme.css', true) ?>">
  <link rel="stylesheet" href="<?= \Picklers\Helpers\Url::asset('css/app.css', true) ?>">
  <link rel="stylesheet" href="<?= \Picklers\Helpers\Url::asset('css/ux-core.css', true) ?>">
  <script>
    (function() {
      const savedTheme = localStorage.getItem('picklers_theme') || 'dark';
      if (savedTheme === 'light') {
        document.documentElement.classList.remove('dark');
        document.documentElement.classList.add('light');
      } else {
        document.documentElement.classList.remove('light');
        document.documentElement.classList.add('dark');
      }
    })();
  </script>
</head>
