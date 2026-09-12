<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="description" content="Picklers Court Owner Portal — Manage pickleball facility courts, monitor live bookings, organize tournaments, and analyze revenue streams.">
  <meta name="color-scheme" content="dark light">
  <meta name="csrf-token" content="<?= \Picklers\Middleware\CsrfMiddleware::getToken() ?>">
  <title>Court Owner Portal | PICKLERS Philippines</title>
  <link rel="icon" type="image/svg+xml" href="<?= \Picklers\Helpers\Url::to('favicon.svg') ?>">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Montserrat:wght@700;800;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= \Picklers\Helpers\Url::asset('css/style.css', true) ?>">
  <link rel="stylesheet" href="<?= \Picklers\Helpers\Url::asset('css/theme.css', true) ?>">
  <link rel="stylesheet" href="<?= \Picklers\Helpers\Url::asset('css/owner.css', true) ?>">
  <link rel="stylesheet" href="<?= \Picklers\Helpers\Url::asset('css/tournament.css', true) ?>">
  <link rel="stylesheet" href="<?= \Picklers\Helpers\Url::asset('css/ux-core.css', true) ?>">
  <script src="https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.js"></script>
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
