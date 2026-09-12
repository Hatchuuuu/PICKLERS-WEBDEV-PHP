<?php
declare(strict_types=1);
/**
 * Picklers - Admin Console
 * @var array $currentUser
 * @var array $users
 * @var array $usersById
 * @var array $facilities
 * @var array $bookings
 * @var array $ownerApplications
 * @var array $pendingApplications
 * @var int $totalUsers
 * @var int $verifiedUsers
 * @var int $totalFacilities
 * @var int $totalBookings
 * @var int $verifiedPartners
 * @var float $disputeFreePct
 * @var float $grossVolume
 * @var int $activePromos
 * @var bool $usingMySQL
 */
$users               = $users ?? [];
$usersById           = $usersById ?? [];
$facilities          = $facilities ?? [];
$bookings            = $bookings ?? [];
$ownerApplications   = $ownerApplications ?? [];
$pendingApplications = $pendingApplications ?? [];
$totalUsers          = (int)($totalUsers ?? count($users));
$verifiedUsers       = (int)($verifiedUsers ?? 0);
$totalFacilities     = (int)($totalFacilities ?? count($facilities));
$totalBookings       = (int)($totalBookings ?? count($bookings));
$verifiedPartners    = (int)($verifiedPartners ?? 0);
$disputeFreePct      = (float)($disputeFreePct ?? 100.0);
$grossVolume         = (float)($grossVolume ?? 0.0);
$activePromos        = (int)($activePromos ?? 0);
$usingMySQL          = (bool)($usingMySQL ?? false);
$currentUser         = $currentUser ?? ['name' => 'Admin', 'role' => 'admin'];
$activeTab           = trim((string)($_GET['tab'] ?? $activeTab ?? 'overview'));
if (!in_array($activeTab, ['overview', 'applications', 'facilities', 'bookings', 'users', 'moderation', 'ledger', 'promos', 'analytics', 'system'], true)) {
    $activeTab = 'overview';
}

/** Compact inline SVG icon set — mirrors the stroke-based line-icon style used across the rest of the app. */
if (!function_exists('admin_icon')) {
function admin_icon(string $name, int $size = 18): string {
    static $icons = [
        'grid'          => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
        'file-text'     => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>',
        'building'      => '<rect x="4" y="2" width="16" height="20" rx="1"/><path d="M9 22v-4h6v4"/><path d="M8 6h.01"/><path d="M16 6h.01"/><path d="M8 10h.01"/><path d="M16 10h.01"/><path d="M8 14h.01"/><path d="M16 14h.01"/>',
        'calendar'      => '<rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>',
        'users'         => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'shield'        => '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/>',
        'wallet'        => '<path d="M21 12V7H5a2 2 0 0 1 0-4h14v4"/><path d="M3 5v14a2 2 0 0 0 2 2h16v-5"/><path d="M18 12a2 2 0 0 0 0 4h4v-4Z"/>',
        'tag'           => '<path d="M12.586 2.586A2 2 0 0 0 11.172 2H4a2 2 0 0 0-2 2v7.172a2 2 0 0 0 .586 1.414l8.704 8.704a2.426 2.426 0 0 0 3.42 0l6.58-6.58a2.426 2.426 0 0 0 0-3.42z"/><circle cx="7.5" cy="7.5" r="1.5"/>',
        'bar-chart'     => '<line x1="12" y1="20" x2="12" y2="10"/><line x1="18" y1="20" x2="18" y2="4"/><line x1="6" y1="20" x2="6" y2="16"/>',
        'terminal'      => '<polyline points="4 17 10 11 4 5"/><line x1="12" y1="19" x2="20" y2="19"/>',
        'search'        => '<circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>',
        'chevron-down'  => '<polyline points="6 9 12 15 18 9"/>',
        'bell'          => '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/>',
        'alert-triangle'=> '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
        'check-circle'  => '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>',
        'user'          => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
        'scroll'        => '<path d="M8 21h12a2 2 0 0 0 2-2v-2H10v2a2 2 0 1 1-4 0V5a2 2 0 1 0-4 0v3h4"/><path d="M19 17V5a2 2 0 0 0-2-2H4"/>',
        'x'             => '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>',
        'log-out'       => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>',
        'database'      => '<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/>',
        'peso'          => '<path d="M6 3h9a4 4 0 0 1 0 8H6"/><path d="M6 21V3"/><path d="M3 9h13"/><path d="M3 13h9"/>',
        'clock'         => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
        'sparkle'       => '<path d="M12 3v4M12 17v4M3 12h4M17 12h4M5.6 5.6l2.8 2.8M15.6 15.6l2.8 2.8M18.4 5.6l-2.8 2.8M8.4 15.6l-2.8 2.8"/>',
        'plug'          => '<path d="M12 22v-5"/><path d="M9 8V2"/><path d="M15 8V2"/><path d="M18 8v5a4 4 0 0 1-4 4h-4a4 4 0 0 1-4-4V8Z"/>',
        'menu'          => '<line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/>',
    ];
    $path = $icons[$name] ?? $icons['grid'];
    return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' . $path . '</svg>';
}
}

/** Real analytics aggregates computed from already-loaded platform data (no fabricated numbers). */
$roleBreakdown = ['player' => 0, 'owner' => 0, 'admin' => 0];
foreach ($users as $u) {
    $r = $u['role'] ?? 'player';
    if (!isset($roleBreakdown[$r])) { $roleBreakdown[$r] = 0; }
    $roleBreakdown[$r]++;
}
$bookingStatusBreakdown = [];
foreach ($bookings as $b) {
    $s = $b['status'] ?? 'upcoming';
    $bookingStatusBreakdown[$s] = ($bookingStatusBreakdown[$s] ?? 0) + 1;
}
$topFacilities = $facilities;
usort($topFacilities, fn($a, $b) => (float)($b['rating'] ?? 0) <=> (float)($a['rating'] ?? 0));
$topFacilities = array_slice($topFacilities, 0, 5);

$verificationRate = $totalUsers > 0 ? round(($verifiedUsers / $totalUsers) * 100, 1) : 0.0;

/** Recent activity feed — real rows, most recent first, no placeholder timestamps. */
$recentBookings = $bookings;
usort($recentBookings, fn($a, $b) => strcmp((string)($b['created_at'] ?? $b['date'] ?? ''), (string)($a['created_at'] ?? $a['date'] ?? '')));
$recentBookings = array_slice($recentBookings, 0, 5);

$recentApplications = $ownerApplications;
usort($recentApplications, fn($a, $b) => strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? '')));
$recentApplications = array_slice($recentApplications, 0, 5);

$csrfToken = \Picklers\Middleware\CsrfMiddleware::getToken();
$adminName = $currentUser['name'] ?? 'Admin';
$isSuperAdmin = ($currentUser['role'] ?? '') === 'admin' || !empty($currentUser['is_admin']);
?>
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Admin Console — PICKLERS</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Montserrat:wght@600;700;800;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="style.css?v=<?php echo time(); ?>">
  <link rel="stylesheet" href="assets/css/ux-core.css?v=<?php echo time(); ?>">
  <script src="assets/js/ux-core.js?v=<?php echo time(); ?>" defer></script>
  <style>
    .admin-wrapper { display: flex; min-height: 100vh; }

    /* ===== SIDEBAR ===== */
    .admin-sidebar {
      background: var(--surface-base);
      border-right: 1px solid var(--border-subtle);
      padding: 0;
      position: fixed;
      top: 0; left: 0;
      width: 256px;
      height: 100vh;
      z-index: 100;
      display: flex;
      flex-direction: column;
    }
    .sidebar-nav-body {
      flex: 1;
      padding: 20px 14px;
      overflow-y: auto;
      display: flex;
      flex-direction: column;
    }
    .sidebar-nav-body::-webkit-scrollbar { width: 6px; }
    .sidebar-nav-body::-webkit-scrollbar-track { background: transparent; }
    .sidebar-nav-body::-webkit-scrollbar-thumb { background: var(--border-default); border-radius: 3px; }

    @keyframes shinyTextSweep {
      0% { background-position: 150% center; }
      100% { background-position: -50% center; }
    }

    .sidebar-logo {
      display: flex;
      align-items: center;
      text-decoration: none;
      padding: 24px 20px 18px;
      margin-bottom: 0;
      border-bottom: 1px solid var(--border-subtle);
      flex-shrink: 0;
    }
    .sidebar-logo img {
      width: 50px;
      height: 50px;
      margin-left: 2px;
      margin-right: -4px;
      margin-top: -3px;
      margin-bottom: -3px;
      object-fit: contain;
      flex-shrink: 0;
    }
    .sidebar-logo span, .sidebar-brand-name {
      font-family: 'Montserrat', var(--font-heading), sans-serif;
      font-weight: 900;
      font-size: 20px;
      letter-spacing: -0.03em;
      background-image: linear-gradient(120deg, #FFFFFF 0%, #FFFFFF 30%, #00D98B 50%, #FFFFFF 70%, #FFFFFF 100%);
      background-size: 200% auto;
      -webkit-background-clip: text !important;
      background-clip: text !important;
      -webkit-text-fill-color: transparent !important;
      color: transparent !important;
      animation: shinyTextSweep 3s linear infinite;
      display: inline-block;
      line-height: 1.2;
    }

    .sidebar-section { margin-bottom: 22px; }
    .sidebar-section-label {
      font-size: 10.5px; font-weight: 700; color: var(--ink-muted);
      text-transform: uppercase; letter-spacing: 0.7px;
      padding: 4px 12px 8px;
    }
    .sidebar-nav-item {
      display: flex; align-items: center; gap: 11px;
      padding: 9px 12px; margin-bottom: 3px;
      min-height: 44px;
      border-radius: 10px; cursor: pointer;
      transition: background 0.15s ease, color 0.15s ease;
      color: var(--ink-secondary); text-decoration: none;
      font-size: 13px; font-weight: 500; border: none; width: 100%;
      background: transparent; text-align: left; font-family: inherit;
    }
    .sidebar-nav-item svg { flex-shrink: 0; opacity: 0.85; }
    .sidebar-nav-item:hover { background: var(--surface-raised); color: var(--ink-primary); }
    .sidebar-nav-item.active { background: var(--accent-primary-muted); color: var(--accent-primary); font-weight: 700; }
    .sidebar-nav-item.active svg { opacity: 1; }

    .sidebar-badge {
      margin-left: auto; background: rgba(240, 72, 72, 0.18); color: var(--accent-danger);
      padding: 1px 7px; border-radius: 999px; font-size: 10px; font-weight: 800;
    }

    .sidebar-footer { margin-top: auto; padding-top: 16px; border-top: 1px solid var(--border-subtle); flex-shrink: 0; }
    .sidebar-user { display: flex; align-items: center; gap: 10px; padding: 10px; border-radius: 12px; background: var(--surface-raised); position: relative; }
    .sidebar-user-avatar {
      position: relative !important;
      width: 36px !important;
      height: 36px !important;
      border-radius: 50% !important;
      border: none !important;
      background: transparent !important;
      box-shadow: none !important;
      display: flex !important;
      align-items: center !important;
      justify-content: center !important;
      flex-shrink: 0 !important;
    }
    .sidebar-user-avatar::before {
      content: '' !important;
      position: absolute !important;
      top: -2px !important; left: -2px !important; right: -2px !important; bottom: -2px !important;
      border-radius: 50% !important;
      background: conic-gradient(from 0deg, rgba(0, 217, 139, 0.5) 0%, rgba(0, 229, 255, 0.5) 35%, rgba(0, 125, 254, 0.5) 70%, rgba(0, 217, 139, 0.5) 100%) !important;
      filter: blur(4px) !important;
      animation: spinBrandRing 4s linear infinite !important;
      z-index: 0 !important;
      opacity: 0.85 !important;
      pointer-events: none !important;
    }
    .sidebar-user-avatar::after {
      content: '' !important;
      position: absolute !important;
      top: -1.5px !important; left: -1.5px !important; right: -1.5px !important; bottom: -1.5px !important;
      border-radius: 50% !important;
      background: conic-gradient(from 0deg, #00D98B 0%, #00E5FF 35%, #007DFE 70%, #00D98B 100%) !important;
      animation: spinBrandRing 4s linear infinite !important;
      z-index: 1 !important;
      box-shadow: 0 0 8px rgba(0, 217, 139, 0.35) !important;
      pointer-events: none !important;
    }
    .sidebar-user-avatar > img,
    .sidebar-user-avatar > span,
    .sidebar-user-avatar > div {
      position: relative !important;
      z-index: 2 !important;
      width: 100% !important;
      height: 100% !important;
      border-radius: 50% !important;
      background: #08101F !important;
      display: flex !important;
      align-items: center !important;
      justify-content: center !important;
      font-weight: 900 !important;
      font-size: 15px !important;
      color: #00D98B !important;
      font-family: 'Montserrat', sans-serif !important;
      text-transform: uppercase !important;
      object-fit: cover !important;
    }
    .sidebar-user-info { flex: 1; min-width: 0; }
    .sidebar-user-name { font-size: 12px; font-weight: 700; color: var(--ink-primary); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .sidebar-user-role { font-size: 10.5px; color: var(--ink-muted); }
    .sidebar-badge-super {
      display: inline-block; background: linear-gradient(135deg, var(--accent-primary), #00A86B);
      color: #06131f; padding: 1px 6px; border-radius: 5px; font-size: 9px; font-weight: 800; margin-top: 2px;
    }
    .sidebar-logout {
      color: var(--ink-muted); background: transparent; border: none; cursor: pointer;
      display: flex; align-items: center; justify-content: center; padding: 4px; border-radius: 6px; flex-shrink: 0;
    }
    .sidebar-logout:hover { color: var(--accent-danger); background: rgba(240,72,72,0.1); }

    /* ===== MAIN ===== */
    .admin-main { margin-left: 256px; flex: 1; min-width: 0; width: calc(100% - 256px); display: flex; flex-direction: column; min-height: 100vh; }

    .admin-topbar {
      background: var(--surface-base); border-bottom: 1px solid var(--border-subtle);
      padding: 13px 24px; display: flex; align-items: center; justify-content: space-between;
      gap: 20px; flex-shrink: 0; position: sticky; top: 0; z-index: 50;
    }
    .topbar-search-wrap { flex: 1; max-width: 380px; position: relative; }
    .topbar-search-wrap svg { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--ink-muted); pointer-events: none; }
    .topbar-search {
      width: 100%; background: var(--surface-raised); border: 1px solid var(--border-subtle);
      border-radius: 10px; padding: 9px 14px 9px 36px; color: var(--ink-primary); font-size: 13px;
      font-family: var(--font-main);
    }
    .topbar-search:focus { outline: none; border-color: var(--accent-primary); }
    .topbar-search::placeholder { color: var(--ink-muted); }

    .topbar-actions { display: flex; align-items: center; gap: 12px; }
    .topbar-dropdown, .topbar-btn {
      background: var(--surface-raised); border: 1px solid var(--border-subtle);
      border-radius: 10px; padding: 9px 14px; color: var(--ink-primary); cursor: pointer;
      font-size: 13px; font-weight: 600; transition: all 0.15s ease;
      display: flex; align-items: center; gap: 6px; position: relative; font-family: var(--font-main);
    }
    .topbar-btn { padding: 9px; }
    .topbar-dropdown:hover, .topbar-btn:hover { background: var(--surface-overlay); border-color: var(--border-default); }
    .topbar-dot {
      position: absolute; top: 6px; right: 6px; width: 7px; height: 7px; border-radius: 50%;
      background: var(--accent-danger); border: 1.5px solid var(--surface-base);
    }

    .dropdown-panel {
      position: absolute; top: calc(100% + 8px); right: 0; width: 280px;
      background: var(--surface-overlay); border: 1px solid var(--border-default);
      border-radius: 14px; box-shadow: var(--shadow-lg); z-index: 200; overflow: hidden;
      opacity: 0; visibility: hidden; transform: translateY(-6px); transition: all 0.15s ease;
    }
    .dropdown-panel.open { opacity: 1; visibility: visible; transform: translateY(0); }
    .dropdown-header { padding: 12px 16px; font-size: 12px; font-weight: 700; color: var(--ink-muted); text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid var(--border-subtle); }
    .dropdown-item { padding: 12px 16px; border-bottom: 1px solid var(--border-subtle); font-size: 12.5px; color: var(--ink-secondary); }
    .dropdown-item:last-child { border-bottom: none; }
    .dropdown-item-title { color: var(--ink-primary); font-weight: 600; margin-bottom: 2px; }
    .dropdown-empty { padding: 24px 16px; text-align: center; color: var(--ink-muted); font-size: 12.5px; }
    .account-line { padding: 10px 16px; font-size: 12.5px; color: var(--ink-secondary); border-bottom: 1px solid var(--border-subtle); }
    .account-line strong { color: var(--ink-primary); }

    /* ===== CONTENT ===== */
    .admin-content { flex: 1; padding: 26px 28px 60px; overflow-y: auto; max-width: 100%; }
    .admin-panel { display: none; }
    .admin-panel.active { display: block; animation: panelIn 0.2s ease; }
    @keyframes panelIn { from { opacity: 0; transform: translateY(4px); } to { opacity: 1; transform: translateY(0); } }

    .content-header { margin-bottom: 24px; }
    .content-breadcrumb { font-size: 12px; color: var(--ink-muted); margin-bottom: 10px; display: flex; align-items: center; gap: 6px; }
    .content-title-wrap { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; flex-wrap: wrap; }
    .content-title { font-family: var(--font-heading); font-size: 26px; font-weight: 900; color: var(--ink-primary); letter-spacing: -0.3px; }
    .content-subtitle { font-size: 13.5px; color: var(--ink-secondary); margin-top: 4px; }

    .btn-primary-pill {
      background: var(--accent-primary); color: #06131f; padding: 10px 18px; border-radius: 10px;
      border: none; font-weight: 700; font-size: 13px; cursor: pointer; transition: all 0.15s ease;
      display: inline-flex; align-items: center; gap: 6px; white-space: nowrap; font-family: var(--font-main);
    }
    .btn-primary-pill:hover { background: var(--accent-primary-hover); transform: translateY(-1px); }
    .btn-ghost-pill {
      background: var(--surface-raised); color: var(--ink-primary); padding: 8px 14px; border-radius: 9px;
      border: 1px solid var(--border-subtle); font-weight: 600; font-size: 12.5px; cursor: pointer;
      transition: all 0.15s ease; display: inline-flex; align-items: center; gap: 6px; font-family: var(--font-main);
    }
    .btn-ghost-pill:hover { background: var(--surface-overlay); border-color: var(--border-default); }
    .btn-danger-pill {
      background: rgba(240,72,72,0.12); color: var(--accent-danger); padding: 8px 14px; border-radius: 9px;
      border: 1px solid rgba(240,72,72,0.3); font-weight: 700; font-size: 12px; cursor: pointer;
      font-family: var(--font-main);
    }
    .btn-danger-pill:hover { background: rgba(240,72,72,0.2); }

    /* Status strip (real system facts, replaces fabricated error banner) */
    .status-strip {
      background: var(--surface-raised); border: 1px solid var(--border-subtle);
      border-radius: 12px; padding: 12px 16px; margin-bottom: 22px;
      display: flex; align-items: center; gap: 12px; font-size: 12.5px; color: var(--ink-secondary);
    }
    .status-dot { width: 8px; height: 8px; border-radius: 50%; background: var(--accent-primary); flex-shrink: 0; box-shadow: 0 0 6px var(--accent-primary); }
    .status-strip strong { color: var(--ink-primary); }
    .status-strip .sep { color: var(--border-default); }

    /* KPI cards */
    .kpi-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 24px; }
    .kpi-card {
      background: var(--surface-raised); border: 1px solid var(--border-subtle);
      border-radius: 16px; padding: 20px; position: relative; overflow: hidden;
    }
    .kpi-card::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px; background: linear-gradient(90deg, var(--accent-primary), transparent); }
    .kpi-icon { width: 38px; height: 38px; border-radius: 10px; background: var(--accent-primary-muted); color: var(--accent-primary); display: flex; align-items: center; justify-content: center; margin-bottom: 14px; }
    .kpi-label { font-size: 10.5px; font-weight: 700; color: var(--ink-muted); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px; }
    .kpi-value { font-family: var(--font-heading); font-size: 30px; font-weight: 900; color: var(--ink-primary); margin-bottom: 2px; }
    .kpi-desc { font-size: 12px; color: var(--ink-muted); }

    /* Cards */
    .admin-card { background: var(--surface-raised); border: 1px solid var(--border-subtle); border-radius: 16px; padding: 22px; margin-bottom: 20px; overflow: hidden; }
    .card-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; gap: 12px; flex-wrap: wrap; }
    .card-title { font-family: var(--font-heading); font-size: 15px; font-weight: 800; color: var(--ink-primary); display: flex; align-items: center; gap: 9px; }
    .card-title svg { color: var(--accent-primary); }
    .card-count { font-size: 12px; color: var(--ink-muted); font-weight: 600; }

    .empty-state { text-align: center; padding: 44px 20px; color: var(--ink-muted); }
    .empty-state svg { color: var(--accent-primary); margin-bottom: 12px; }
    .empty-text { font-size: 14px; font-weight: 700; color: var(--ink-primary); margin-bottom: 4px; }
    .empty-subtext { font-size: 12.5px; color: var(--ink-muted); max-width: 380px; margin: 0 auto; }

    /* Application rows */
    .app-row {
      display: flex; align-items: center; gap: 14px; padding: 14px;
      border: 1px solid var(--border-subtle); border-radius: 12px; margin-bottom: 10px;
      background: var(--surface-overlay);
    }
    .app-row:last-child { margin-bottom: 0; }
    .app-avatar { width: 40px; height: 40px; border-radius: 10px; background: var(--accent-secondary); color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 14px; flex-shrink: 0; }
    .app-info { flex: 1; min-width: 0; }
    .app-name { font-size: 13.5px; font-weight: 700; color: var(--ink-primary); }
    .app-meta { font-size: 12px; color: var(--ink-muted); margin-top: 2px; }
    .app-actions { display: flex; gap: 8px; flex-shrink: 0; }
    .status-pill { padding: 3px 10px; border-radius: 999px; font-size: 10.5px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.3px; }
    .status-pill.pending { background: rgba(255,186,59,0.15); color: var(--accent-warning); }
    .status-pill.approved { background: var(--accent-primary-muted); color: var(--accent-primary); }
    .status-pill.rejected { background: rgba(240,72,72,0.15); color: var(--accent-danger); }

    /* Quick actions */
    .quick-actions-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 14px; }
    .action-item {
      background: var(--surface-overlay); border: 1px solid var(--border-subtle); border-radius: 13px;
      padding: 16px; cursor: pointer; transition: all 0.15s ease; display: flex; align-items: flex-start; gap: 13px;
    }
    .action-item:hover { border-color: var(--border-emphasis); transform: translateY(-1px); }
    .action-icon { width: 38px; height: 38px; border-radius: 10px; background: var(--surface-interactive); color: var(--accent-primary); display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
    .action-title { font-size: 13.5px; font-weight: 700; color: var(--ink-primary); margin-bottom: 3px; }
    .action-desc { font-size: 12px; color: var(--ink-muted); }

    /* Tables */
    .table-wrap { overflow-x: auto; border: none; border-radius: 0; margin: 14px -22px -22px -22px; }
    .admin-card > .table-wrap:first-child { margin-top: -22px; }
    .admin-card > .table-wrap:first-child th { border-top: none; }
    .admin-table { width: 100%; border-collapse: collapse; text-align: left; min-width: 640px; }
    .admin-table th {
      padding: 13px 22px; font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px;
      color: var(--ink-muted); border-top: 1px solid var(--border-subtle); border-bottom: 1px solid var(--border-subtle);
      background: rgba(0, 0, 0, 0.25); white-space: nowrap;
    }
    .admin-table td { padding: 14px 22px; font-size: 13px; color: var(--ink-secondary); border-bottom: 1px solid var(--border-subtle); vertical-align: middle; }
    .admin-table tbody tr:last-child td { border-bottom: none; }
    .admin-table tbody tr { transition: background 0.15s ease; }
    .admin-table tbody tr:hover { background: rgba(0, 217, 139, 0.035); }
    .row-primary { font-weight: 700; color: var(--ink-primary); }
    .row-mono { font-family: monospace; font-size: 11.5px; color: var(--ink-muted); }
    .cell-user { display: flex; align-items: center; gap: 12px; }
    .cell-avatar,
    .cell-avatar-initial,
    .cell-avatar-wrap {
      position: relative !important;
      width: 34px !important;
      height: 34px !important;
      border-radius: 50% !important;
      border: none !important;
      background: transparent !important;
      box-shadow: none !important;
      display: flex !important;
      align-items: center !important;
      justify-content: center !important;
      flex-shrink: 0 !important;
    }
    .cell-avatar::before,
    .cell-avatar-initial::before,
    .cell-avatar-wrap::before {
      content: '' !important;
      position: absolute !important;
      top: -2px !important; left: -2px !important; right: -2px !important; bottom: -2px !important;
      border-radius: 50% !important;
      background: conic-gradient(from 0deg, rgba(0, 217, 139, 0.5) 0%, rgba(0, 229, 255, 0.5) 35%, rgba(0, 125, 254, 0.5) 70%, rgba(0, 217, 139, 0.5) 100%) !important;
      filter: blur(4px) !important;
      animation: spinBrandRing 4s linear infinite !important;
      z-index: 0 !important;
      opacity: 0.85 !important;
      pointer-events: none !important;
    }
    .cell-avatar::after,
    .cell-avatar-initial::after,
    .cell-avatar-wrap::after {
      content: '' !important;
      position: absolute !important;
      top: -1.5px !important; left: -1.5px !important; right: -1.5px !important; bottom: -1.5px !important;
      border-radius: 50% !important;
      background: conic-gradient(from 0deg, #00D98B 0%, #00E5FF 35%, #007DFE 70%, #00D98B 100%) !important;
      animation: spinBrandRing 4s linear infinite !important;
      z-index: 1 !important;
      box-shadow: 0 0 8px rgba(0, 217, 139, 0.35) !important;
      pointer-events: none !important;
    }
    .cell-avatar > img,
    .cell-avatar > span,
    .cell-avatar > div,
    .cell-avatar-initial > img,
    .cell-avatar-initial > span,
    .cell-avatar-initial > div,
    .cell-avatar-wrap > img,
    .cell-avatar-wrap > span,
    .cell-avatar-wrap > div {
      position: absolute !important;
      inset: 0 !important;
      z-index: 2 !important;
      width: 100% !important;
      height: 100% !important;
      border-radius: 50% !important;
      background: #08101F !important;
      display: flex !important;
      align-items: center !important;
      justify-content: center !important;
      font-weight: 900 !important;
      font-size: 14px !important;
      color: #00D98B !important;
      font-family: 'Montserrat', sans-serif !important;
      text-transform: uppercase !important;
      object-fit: cover !important;
    }
    .cell-avatar [style*="display:none"],
    .cell-avatar [style*="display: none"],
    .cell-avatar-initial [style*="display:none"],
    .cell-avatar-initial [style*="display: none"],
    .cell-avatar-wrap [style*="display:none"],
    .cell-avatar-wrap [style*="display: none"] {
      display: none !important;
    }

    .role-select, .status-select {
      background: var(--surface-base); border: 1px solid var(--border-default); border-radius: 8px;
      color: var(--ink-primary); padding: 5px 8px; font-size: 12px; font-family: var(--font-main); cursor: pointer;
    }
    .verify-btn {
      border: none;
      padding: 5px 12px;
      border-radius: 7px;
      font-size: 10.5px;
      font-weight: 800;
      cursor: pointer;
      letter-spacing: 0.5px;
      transition: all 0.2s ease;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      outline: none;
      user-select: none;
    }
    .verify-btn.verified {
      background: rgba(0, 217, 139, 0.15);
      color: #00D98B;
      border: 1px solid rgba(0, 217, 139, 0.25);
    }
    .verify-btn.verified:hover {
      background: rgba(0, 217, 139, 0.28);
      border-color: rgba(0, 217, 139, 0.5);
      transform: translateY(-1px);
      box-shadow: 0 3px 10px rgba(0, 217, 139, 0.25);
    }
    .verify-btn.unverified {
      background: rgba(240, 72, 72, 0.15);
      color: #F87171;
      border: 1px solid rgba(240, 72, 72, 0.25);
    }
    .verify-btn.unverified:hover {
      background: rgba(240, 72, 72, 0.28);
      border-color: rgba(240, 72, 72, 0.5);
      transform: translateY(-1px);
      box-shadow: 0 3px 10px rgba(240, 72, 72, 0.25);
    }
    .verify-btn:active {
      transform: translateY(0);
      box-shadow: none;
    }
    .table-actions { display: flex; gap: 6px; flex-wrap: wrap; }
    .mini-btn {
      background: var(--surface-interactive); border: 1px solid var(--border-subtle); color: var(--ink-primary);
      border-radius: 7px; padding: 5px 9px; font-size: 11px; cursor: pointer; font-weight: 600; font-family: var(--font-main);
    }
    .mini-btn:hover { background: var(--surface-overlay); }
    .mini-btn.danger { color: var(--accent-danger); border-color: rgba(240,72,72,0.3); }

    /* User Action Dropdown Menu (Matches Picture 3) */
    .user-action-menu-wrap {
      position: relative;
      display: inline-block;
    }
    .user-action-menu-btn {
      width: 34px;
      height: 34px;
      border-radius: 9px;
      background: var(--surface-raised, #11233D);
      border: 1px solid var(--border-subtle, rgba(255, 255, 255, 0.12));
      color: var(--ink-primary, #FFFFFF);
      display: inline-flex;
      align-items: center;
      justify-content: center;
      cursor: pointer;
      transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
    }
    .user-action-menu-btn:hover,
    .user-action-menu-btn.active {
      background: rgba(0, 217, 139, 0.15);
      border-color: rgba(0, 217, 139, 0.4);
      color: #00D98B;
      transform: scale(1.05);
    }

    .user-action-dropdown {
      position: absolute;
      top: calc(100% + 6px);
      right: 0;
      width: 220px;
      background: #0E1A2D;
      border: 1px solid rgba(255, 255, 255, 0.14);
      border-radius: 14px;
      box-shadow: 0 14px 40px rgba(0, 0, 0, 0.65), 0 0 20px rgba(0, 217, 139, 0.12);
      z-index: 1000;
      padding: 6px;
      display: none;
      flex-direction: column;
      gap: 2px;
    }
    .user-action-dropdown.show {
      display: flex;
      animation: userMenuIn 0.2s cubic-bezier(0.16, 1, 0.3, 1) forwards;
    }
    @keyframes userMenuIn {
      from { opacity: 0; transform: translateY(-8px) scale(0.96); }
      to { opacity: 1; transform: translateY(0) scale(1); }
    }

    .action-dropdown-header {
      font-size: 10px;
      font-weight: 800;
      color: #94A3B8;
      text-transform: uppercase;
      letter-spacing: 0.6px;
      padding: 6px 10px 4px;
    }
    .action-dropdown-divider {
      height: 1px;
      background: rgba(255, 255, 255, 0.07);
      margin: 3px 0;
    }
    .action-dropdown-item {
      display: flex;
      align-items: center;
      gap: 9px;
      padding: 8px 10px;
      border-radius: 8px;
      font-size: 12.5px;
      font-weight: 600;
      color: #E2E8F0;
      background: transparent;
      border: none;
      cursor: pointer;
      width: 100%;
      text-align: left;
      transition: all 0.15s ease;
      font-family: inherit;
    }
    .action-dropdown-item:hover:not(:disabled) {
      background: rgba(255, 255, 255, 0.08);
      color: #FFFFFF;
    }
    .action-dropdown-item:disabled {
      opacity: 0.4;
      cursor: not-allowed;
    }
    .action-dropdown-item.active-role {
      color: #00D98B;
      font-weight: 700;
    }
    .action-dropdown-item .role-check {
      margin-left: auto;
      font-size: 12px;
      color: #00D98B;
      font-weight: 900;
    }
    .action-dropdown-item.item-danger {
      color: #F87171;
    }
    .action-dropdown-item.item-danger:hover:not(:disabled) {
      background: rgba(239, 68, 68, 0.15);
      color: #EF4444;
    }

    /* Ledger summary */
    .ledger-summary { display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px; margin-bottom: 18px; }
    .ledger-stat { background: var(--surface-overlay); border: 1px solid var(--border-subtle); border-radius: 12px; padding: 16px; }
    .ledger-stat-label { font-size: 10.5px; font-weight: 700; color: var(--ink-muted); text-transform: uppercase; letter-spacing: 0.4px; margin-bottom: 6px; }
    .ledger-stat-value { font-family: var(--font-heading); font-size: 22px; font-weight: 900; color: var(--accent-primary); }

    /* Analytics */
    .analytics-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
    .bar-row { display: flex; align-items: center; gap: 10px; margin-bottom: 12px; font-size: 12.5px; }
    .bar-row:last-child { margin-bottom: 0; }
    .bar-label { width: 90px; flex-shrink: 0; color: var(--ink-secondary); text-transform: capitalize; }
    .bar-track { flex: 1; height: 8px; background: var(--surface-interactive); border-radius: 999px; overflow: hidden; }
    .bar-fill { height: 100%; background: linear-gradient(90deg, var(--accent-primary), var(--accent-secondary)); border-radius: 999px; }
    .bar-value { width: 34px; text-align: right; flex-shrink: 0; font-weight: 700; color: var(--ink-primary); }
    .facility-rank { display: flex; align-items: center; gap: 10px; padding: 9px 0; border-bottom: 1px solid var(--border-subtle); font-size: 12.5px; }
    .facility-rank:last-child { border-bottom: none; }
    .rank-num { width: 20px; height: 20px; border-radius: 6px; background: var(--surface-interactive); color: var(--ink-muted); display: flex; align-items: center; justify-content: center; font-size: 10.5px; font-weight: 800; flex-shrink: 0; }
    .rank-name { flex: 1; color: var(--ink-primary); font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .rank-rating { color: var(--accent-warning); font-weight: 700; flex-shrink: 0; }

    /* System panel */
    .system-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px; margin-bottom: 20px; }
    .system-fact { background: var(--surface-overlay); border: 1px solid var(--border-subtle); border-radius: 12px; padding: 14px 16px; }
    .system-fact-label { font-size: 10.5px; font-weight: 700; color: var(--ink-muted); text-transform: uppercase; letter-spacing: 0.4px; margin-bottom: 6px; }
    .system-fact-value { font-size: 14px; font-weight: 700; color: var(--ink-primary); display: flex; align-items: center; gap: 6px; }
    .activity-row { display: flex; gap: 12px; padding: 11px 0; border-bottom: 1px solid var(--border-subtle); font-size: 12.5px; }
    .activity-row:last-child { border-bottom: none; }
    .activity-dot { width: 8px; height: 8px; border-radius: 50%; background: var(--accent-primary); margin-top: 5px; flex-shrink: 0; }
    .activity-text { color: var(--ink-secondary); }
    .activity-text strong { color: var(--ink-primary); }
    .activity-time { color: var(--ink-muted); font-size: 11px; margin-top: 2px; }

    /* Modal */
    .modal-overlay {
      position: fixed; inset: 0; background: rgba(4,10,20,0.72); backdrop-filter: blur(4px);
      z-index: 500; display: flex; align-items: center; justify-content: center;
      opacity: 0; visibility: hidden; transition: all 0.15s ease; padding: 20px;
    }
    .modal-overlay.open { opacity: 1; visibility: visible; }
    .modal-box {
      background: var(--surface-overlay); border: 1px solid var(--border-default); border-radius: 18px;
      padding: 24px; width: 100%; max-width: 400px; box-shadow: var(--shadow-lg);
      transform: scale(0.96); transition: transform 0.15s ease;
    }
    .modal-overlay.open .modal-box { transform: scale(1); }
    .modal-title { font-family: var(--font-heading); font-size: 17px; font-weight: 800; color: var(--ink-primary); margin-bottom: 4px; }
    .modal-sub { font-size: 12.5px; color: var(--ink-muted); margin-bottom: 18px; }
    .modal-field { margin-bottom: 14px; }
    .modal-field label { display: block; font-size: 11.5px; font-weight: 700; color: var(--ink-secondary); margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.3px; }
    .modal-field input, .modal-field select {
      width: 100%; background: var(--surface-base); border: 1px solid var(--border-default); border-radius: 10px;
      padding: 10px 12px; color: var(--ink-primary); font-size: 13px; font-family: var(--font-main);
    }
    .modal-field input:focus, .modal-field select:focus { outline: none; border-color: var(--accent-primary); }
    .modal-actions { display: flex; gap: 10px; margin-top: 18px; }
    .modal-actions button { flex: 1; }

    /* Toast */
    .toast-container { position: fixed; bottom: 26px; right: 26px; z-index: 10000; display: flex; flex-direction: column-reverse; gap: 10px; pointer-events: none; }
    .toast-card {
      pointer-events: auto; padding: 12px 18px; border-radius: 999px; background: var(--surface-overlay);
      border: 1px solid var(--accent-primary); color: var(--ink-primary); font-size: 13px; font-weight: 700;
      box-shadow: var(--shadow-lg), var(--shadow-glow); animation: toastIn 0.25s ease-out forwards;
    }
    .toast-card.error { border-color: var(--accent-danger); }
    @keyframes toastIn { from { opacity: 0; transform: translateY(12px) scale(0.95); } to { opacity: 1; transform: translateY(0) scale(1); } }

    .coming-soon { text-align: center; padding: 56px 24px; }
    .coming-soon svg { color: var(--accent-secondary); margin-bottom: 14px; }
    .coming-soon-title { font-family: var(--font-heading); font-size: 18px; font-weight: 800; color: var(--ink-primary); margin-bottom: 6px; }
    .coming-soon-desc { font-size: 13px; color: var(--ink-muted); max-width: 420px; margin: 0 auto; line-height: 1.7; }

    .admin-topbar-left { display: flex; align-items: center; gap: 12px; flex: 1; max-width: 420px; }
    .mobile-sidebar-btn {
      min-width: 44px;
      min-height: 44px;
      display: none; background: var(--surface-raised); border: 1px solid var(--border-subtle);
      color: var(--ink-primary); width: 38px; height: 38px; border-radius: 10px;
      align-items: center; justify-content: center; cursor: pointer; flex-shrink: 0;
      transition: background 0.15s ease, border-color 0.15s ease, color 0.15s ease;
    }
    .mobile-sidebar-btn:hover { border-color: var(--accent-primary); color: var(--accent-primary); background: var(--surface-interactive); }
    .sidebar-overlay {
      display: none; position: fixed; inset: 0; background: rgba(0, 0, 0, 0.65);
      backdrop-filter: blur(4px); -webkit-backdrop-filter: blur(4px); z-index: 999; opacity: 0; transition: opacity 0.25s ease;
    }
    .sidebar-overlay.active { display: block; opacity: 1; }

    @media (max-width: 1180px) {
      .kpi-grid { grid-template-columns: repeat(2, 1fr); }
      .analytics-grid { grid-template-columns: 1fr; }
      .system-grid { grid-template-columns: 1fr 1fr; }
      .ledger-summary { grid-template-columns: 1fr; }
    }
    @media (max-width: 1024px) {
      .mobile-sidebar-btn {
      min-width: 44px;
      min-height: 44px; display: flex; }
      /* Animate transform, not left: left triggers layout on every frame,
         transform stays on the compositor. */
      .admin-sidebar {
        left: 0;
        transform: translate3d(-100%, 0, 0);
        will-change: transform;
        z-index: 1000;
        transition: transform var(--motion-modal, 260ms) var(--ease-out, cubic-bezier(0.16, 1, 0.3, 1));
      }
      .admin-sidebar.mobile-open { transform: translate3d(0, 0, 0); box-shadow: var(--shadow-lg); }
      .admin-main { margin-left: 0; width: 100%; }
      .quick-actions-grid { grid-template-columns: 1fr; }
    }
    @media (max-width: 640px) {
      .topbar-search-wrap { display: none; }
      .content-title { font-size: 21px; }
      .kpi-grid { grid-template-columns: 1fr; }
      .system-grid { grid-template-columns: 1fr; }
      .admin-content { padding: 20px 16px 50px; }
    }
  </style>
</head>
<body>
  <div class="admin-wrapper">
    <!-- ===== MOBILE OVERLAY ===== -->
    <div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleAdminSidebar()"></div>
    <!-- ===== SIDEBAR ===== -->
    <aside class="admin-sidebar" id="adminSidebar">
      <div class="sidebar-logo">
        <img src="assets/images/PICKLERS_OFFICIAL_LOGO.svg" alt="Picklers" onerror="this.src='assets/images/PICKLERS_LOGO.png'">
        <span class="sidebar-brand-name">PICKLERS</span>
      </div>

      <div class="sidebar-nav-body">
        <div class="sidebar-section">
          <div class="sidebar-section-label">Overview</div>
          <button type="button" class="sidebar-nav-item <?php echo $activeTab === 'overview' ? 'active' : ''; ?>" data-tab="overview" onclick="switchTab('overview')">
            <?php echo admin_icon('grid'); ?> Control Center
          </button>
        </div>

        <div class="sidebar-section">
          <div class="sidebar-section-label">Operations</div>
          <button type="button" class="sidebar-nav-item <?php echo $activeTab === 'applications' ? 'active' : ''; ?>" data-tab="applications" onclick="switchTab('applications')">
            <?php echo admin_icon('file-text'); ?> Partner Applications
            <?php if (count($pendingApplications) > 0): ?>
              <span class="sidebar-badge"><?php echo count($pendingApplications); ?></span>
            <?php endif; ?>
          </button>
          <button type="button" class="sidebar-nav-item <?php echo $activeTab === 'facilities' ? 'active' : ''; ?>" data-tab="facilities" onclick="switchTab('facilities')">
            <?php echo admin_icon('building'); ?> Facilities &amp; Courts
          </button>
          <button type="button" class="sidebar-nav-item <?php echo $activeTab === 'bookings' ? 'active' : ''; ?>" data-tab="bookings" onclick="switchTab('bookings')">
            <?php echo admin_icon('calendar'); ?> Bookings &amp; Matches
          </button>
        </div>

        <div class="sidebar-section">
          <div class="sidebar-section-label">User Management</div>
          <button type="button" class="sidebar-nav-item <?php echo $activeTab === 'users' ? 'active' : ''; ?>" data-tab="users" onclick="switchTab('users')">
            <?php echo admin_icon('users'); ?> User Directory &amp; Roles
          </button>
          <button type="button" class="sidebar-nav-item <?php echo $activeTab === 'moderation' ? 'active' : ''; ?>" data-tab="moderation" onclick="switchTab('moderation')">
            <?php echo admin_icon('shield'); ?> Content Moderation
          </button>
        </div>

        <div class="sidebar-section">
          <div class="sidebar-section-label">Finance &amp; Promotions</div>
          <button type="button" class="sidebar-nav-item <?php echo $activeTab === 'ledger' ? 'active' : ''; ?>" data-tab="ledger" onclick="switchTab('ledger')">
            <?php echo admin_icon('wallet'); ?> Financial Ledger
          </button>
          <button type="button" class="sidebar-nav-item <?php echo $activeTab === 'promos' ? 'active' : ''; ?>" data-tab="promos" onclick="switchTab('promos')">
            <?php echo admin_icon('tag'); ?> Promo Codes
          </button>
        </div>

        <div class="sidebar-section">
          <div class="sidebar-section-label">Insights &amp; Reports</div>
          <button type="button" class="sidebar-nav-item <?php echo $activeTab === 'analytics' ? 'active' : ''; ?>" data-tab="analytics" onclick="switchTab('analytics')">
            <?php echo admin_icon('bar-chart'); ?> Analytics BI
          </button>
        </div>

        <div class="sidebar-section">
          <div class="sidebar-section-label">System &amp; Security</div>
          <button type="button" class="sidebar-nav-item <?php echo $activeTab === 'system' ? 'active' : ''; ?>" data-tab="system" onclick="switchTab('system')">
            <?php echo admin_icon('terminal'); ?> <?php echo $isSuperAdmin ? 'Lead Developer' : 'System'; ?>
          </button>
        </div>
      </div>

      <div class="sidebar-footer">
        <div class="sidebar-user">
          <?php
            $adminAvatarUrl = trim($currentUser['avatar_url'] ?? '');
            $hasAdminAvatar = !empty($adminAvatarUrl) && stripos($adminAvatarUrl, 'unsplash.com') === false;
            $adminInitial = strtoupper(substr(trim($currentUser['name'] ?? $adminName), 0, 1)) ?: 'P';
          ?>
          <div class="sidebar-user-avatar">
            <?php if ($hasAdminAvatar): ?>
              <img src="<?php echo htmlspecialchars($adminAvatarUrl); ?>" alt="Avatar" style="width:100%; height:100%; border-radius:50%; object-fit:cover;">
            <?php else: ?>
              <span><?php echo $adminInitial; ?></span>
            <?php endif; ?>
          </div>
          <div class="sidebar-user-info">
            <div class="sidebar-user-name"><?php echo htmlspecialchars($adminName); ?></div>
            <div class="sidebar-user-role">
              <?php if ($isSuperAdmin): ?>
                <span class="sidebar-badge-super">SUPER_ADMIN</span>
              <?php else: ?>
                <span>Developer</span>
              <?php endif; ?>
            </div>
          </div>
          <a href="app.php" class="sidebar-logout" title="Back to app" aria-label="Back to app"><?php echo admin_icon('log-out', 16); ?></a>
        </div>
      </div>
    </aside>

    <!-- ===== MAIN ===== -->
    <main class="admin-main">
      <header class="admin-topbar">
        <div class="admin-topbar-left">
          <button type="button" class="mobile-sidebar-btn" onclick="toggleAdminSidebar()" title="Open Navigation Menu" aria-label="Open Navigation Menu">
            <?php echo admin_icon('menu', 20); ?>
          </button>
          <div class="topbar-search-wrap">
            <?php echo admin_icon('search', 16); ?>
            <input type="text" class="topbar-search" placeholder="Search admin hub..." id="adminSearch">
          </div>
        </div>

        <div class="topbar-actions">


          <div style="position:relative;">
            <button type="button" class="topbar-btn" id="bellBtn" onclick="toggleDropdown('bellDropdown')">
              <?php echo admin_icon('bell', 16); ?>
              <?php if (count($pendingApplications) > 0 || !empty($recentBookings)): ?><span class="topbar-dot"></span><?php endif; ?>
            </button>
            <div class="dropdown-panel" id="bellDropdown" style="width: 300px;">
              <div class="dropdown-header">Recent Activity</div>
              <?php if (empty($recentBookings) && empty($recentApplications)): ?>
                <div class="dropdown-empty">No recent platform activity yet.</div>
              <?php else: ?>
                <?php foreach (array_slice($recentApplications, 0, 2) as $app): ?>
                  <div class="dropdown-item">
                    <div class="dropdown-item-title">New owner application</div>
                    <?php echo htmlspecialchars($app['facility_name'] ?? 'Unnamed facility'); ?> — <?php echo htmlspecialchars($app['owner_name'] ?? 'Applicant'); ?>
                  </div>
                <?php endforeach; ?>
                <?php foreach (array_slice($recentBookings, 0, 3) as $bk): ?>
                  <div class="dropdown-item">
                    <div class="dropdown-item-title">Booking #<?php echo htmlspecialchars((string)($bk['id'] ?? '')); ?></div>
                    <?php echo htmlspecialchars($bk['facility_name'] ?? 'Facility'); ?> · <?php echo htmlspecialchars(ucfirst((string)($bk['status'] ?? 'upcoming'))); ?>
                  </div>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </header>

      <div class="admin-content">

        <!-- ===================== OVERVIEW ===================== -->
        <section class="admin-panel <?php echo $activeTab === 'overview' ? 'active' : ''; ?>" data-panel="overview">
          <div class="content-header">
            <div class="content-title-wrap">
              <div>
                <h1 class="content-title">Admin Overview</h1>
                <p class="content-subtitle">Platform performance, application queue, and quick actions</p>
              </div>
              <button type="button" class="btn-primary-pill" onclick="switchTab('applications')">
                <?php echo admin_icon('check-circle', 15); ?> Review Applications
              </button>
            </div>
          </div>

          <div class="kpi-grid">
            <div class="kpi-card">
              <div class="kpi-icon"><?php echo admin_icon('users', 18); ?></div>
              <div class="kpi-label">Total Users</div>
              <div class="kpi-value"><?php echo number_format($totalUsers); ?></div>
              <div class="kpi-desc">Registered players</div>
            </div>
            <div class="kpi-card">
              <div class="kpi-icon"><?php echo admin_icon('file-text', 18); ?></div>
              <div class="kpi-label">Pending Apps</div>
              <div class="kpi-value"><?php echo count($pendingApplications); ?></div>
              <div class="kpi-desc">Needs review</div>
            </div>
            <div class="kpi-card">
              <div class="kpi-icon"><?php echo admin_icon('building', 18); ?></div>
              <div class="kpi-label">Facility Owners</div>
              <div class="kpi-value"><?php echo number_format($verifiedPartners); ?></div>
              <div class="kpi-desc">Verified partners</div>
            </div>
            <div class="kpi-card">
              <div class="kpi-icon"><?php echo admin_icon('tag', 18); ?></div>
              <div class="kpi-label">Active Promos</div>
              <div class="kpi-value"><?php echo number_format($activePromos); ?></div>
              <div class="kpi-desc">Running campaigns</div>
            </div>
          </div>

          <div class="admin-card">
            <div class="card-header">
              <div class="card-title"><?php echo admin_icon('file-text', 18); ?> Pending Owner Applications</div>
              <a href="#" class="btn-ghost-pill" onclick="switchTab('applications'); return false;">View All Queue →</a>
            </div>
            <?php if (empty($pendingApplications)): ?>
              <div class="empty-state">
                <?php echo admin_icon('sparkle', 40); ?>
                <div class="empty-text">Queue is clear!</div>
                <div class="empty-subtext">All submitted owner applications have been reviewed.</div>
              </div>
            <?php else: ?>
              <?php foreach (array_slice($pendingApplications, 0, 4) as $app): ?>
                <?php $applicant = $usersById[(string)($app['user_id'] ?? '')] ?? null; ?>
                <div class="app-row">
                  <div class="app-avatar"><?php echo strtoupper(substr((string)($app['owner_name'] ?? 'A'), 0, 1)); ?></div>
                  <div class="app-info">
                    <div class="app-name"><?php echo htmlspecialchars($app['facility_name'] ?? 'Unnamed Facility'); ?></div>
                    <div class="app-meta"><?php echo htmlspecialchars($app['owner_name'] ?? 'Applicant'); ?> · <?php echo htmlspecialchars($app['address'] ?? '—'); ?></div>
                  </div>
                  <div class="app-actions">
                    <button type="button" class="mini-btn" onclick="approveApplication('<?php echo htmlspecialchars((string)($app['user_id'] ?? '')); ?>', '<?php echo htmlspecialchars((string)($app['id'] ?? '')); ?>')">Approve</button>
                    <button type="button" class="mini-btn danger" onclick="rejectApplication('<?php echo htmlspecialchars((string)($app['user_id'] ?? '')); ?>', '<?php echo htmlspecialchars((string)($app['id'] ?? '')); ?>')">Reject</button>
                  </div>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>

          <div class="admin-card">
            <div class="card-header"><div class="card-title"><?php echo admin_icon('sparkle', 18); ?> Quick Actions</div></div>
            <div class="quick-actions-grid">
              <div class="action-item" onclick="switchTab('users')">
                <div class="action-icon"><?php echo admin_icon('user', 18); ?></div>
                <div><div class="action-title">User Moderation</div><div class="action-desc">Search and manage user accounts</div></div>
              </div>
              <div class="action-item" onclick="switchTab('analytics')">
                <div class="action-icon"><?php echo admin_icon('bar-chart', 18); ?></div>
                <div><div class="action-title">Executive Analytics BI</div><div class="action-desc">Platform metrics &amp; facility ranks</div></div>
              </div>
              <div class="action-item" onclick="switchTab('promos')">
                <div class="action-icon"><?php echo admin_icon('tag', 18); ?></div>
                <div><div class="action-title">Create Promo Code</div><div class="action-desc">Launch discount campaign</div></div>
              </div>
              <div class="action-item" onclick="switchTab('system')">
                <div class="action-icon"><?php echo admin_icon('scroll', 18); ?></div>
                <div><div class="action-title">Audit Log Trail</div><div class="action-desc">Inspect recent platform activity</div></div>
              </div>
            </div>
          </div>
        </section>

        <!-- ===================== PARTNER APPLICATIONS ===================== -->
        <section class="admin-panel <?php echo $activeTab === 'applications' ? 'active' : ''; ?>" data-panel="applications">
          <div class="content-header">

            <h1 class="content-title">Partner Applications</h1>
            <p class="content-subtitle">Every court-owner application ever submitted, newest first</p>
          </div>
          <div class="admin-card">
            <div class="card-header">
              <div class="card-title"><?php echo admin_icon('file-text', 18); ?> All Applications</div>
              <span class="card-count"><?php echo count($ownerApplications); ?> total</span>
            </div>
            <?php if (empty($ownerApplications)): ?>
              <div class="empty-state">
                <?php echo admin_icon('file-text', 40); ?>
                <div class="empty-text">No applications yet</div>
                <div class="empty-subtext">Owner applications submitted via the onboarding wizard will appear here.</div>
              </div>
            <?php else: ?>
              <div class="table-wrap searchable">
                <table class="admin-table">
                  <thead><tr><th>Facility</th><th>Applicant</th><th>Entity</th><th>Courts</th><th>Submitted</th><th>Status</th><th>Action</th></tr></thead>
                  <tbody>
                    <?php foreach ($ownerApplications as $app): ?>
                      <?php $st = $app['status'] ?? 'pending_review'; ?>
                      <tr>
                        <td class="row-primary"><?php echo htmlspecialchars($app['facility_name'] ?? '—'); ?></td>
                        <td><?php echo htmlspecialchars($app['owner_name'] ?? '—'); ?><br><span class="row-mono"><?php echo htmlspecialchars($app['business_email'] ?? ''); ?></span></td>
                        <td><?php echo htmlspecialchars($app['entity_name'] ?? '—'); ?></td>
                        <td><?php echo (int)($app['courts_count'] ?? 0); ?></td>
                        <td><?php echo htmlspecialchars($app['created_at'] ?? '—'); ?></td>
                        <td>
                          <span class="status-pill <?php echo $st === 'pending_review' ? 'pending' : htmlspecialchars($st); ?>">
                            <?php echo $st === 'pending_review' ? 'Pending' : htmlspecialchars(ucfirst($st)); ?>
                          </span>
                        </td>
                        <td>
                          <?php if ($st === 'pending_review'): ?>
                            <div class="table-actions">
                              <button type="button" class="mini-btn" onclick="approveApplication('<?php echo htmlspecialchars((string)($app['user_id'] ?? '')); ?>', '<?php echo htmlspecialchars((string)($app['id'] ?? '')); ?>')">Approve</button>
                              <button type="button" class="mini-btn danger" onclick="rejectApplication('<?php echo htmlspecialchars((string)($app['user_id'] ?? '')); ?>', '<?php echo htmlspecialchars((string)($app['id'] ?? '')); ?>')">Reject</button>
                            </div>
                          <?php else: ?>
                            <span class="row-mono">—</span>
                          <?php endif; ?>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
          </div>
        </section>

        <!-- ===================== FACILITIES & COURTS ===================== -->
        <section class="admin-panel <?php echo $activeTab === 'facilities' ? 'active' : ''; ?>" data-panel="facilities">
          <div class="content-header">

            <h1 class="content-title">Facilities &amp; Courts</h1>
            <p class="content-subtitle"><?php echo count($facilities); ?> partner facilities listed on the platform</p>
          </div>
          <div class="admin-card">
            <div class="card-header">
              <div class="card-title"><?php echo admin_icon('building', 18); ?> Partner Facilities</div>
              <span class="card-count"><?php echo count($facilities); ?> total</span>
            </div>
            <?php if (empty($facilities)): ?>
              <div class="empty-state">
                <?php echo admin_icon('building', 40); ?>
                <div class="empty-text">No facilities yet</div>
                <div class="empty-subtext">Approved owner applications will show up here once they list courts.</div>
              </div>
            <?php else: ?>
              <div class="table-wrap searchable">
                <table class="admin-table">
                  <thead><tr><th>Facility</th><th>Location</th><th>Courts</th><th>Hourly Rate</th><th>Rating</th><th>Status</th><th>Action</th></tr></thead>
                  <tbody>
                    <?php foreach ($facilities as $f): ?>
                      <tr>
                        <td class="cell-user">
                          <img class="cell-avatar" src="<?php echo htmlspecialchars($f['image_url'] ?? $f['image'] ?? 'assets/images/facilities/overhead_dumaguete.jpg'); ?>" alt="">
                          <span class="row-primary"><?php echo htmlspecialchars($f['name'] ?? '—'); ?></span>
                        </td>
                        <td><?php echo htmlspecialchars($f['location'] ?? '—'); ?></td>
                        <td><?php echo (int)($f['courts_count'] ?? 4); ?> courts</td>
                        <td class="row-primary" style="color: var(--accent-primary);"><?php echo htmlspecialchars((string)($f['price'] ?? '₱' . number_format((float)($f['price_numeric'] ?? 180), 2))); ?></td>
                        <td style="color: var(--accent-warning); font-weight:700;">★ <?php echo htmlspecialchars((string)($f['rating'] ?? '4.9')); ?></td>
                        <td><span class="status-pill approved"><?php echo !empty($f['is_verified']) ? 'Verified' : 'Active'; ?></span></td>
                        <td><a href="app.php?tab=play&facility=<?php echo urlencode((string)($f['id'] ?? '')); ?>" class="mini-btn" style="text-decoration:none; display:inline-block;">View →</a></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
          </div>
        </section>

        <!-- ===================== BOOKINGS & MATCHES ===================== -->
        <section class="admin-panel <?php echo $activeTab === 'bookings' ? 'active' : ''; ?>" data-panel="bookings">
          <div class="content-header">

            <h1 class="content-title">Bookings &amp; Matches</h1>
            <p class="content-subtitle"><?php echo count($bookings); ?> total reservations · <?php echo $disputeFreePct; ?>% dispute-free</p>
          </div>
          <div class="admin-card">
            <div class="card-header">
              <div class="card-title"><?php echo admin_icon('calendar', 18); ?> Platform Bookings</div>
              <span class="card-count"><?php echo count($bookings); ?> total</span>
            </div>
            <?php if (empty($bookings)): ?>
              <div class="empty-state">
                <?php echo admin_icon('calendar', 40); ?>
                <div class="empty-text">No bookings recorded yet</div>
                <div class="empty-subtext">Player reservations will appear here as they come in.</div>
              </div>
            <?php else: ?>
              <div class="table-wrap searchable">
                <table class="admin-table">
                  <thead><tr><th>Booking</th><th>Facility &amp; Court</th><th>Schedule</th><th>Price</th><th>Payment</th><th>Status</th><th>Action</th></tr></thead>
                  <tbody>
                    <?php foreach ($bookings as $b): ?>
                      <?php $bst = $b['status'] ?? 'upcoming'; ?>
                      <tr>
                        <td class="row-mono">#<?php echo htmlspecialchars((string)($b['id'] ?? '')); ?></td>
                        <td class="row-primary"><?php echo htmlspecialchars($b['court_name'] ?? '—'); ?><br><span style="font-weight:400; color:var(--ink-muted); font-size:11px;"><?php echo htmlspecialchars($b['facility_name'] ?? ''); ?></span></td>
                        <td><?php echo htmlspecialchars($b['date'] ?? '—'); ?><br><span style="color:var(--ink-muted); font-size:11px;"><?php echo htmlspecialchars($b['time'] ?? ''); ?></span></td>
                        <td class="row-primary" style="color: var(--accent-primary);">₱<?php echo number_format((float)($b['price'] ?? 0), 2); ?></td>
                        <td><?php echo htmlspecialchars($b['payment_method'] ?? '—'); ?></td>
                        <td>
                          <select class="status-select" onchange="updateBookingStatus('<?php echo htmlspecialchars((string)($b['id'] ?? '')); ?>', this.value)" <?php echo $bst === 'cancelled' ? 'disabled' : ''; ?>>
                            <?php foreach (['upcoming', 'confirmed', 'completed', 'cancelled'] as $opt): ?>
                              <option value="<?php echo $opt; ?>" <?php echo $bst === $opt ? 'selected' : ''; ?>><?php echo ucfirst($opt); ?></option>
                            <?php endforeach; ?>
                          </select>
                        </td>
                        <td>
                          <?php if ($bst !== 'cancelled'): ?>
                            <button type="button" class="mini-btn danger" onclick="cancelBooking('<?php echo htmlspecialchars((string)($b['id'] ?? '')); ?>')">Cancel</button>
                          <?php else: ?>
                            <span class="row-mono">—</span>
                          <?php endif; ?>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
          </div>
        </section>

        <!-- ===================== USER DIRECTORY & ROLES ===================== -->
        <section class="admin-panel <?php echo $activeTab === 'users' ? 'active' : ''; ?>" data-panel="users">
          <div class="content-header">

            <h1 class="content-title">User Directory &amp; Roles</h1>
            <p class="content-subtitle"><?php echo count($users); ?> registered accounts · <?php echo $verificationRate; ?>% verified</p>
          </div>
          <div class="admin-card">
            <div class="card-header">
              <div class="card-title"><?php echo admin_icon('users', 18); ?> Registered Users</div>
              <span class="card-count"><?php echo count($users); ?> total</span>
            </div>
            <div class="table-wrap searchable">
              <table class="admin-table">
                <thead><tr><th>User</th><th>Email / Phone</th><th>Role</th><th>Verification</th><th>Wallet</th><th>Actions</th></tr></thead>
                <tbody>
                  <?php foreach ($users as $u): ?>
                    <?php $uid = (string)($u['id'] ?? ''); $isSelf = $uid === (string)($currentUser['id'] ?? ''); ?>
                    <tr>
                      <td class="cell-user">
                        <?php
                          $uAvatar = trim($u['avatar_url'] ?? '');
                          $hasUAvatar = !empty($uAvatar) && stripos($uAvatar, 'unsplash.com') === false;
                          $uInitial = strtoupper(substr(trim($u['name'] ?? 'U'), 0, 1)) ?: 'U';
                        ?>
                        <div class="cell-avatar-wrap">
                          <?php if ($hasUAvatar): ?>
                            <img src="<?php echo htmlspecialchars($uAvatar); ?>" alt="Avatar" onerror="this.style.display='none'; if(this.nextElementSibling) this.nextElementSibling.style.display='flex';">
                            <span style="display:none;"><?php echo $uInitial; ?></span>
                          <?php else: ?>
                            <span><?php echo $uInitial; ?></span>
                          <?php endif; ?>
                        </div>
                        <div><div class="row-primary"><?php echo htmlspecialchars($u['name'] ?? '—'); ?></div><div class="row-mono"><?php echo htmlspecialchars($uid); ?></div></div>
                      </td>
                      <td><?php echo htmlspecialchars($u['email'] ?? '—'); ?><br><span style="color:var(--ink-muted); font-size:11px;"><?php echo htmlspecialchars($u['phone'] ?? '—'); ?></span></td>
                      <td>
                        <span class="status-pill <?php echo ($u['role'] ?? '') === 'admin' ? 'approved' : (($u['role'] ?? '') === 'owner' ? 'pending' : 'player'); ?>" style="font-size:11px;">
                          <?php echo htmlspecialchars(ucfirst((string)($u['role'] ?? 'player'))); ?>
                        </span>
                      </td>
                      <td>
                        <button type="button" class="verify-btn <?php echo ($u['verification_status'] ?? '') === 'verified' ? 'verified' : 'unverified'; ?>" onclick="toggleVerify('<?php echo htmlspecialchars($uid); ?>')" title="Click to toggle verification status">
                          <?php echo strtoupper((string)($u['verification_status'] ?? 'unverified')); ?>
                        </button>
                      </td>
                      <td class="row-primary" style="color: var(--accent-secondary);">₱<?php echo number_format((float)($u['wallet_balance'] ?? 0), 2); ?></td>
                      <td>
                        <div class="user-action-menu-wrap">
                          <button type="button" class="user-action-menu-btn" onclick="toggleUserActionMenu('menu-<?php echo htmlspecialchars($uid); ?>', event)" title="Account Actions" aria-label="Account Actions">
                            <?php echo admin_icon('menu', 18); ?>
                          </button>
                          <div class="user-action-dropdown" id="menu-<?php echo htmlspecialchars($uid); ?>">
                            <div class="action-dropdown-header">Manage <?php echo htmlspecialchars($u['name'] ?? 'Account'); ?></div>

                            <!-- Verification Toggle -->
                            <button type="button" class="action-dropdown-item" onclick="toggleVerify('<?php echo htmlspecialchars($uid); ?>'); closeAllUserActionMenus();">
                              <?php if (($u['verification_status'] ?? '') === 'verified'): ?>
                                <?php echo admin_icon('x', 15); ?>
                                <span style="color:#F87171;">Revoke Verification</span>
                              <?php else: ?>
                                <?php echo admin_icon('check-circle', 15); ?>
                                <span style="color:#00D98B;">Verify Account</span>
                              <?php endif; ?>
                            </button>

                            <div class="action-dropdown-divider"></div>
                            <div class="action-dropdown-header">Set Role</div>

                            <button type="button" class="action-dropdown-item <?php echo ($u['role'] ?? '') === 'player' ? 'active-role' : ''; ?>" onclick="updateRole('<?php echo htmlspecialchars($uid); ?>', 'player'); closeAllUserActionMenus();" <?php echo $isSelf ? 'disabled' : ''; ?>>
                              <?php echo admin_icon('user', 15); ?>
                              <span>Player</span>
                              <?php if (($u['role'] ?? '') === 'player'): ?><span class="role-check">✓</span><?php endif; ?>
                            </button>

                            <button type="button" class="action-dropdown-item <?php echo ($u['role'] ?? '') === 'owner' ? 'active-role' : ''; ?>" onclick="updateRole('<?php echo htmlspecialchars($uid); ?>', 'owner'); closeAllUserActionMenus();" <?php echo $isSelf ? 'disabled' : ''; ?>>
                              <?php echo admin_icon('building', 15); ?>
                              <span>Court Owner</span>
                              <?php if (($u['role'] ?? '') === 'owner'): ?><span class="role-check">✓</span><?php endif; ?>
                            </button>

                            <button type="button" class="action-dropdown-item <?php echo ($u['role'] ?? '') === 'admin' ? 'active-role' : ''; ?>" onclick="updateRole('<?php echo htmlspecialchars($uid); ?>', 'admin'); closeAllUserActionMenus();" <?php echo $isSelf ? 'disabled' : ''; ?>>
                              <?php echo admin_icon('shield', 15); ?>
                              <span>Admin</span>
                              <?php if (($u['role'] ?? '') === 'admin'): ?><span class="role-check">✓</span><?php endif; ?>
                            </button>

                            <div class="action-dropdown-divider"></div>
                            <div class="action-dropdown-header">Operations</div>

                            <button type="button" class="action-dropdown-item" onclick="openWalletModal('<?php echo htmlspecialchars($uid); ?>', '<?php echo htmlspecialchars(addslashes($u['name'] ?? 'User')); ?>'); closeAllUserActionMenus();">
                              <?php echo admin_icon('wallet', 15); ?>
                              <span>Top Up Wallet</span>
                            </button>

                            <button type="button" class="action-dropdown-item" onclick="openResetModal('<?php echo htmlspecialchars($uid); ?>', '<?php echo htmlspecialchars(addslashes($u['name'] ?? 'User')); ?>'); closeAllUserActionMenus();">
                              <?php echo admin_icon('terminal', 15); ?>
                              <span>Reset Password</span>
                            </button>

                            <button type="button" class="action-dropdown-item" onclick="openNotifModal('<?php echo htmlspecialchars($uid); ?>', '<?php echo htmlspecialchars(addslashes($u['name'] ?? 'User')); ?>'); closeAllUserActionMenus();">
                              <?php echo admin_icon('bell', 15); ?>
                              <span>Send System Notice</span>
                            </button>

                            <?php if (!$isSelf): ?>
                              <button type="button" class="action-dropdown-item" onclick="impersonateUser('<?php echo htmlspecialchars($uid); ?>', '<?php echo htmlspecialchars(addslashes($u['name'] ?? 'User')); ?>'); closeAllUserActionMenus();">
                                <?php echo admin_icon('log-out', 15); ?>
                                <span>Log In as User</span>
                              </button>

                              <div class="action-dropdown-divider"></div>
                              <?php if (($u['role'] ?? '') === 'deleted'): ?>
                                <button type="button" class="action-dropdown-item" style="color:#00D98B;" onclick="reactivateUser('<?php echo htmlspecialchars($uid); ?>', '<?php echo htmlspecialchars(addslashes($u['name'] ?? 'User')); ?>'); closeAllUserActionMenus();">
                                  <?php echo admin_icon('check-circle', 15); ?>
                                  <span>Reactivate / Unblock User</span>
                                </button>
                              <?php else: ?>
                                <button type="button" class="action-dropdown-item item-danger" onclick="deactivateUser('<?php echo htmlspecialchars($uid); ?>', '<?php echo htmlspecialchars(addslashes($u['name'] ?? 'User')); ?>'); closeAllUserActionMenus();">
                                  <?php echo admin_icon('alert-triangle', 15); ?>
                                  <span>Deactivate / Block User</span>
                                </button>
                              <?php endif; ?>
                            <?php endif; ?>
                          </div>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
        </section>

        <!-- ===================== CONTENT MODERATION (honest coming-soon) ===================== -->
        <section class="admin-panel <?php echo $activeTab === 'moderation' ? 'active' : ''; ?>" data-panel="moderation">
          <div class="content-header">

            <h1 class="content-title">Content Moderation</h1>
            <p class="content-subtitle">Community content review queue</p>
          </div>
          <div class="admin-card">
            <div class="coming-soon">
              <?php echo admin_icon('shield', 44); ?>
              <div class="coming-soon-title">No moderation backend wired up yet</div>
              <div class="coming-soon-desc">This section is reserved for reviewing flagged profiles, messages, and open-play listings once community reporting ships. In the meantime, use <strong style="color:var(--ink-primary)">User Directory &amp; Roles</strong> to verify or deactivate accounts directly.</div>
              <div style="margin-top:18px;"><button type="button" class="btn-ghost-pill" onclick="switchTab('users')">Go to User Directory →</button></div>
            </div>
          </div>
        </section>

        <!-- ===================== FINANCIAL LEDGER ===================== -->
        <section class="admin-panel <?php echo $activeTab === 'ledger' ? 'active' : ''; ?>" data-panel="ledger">
          <div class="content-header">

            <h1 class="content-title">Financial Ledger</h1>
            <p class="content-subtitle">Confirmed booking revenue across the platform</p>
          </div>

          <div class="ledger-summary">
            <div class="ledger-stat"><div class="ledger-stat-label">Gross Volume</div><div class="ledger-stat-value">₱<?php echo number_format($grossVolume, 2); ?></div></div>
            <div class="ledger-stat"><div class="ledger-stat-label">Total Bookings</div><div class="ledger-stat-value" style="color: var(--ink-primary);"><?php echo number_format($totalBookings); ?></div></div>
            <div class="ledger-stat"><div class="ledger-stat-label">Dispute-Free Rate</div><div class="ledger-stat-value" style="color: var(--accent-secondary);"><?php echo $disputeFreePct; ?>%</div></div>
          </div>

          <div class="admin-card">
            <?php $ledgerRows = array_values(array_filter($bookings, fn($b) => ($b['status'] ?? '') !== 'cancelled')); ?>
            <div class="card-header">
              <div class="card-title"><?php echo admin_icon('wallet', 18); ?> Transaction History</div>
              <span class="card-count"><?php echo count($ledgerRows); ?> settled</span>
            </div>
            <?php if (empty($ledgerRows)): ?>
              <div class="empty-state">
                <?php echo admin_icon('wallet', 40); ?>
                <div class="empty-text">No settled transactions yet</div>
                <div class="empty-subtext">Confirmed booking payments will appear here as revenue events.</div>
              </div>
            <?php else: ?>
              <div class="table-wrap searchable">
                <table class="admin-table">
                  <thead><tr><th>Booking</th><th>Facility</th><th>Date</th><th>Amount</th><th>Method</th><th>Status</th></tr></thead>
                  <tbody>
                    <?php foreach ($ledgerRows as $b): ?>
                      <tr>
                        <td class="row-mono">#<?php echo htmlspecialchars((string)($b['id'] ?? '')); ?></td>
                        <td class="row-primary"><?php echo htmlspecialchars($b['facility_name'] ?? '—'); ?></td>
                        <td><?php echo htmlspecialchars($b['date'] ?? '—'); ?></td>
                        <td class="row-primary" style="color: var(--accent-primary);">₱<?php echo number_format((float)($b['price'] ?? 0), 2); ?></td>
                        <td><?php echo htmlspecialchars($b['payment_method'] ?? '—'); ?></td>
                        <td><span class="status-pill approved"><?php echo htmlspecialchars(ucfirst((string)($b['status'] ?? 'upcoming'))); ?></span></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
          </div>
        </section>

        <!-- ===================== PROMO CODES ENGINE ===================== -->
        <section class="admin-panel <?php echo $activeTab === 'promos' ? 'active' : ''; ?>" data-panel="promos">
          <div class="content-header" style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:12px;">
            <div>
              <h1 class="content-title">Promo Codes &amp; Discounts</h1>
              <p class="content-subtitle">Setup discount campaigns, referral vouchers, and manage platform redemptions</p>
            </div>
            <button type="button" class="mini-btn" onclick="openCreatePromoModal()" style="background:var(--accent-primary); color:#0A1628; border:none; padding:10px 18px; border-radius:10px; font-weight:800; font-size:13px; cursor:pointer; display:inline-flex; align-items:center; gap:6px; box-shadow:0 4px 14px rgba(0,217,139,0.25);">
              <?php echo admin_icon('plus', 16); ?>
              <span>Create New Promo Code</span>
            </button>
          </div>

          <!-- Promo Stats Bar -->
          <div class="kpi-grid" style="grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); margin-bottom: 20px;">
            <div class="kpi-card">
              <div class="kpi-header">
                <span class="kpi-label">Active Promos</span>
                <div class="kpi-icon" style="background:rgba(0,217,139,0.12); color:#00D98B;"><?php echo admin_icon('tag', 18); ?></div>
              </div>
              <div class="kpi-value" style="color:#00D98B;"><?php echo (int)($promoStats['totalActive'] ?? 0); ?></div>
              <div class="kpi-sub" style="color:var(--ink-muted);">Live discount codes</div>
            </div>

            <div class="kpi-card">
              <div class="kpi-header">
                <span class="kpi-label">Total Redemptions</span>
                <div class="kpi-icon" style="background:rgba(56,189,248,0.12); color:#38BDF8;"><?php echo admin_icon('check-circle', 18); ?></div>
              </div>
              <div class="kpi-value" style="color:#38BDF8;"><?php echo number_format((int)($promoStats['totalRedemptions'] ?? 0)); ?></div>
              <div class="kpi-sub" style="color:var(--ink-muted);">Claimed by players</div>
            </div>

            <div class="kpi-card">
              <div class="kpi-header">
                <span class="kpi-label">Discounts Granted</span>
                <div class="kpi-icon" style="background:rgba(245,158,11,0.12); color:#F59E0B;"><?php echo admin_icon('wallet', 18); ?></div>
              </div>
              <div class="kpi-value" style="color:#F59E0B;">₱<?php echo number_format((float)($promoStats['totalSavings'] ?? 0), 2); ?></div>
              <div class="kpi-sub" style="color:var(--ink-muted);">Total savings given</div>
            </div>
          </div>

          <!-- Promo Codes Table Card -->
          <div class="admin-card">
            <div class="card-header">
              <div class="card-title"><?php echo admin_icon('tag', 18); ?> Configured Promo Codes</div>
              <span class="card-count"><?php echo count($promos ?? []); ?> total</span>
            </div>
            <div class="table-wrap searchable">
              <table class="admin-table">
                <thead>
                  <tr>
                    <th>Code</th>
                    <th>Discount</th>
                    <th>Conditions</th>
                    <th>Usage</th>
                    <th>Expiry</th>
                    <th>Status</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (empty($promos)): ?>
                    <tr>
                      <td colspan="7" style="text-align:center; padding:30px; color:var(--ink-muted);">
                        No promo codes generated yet. Click "Create New Promo Code" above.
                      </td>
                    </tr>
                  <?php else: ?>
                    <?php foreach ($promos as $p): ?>
                      <?php
                        $pCode = htmlspecialchars((string)($p['code'] ?? ''));
                        $pId = htmlspecialchars((string)($p['id'] ?? ''));
                        $pType = (string)($p['discount_type'] ?? 'fixed');
                        $pVal = (float)($p['discount_value'] ?? 0);
                        $pMin = (float)($p['min_spend'] ?? 0);
                        $pLimit = (int)($p['usage_limit'] ?? 0);
                        $pUsed = (int)($p['times_used'] ?? 0);
                        $pStatus = (string)($p['status'] ?? 'active');
                        $pExp = !empty($p['expires_at']) ? date('M j, Y', strtotime($p['expires_at'])) : 'No Expiration';

                        $isExpired = false;
                        if (!empty($p['expires_at']) && strtotime($p['expires_at']) < time()) {
                            $isExpired = true;
                            $pStatus = 'expired';
                        }
                      ?>
                      <tr>
                        <td>
                          <div style="display:inline-flex; align-items:center; gap:6px; background:rgba(255,255,255,0.06); border:1px solid rgba(255,255,255,0.12); padding:4px 10px; border-radius:8px;">
                            <span class="row-mono" style="font-weight:900; font-size:13px; color:#FFFFFF; letter-spacing:0.8px;"><?php echo $pCode; ?></span>
                            <button type="button" onclick="copyPromoCode('<?php echo $pCode; ?>')" title="Copy Code" style="background:none; border:none; color:var(--ink-muted); cursor:pointer; padding:0; display:flex; align-items:center;">
                              <?php echo admin_icon('copy', 14); ?>
                            </button>
                          </div>
                        </td>
                        <td>
                          <span style="font-weight:900; font-size:13px; color:#00D98B;">
                            <?php if ($pType === 'percentage' || $pType === 'percent'): ?>
                              <?php echo round($pVal); ?>% OFF
                            <?php else: ?>
                              ₱<?php echo number_format($pVal, 2); ?> OFF
                            <?php endif; ?>
                          </span>
                        </td>
                        <td>
                          <div style="font-size:12px; color:var(--ink-primary); font-weight:600;">
                            <?php echo $pMin > 0 ? ('Min Spend: ₱' . number_format($pMin, 2)) : 'No Min Spend'; ?>
                          </div>
                          <div style="font-size:11px; color:var(--ink-muted);">
                            Max <?php echo (int)($p['user_limit'] ?? 1); ?> per user
                          </div>
                        </td>
                        <td>
                          <div style="font-size:12px; font-weight:700; color:var(--ink-primary);">
                            <?php echo $pUsed; ?> / <?php echo $pLimit > 0 ? $pLimit : '∞'; ?> used
                          </div>
                          <?php if ($pLimit > 0): ?>
                            <?php $pct = min(100, round(($pUsed / $pLimit) * 100)); ?>
                            <div style="width:100px; height:4px; background:rgba(255,255,255,0.1); border-radius:2px; margin-top:3px; overflow:hidden;">
                              <div style="width:<?php echo $pct; ?>%; height:100%; background:<?php echo $pct >= 100 ? '#F87171' : '#00D98B'; ?>;"></div>
                            </div>
                          <?php endif; ?>
                        </td>
                        <td>
                          <span style="font-size:11.5px; font-weight:600; color:<?php echo $isExpired ? '#F87171' : 'var(--ink-muted)'; ?>;">
                            <?php echo $pExp; ?>
                          </span>
                        </td>
                        <td>
                          <?php if ($pStatus === 'active'): ?>
                            <span class="status-pill approved" style="font-size:10px;">ACTIVE</span>
                          <?php elseif ($pStatus === 'expired'): ?>
                            <span class="status-pill pending" style="font-size:10px; background:rgba(248,113,113,0.15); color:#F87171; border-color:rgba(248,113,113,0.3);">EXPIRED</span>
                          <?php else: ?>
                            <span class="status-pill pending" style="font-size:10px; background:rgba(148,163,184,0.15); color:#94A3B8; border-color:rgba(148,163,184,0.3);">DISABLED</span>
                          <?php endif; ?>
                        </td>
                        <td>
                          <div class="table-actions">
                            <button type="button" class="mini-btn" onclick="togglePromoStatus('<?php echo $pId; ?>')" title="Toggle Active / Disabled">
                              <?php echo $pStatus === 'active' ? 'Disable' : 'Enable'; ?>
                            </button>
                            <button type="button" class="mini-btn danger" onclick="deletePromo('<?php echo $pId; ?>', '<?php echo $pCode; ?>')" title="Delete Promo Code">
                              Delete
                            </button>
                          </div>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </section>

        <!-- ===================== ANALYTICS BI ===================== -->
        <section class="admin-panel <?php echo $activeTab === 'analytics' ? 'active' : ''; ?>" data-panel="analytics">
          <div class="content-header">

            <h1 class="content-title">Executive Analytics</h1>
            <p class="content-subtitle">Real-time breakdowns computed from live platform data</p>
          </div>

          <div class="analytics-grid">
            <div class="admin-card">
              <div class="card-header"><div class="card-title"><?php echo admin_icon('users', 17); ?> User Role Distribution</div></div>
              <?php $maxRole = max(1, ...array_values($roleBreakdown)); ?>
              <?php foreach ($roleBreakdown as $role => $count): ?>
                <div class="bar-row">
                  <div class="bar-label"><?php echo htmlspecialchars($role); ?></div>
                  <div class="bar-track"><div class="bar-fill" style="width: <?php echo $count > 0 ? round(($count / $maxRole) * 100) : 0; ?>%;"></div></div>
                  <div class="bar-value"><?php echo $count; ?></div>
                </div>
              <?php endforeach; ?>
              <div style="margin-top:14px; padding-top:14px; border-top:1px solid var(--border-subtle); font-size:12px; color:var(--ink-muted);">
                Verification rate: <strong style="color:var(--ink-primary);"><?php echo $verificationRate; ?>%</strong> (<?php echo $verifiedUsers; ?> of <?php echo $totalUsers; ?>)
              </div>
            </div>

            <div class="admin-card">
              <div class="card-header"><div class="card-title"><?php echo admin_icon('calendar', 17); ?> Booking Status Breakdown</div></div>
              <?php if (empty($bookingStatusBreakdown)): ?>
                <div class="empty-state" style="padding: 20px;"><div class="empty-subtext">No bookings yet to analyze.</div></div>
              <?php else: ?>
                <?php $maxBk = max(1, ...array_values($bookingStatusBreakdown)); ?>
                <?php foreach ($bookingStatusBreakdown as $status => $count): ?>
                  <div class="bar-row">
                    <div class="bar-label"><?php echo htmlspecialchars($status); ?></div>
                    <div class="bar-track"><div class="bar-fill" style="width: <?php echo round(($count / $maxBk) * 100); ?>%;"></div></div>
                    <div class="bar-value"><?php echo $count; ?></div>
                  </div>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>

            <div class="admin-card" style="grid-column: 1 / -1;">
              <div class="card-header"><div class="card-title"><?php echo admin_icon('building', 17); ?> Top Rated Facilities</div></div>
              <?php if (empty($topFacilities)): ?>
                <div class="empty-state" style="padding: 20px;"><div class="empty-subtext">No facilities listed yet.</div></div>
              <?php else: ?>
                <?php foreach ($topFacilities as $i => $f): ?>
                  <div class="facility-rank">
                    <div class="rank-num"><?php echo $i + 1; ?></div>
                    <div class="rank-name"><?php echo htmlspecialchars($f['name'] ?? '—'); ?></div>
                    <div style="color:var(--ink-muted); font-size:11.5px; flex-shrink:0;"><?php echo htmlspecialchars($f['location'] ?? ''); ?></div>
                    <div class="rank-rating">★ <?php echo htmlspecialchars((string)($f['rating'] ?? '—')); ?></div>
                  </div>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
          </div>
        </section>

        <!-- ===================== SYSTEM & SECURITY ===================== -->
        <section class="admin-panel <?php echo $activeTab === 'system' ? 'active' : ''; ?>" data-panel="system">
          <div class="content-header">

            <h1 class="content-title">System &amp; Security</h1>
            <p class="content-subtitle">Environment facts and recent platform activity</p>
          </div>

          <div class="system-grid">
            <div class="system-fact"><div class="system-fact-label">Storage Engine</div><div class="system-fact-value"><?php echo admin_icon('database', 16); ?> <?php echo $usingMySQL ? 'MySQL' : 'JSON File Store'; ?></div></div>
            <div class="system-fact"><div class="system-fact-label">PHP Runtime</div><div class="system-fact-value"><?php echo admin_icon('terminal', 16); ?> <?php echo htmlspecialchars(phpversion()); ?></div></div>
            <div class="system-fact"><div class="system-fact-label">CSRF Token</div><div class="system-fact-value" style="color: var(--accent-primary);"><?php echo admin_icon('shield', 16); ?> Active</div></div>
          </div>

          <div class="admin-card">
            <div class="card-header"><div class="card-title"><?php echo admin_icon('scroll', 18); ?> Recent Platform Activity</div></div>
            <?php if (empty($recentBookings) && empty($recentApplications)): ?>
              <div class="empty-state">
                <?php echo admin_icon('scroll', 40); ?>
                <div class="empty-text">No activity yet</div>
                <div class="empty-subtext">Bookings and owner applications will show up here as they happen.</div>
              </div>
            <?php else: ?>
              <?php foreach ($recentApplications as $app): ?>
                <div class="activity-row">
                  <div class="activity-dot" style="background: var(--accent-secondary);"></div>
                  <div>
                    <div class="activity-text">Owner application from <strong><?php echo htmlspecialchars($app['owner_name'] ?? 'Applicant'); ?></strong> for <strong><?php echo htmlspecialchars($app['facility_name'] ?? 'a facility'); ?></strong></div>
                    <div class="activity-time"><?php echo htmlspecialchars($app['created_at'] ?? ''); ?> · status: <?php echo htmlspecialchars($app['status'] ?? 'pending_review'); ?></div>
                  </div>
                </div>
              <?php endforeach; ?>
              <?php foreach ($recentBookings as $bk): ?>
                <div class="activity-row">
                  <div class="activity-dot"></div>
                  <div>
                    <div class="activity-text">Booking <strong>#<?php echo htmlspecialchars((string)($bk['id'] ?? '')); ?></strong> at <strong><?php echo htmlspecialchars($bk['facility_name'] ?? 'a facility'); ?></strong> — ₱<?php echo number_format((float)($bk['price'] ?? 0), 2); ?></div>
                    <div class="activity-time"><?php echo htmlspecialchars($bk['date'] ?? ''); ?> <?php echo htmlspecialchars($bk['time'] ?? ''); ?> · status: <?php echo htmlspecialchars($bk['status'] ?? 'upcoming'); ?></div>
                  </div>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </section>

      </div>
    </main>
  </div>

  <!-- ===== WALLET ADJUST MODAL ===== -->
  <div class="modal-overlay" id="walletModal">
    <div class="modal-box">
      <div class="modal-title">Adjust Wallet</div>
      <div class="modal-sub" id="walletModalSub">Credit or debit this user's Pickle Credits balance.</div>
      <div class="modal-field">
        <label>Type</label>
        <select id="walletType"><option value="credit">Credit (add funds)</option><option value="debit">Debit (remove funds)</option></select>
      </div>
      <div class="modal-field"><label>Amount (₱)</label><input type="number" id="walletAmount" min="1" step="0.01" placeholder="0.00"></div>
      <div class="modal-field"><label>Label / Reason</label><input type="text" id="walletLabel" placeholder="e.g. Refund adjustment"></div>
      <div class="modal-actions">
        <button type="button" class="btn-ghost-pill" onclick="closeModal('walletModal')">Cancel</button>
        <button type="button" class="btn-primary-pill" onclick="submitWalletAdjust()">Apply</button>
      </div>
    </div>
  </div>

  <!-- ===== RESET PASSWORD MODAL ===== -->
  <div class="modal-overlay" id="resetModal">
    <div class="modal-box">
      <div class="modal-title">Reset Password</div>
      <div class="modal-sub" id="resetModalSub">Set a new password for this user.</div>
      <div class="modal-field"><label>New Password</label><input type="password" id="resetPassword" minlength="6" placeholder="At least 6 characters"></div>
      <div class="modal-actions">
        <button type="button" class="btn-ghost-pill" onclick="closeModal('resetModal')">Cancel</button>
        <button type="button" class="btn-primary-pill" onclick="submitResetPassword()">Reset</button>
      </div>
    </div>
  </div>

  <!-- ===== SEND SYSTEM NOTICE MODAL ===== -->
  <div class="modal-overlay" id="notifModal">
    <div class="modal-box">
      <div class="modal-title">Send System Notice</div>
      <div class="modal-sub" id="notifModalSub">Send a direct inbox message to this user.</div>
      <div class="modal-field"><label>Notice Title</label><input type="text" id="notifTitle" placeholder="e.g. Account Update / Important Alert"></div>
      <div class="modal-field"><label>Message Content</label><textarea id="notifMessage" rows="3" style="width:100%; background:var(--surface-base); border:1px solid var(--border-default); border-radius:10px; padding:10px 12px; color:var(--ink-primary); font-size:13px; font-family:var(--font-main);" placeholder="Write notification message..."></textarea></div>
      <div class="modal-actions">
        <button type="button" class="btn-ghost-pill" onclick="closeModal('notifModal')">Cancel</button>
        <button type="button" class="btn-primary-pill" onclick="submitSendNotif()">Send Notice</button>
      </div>
    </div>
  </div>

  <!-- ===== CREATE PROMO CODE MODAL ===== -->
  <div class="modal-overlay" id="createPromoModal">
    <div class="modal-box" style="max-width:460px;">
      <div class="modal-title" style="display:flex; align-items:center; gap:8px;">
        <?php echo admin_icon('tag', 20); ?>
        <span>Create Promo Code</span>
      </div>
      <div class="modal-sub">Create a new discount code for court bookings and player promotions.</div>
      
      <div class="modal-field">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:4px;">
          <label style="margin-bottom:0;">Promo Code</label>
          <button type="button" onclick="autoGeneratePromoCode()" style="background:none; border:none; color:var(--accent-primary); font-size:11px; font-weight:800; cursor:pointer;">⚡ Auto-Generate</button>
        </div>
        <input type="text" id="promoCodeInput" placeholder="e.g. WELCOME100" style="text-transform:uppercase; font-family:monospace; font-weight:800; letter-spacing:1px;">
      </div>

      <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">
        <div class="modal-field">
          <label>Discount Type</label>
          <select id="promoTypeInput" onchange="updatePromoTypeLabel()">
            <option value="fixed">Fixed Amount (₱)</option>
            <option value="percentage">Percentage (%)</option>
          </select>
        </div>
        <div class="modal-field">
          <label id="promoValueLabel">Discount Value (₱)</label>
          <input type="number" id="promoValueInput" min="1" step="0.5" placeholder="e.g. 100">
        </div>
      </div>

      <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">
        <div class="modal-field">
          <label>Min. Booking Spend (₱)</label>
          <input type="number" id="promoMinSpendInput" min="0" step="10" placeholder="0 (No minimum)">
        </div>
        <div class="modal-field">
          <label>Total Usage Limit</label>
          <input type="number" id="promoLimitInput" min="0" placeholder="0 (Unlimited)">
        </div>
      </div>

      <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">
        <div class="modal-field">
          <label>Per-User Limit</label>
          <input type="number" id="promoUserLimitInput" min="1" value="1">
        </div>
        <div class="modal-field">
          <label>Expiry Date (Optional)</label>
          <input type="date" id="promoExpiresInput">
        </div>
      </div>

      <div class="modal-actions">
        <button type="button" class="btn-ghost-pill" onclick="closeModal('createPromoModal')">Cancel</button>
        <button type="button" class="btn-primary-pill" onclick="submitCreatePromo()">Create &amp; Activate</button>
      </div>
    </div>
  </div>

  <div class="toast-container" id="toastContainer"></div>

  <script>
    const csrfToken = <?php echo json_encode($csrfToken); ?>;
    const currentAdminId = <?php echo json_encode((string)($currentUser['id'] ?? '')); ?>;
    let walletTargetId = null, resetTargetId = null, notifTargetId = null;

    // ---------- Toasts ----------
    let toastTimer = null;
    function showToast(msg, type = 'success') {
      if (window.UX) window.UX.announce(msg, type);
      const c = document.getElementById('toastContainer');
      c.innerHTML = '';
      if (toastTimer) clearTimeout(toastTimer);
      const t = document.createElement('div');
      t.className = 'toast-card' + (type === 'error' ? ' error' : '');
      t.textContent = msg;
      c.appendChild(t);
      toastTimer = setTimeout(() => { if (t.parentNode === c) t.remove(); }, 3200);
    }

    // ---------- Mobile Sidebar Drawer ----------
    function toggleAdminSidebar() {
      const sidebar = document.getElementById('adminSidebar');
      const overlay = document.getElementById('sidebarOverlay');
      if (!sidebar) return;
      const isOpen = sidebar.classList.contains('mobile-open');
      if (isOpen) {
        sidebar.classList.remove('mobile-open');
        if (overlay) overlay.classList.remove('active');
      } else {
        sidebar.classList.add('mobile-open');
        if (overlay) overlay.classList.add('active');
      }
    }

    // ---------- Tab switching ----------
    const panelMeta = {
      overview: {}, applications: {}, facilities: {}, bookings: {}, users: {},
      moderation: {}, ledger: {}, promos: {}, analytics: {}, system: {}
    };
    function switchTab(tab, updateHistory = true) {
      if (!panelMeta.hasOwnProperty(tab)) return;
      document.querySelectorAll('.sidebar-nav-item').forEach(el => el.classList.toggle('active', el.dataset.tab === tab));
      document.querySelectorAll('.admin-panel').forEach(el => el.classList.toggle('active', el.dataset.panel === tab));
      const sidebar = document.getElementById('adminSidebar');
      const overlay = document.getElementById('sidebarOverlay');
      if (sidebar) sidebar.classList.remove('mobile-open');
      if (overlay) overlay.classList.remove('active');
      window.scrollTo({ top: 0, behavior: 'instant' in window ? 'instant' : 'auto' });
      closeAllDropdowns();

      try {
        localStorage.setItem('admin_active_tab', tab);
      } catch (e) {}

      if (updateHistory && window.history && window.history.replaceState) {
        const url = new URL(window.location.href);
        url.searchParams.set('tab', tab);
        window.history.replaceState(null, '', url.pathname + url.search + url.hash);
      }
    }

    document.addEventListener('DOMContentLoaded', () => {
      const urlParams = new URLSearchParams(window.location.search);
      const urlTab = urlParams.get('tab') || window.location.hash.replace('#', '');
      let savedTab = null;
      try {
        savedTab = localStorage.getItem('admin_active_tab');
      } catch (e) {}

      const initialTab = urlTab || savedTab || '<?php echo htmlspecialchars($activeTab); ?>';
      if (initialTab && panelMeta.hasOwnProperty(initialTab)) {
        switchTab(initialTab, false);
      }
    });

    // ---------- Dropdowns ----------
    function toggleDropdown(id) {
      const el = document.getElementById(id);
      const isOpen = el.classList.contains('open');
      closeAllDropdowns();
      if (!isOpen) el.classList.add('open');
    }
    function closeAllDropdowns() {
      document.querySelectorAll('.dropdown-panel').forEach(el => el.classList.remove('open'));
    }

    // ---------- User Action Dropdowns ----------
    function toggleUserActionMenu(menuId, event) {
      if (event) event.stopPropagation();
      const targetMenu = document.getElementById(menuId);
      if (!targetMenu) return;
      const isOpen = targetMenu.classList.contains('show');
      closeAllUserActionMenus();
      if (!isOpen) {
        targetMenu.classList.add('show');
        const btn = targetMenu.previousElementSibling;
        if (btn) btn.classList.add('active');
      }
    }
    function closeAllUserActionMenus() {
      document.querySelectorAll('.user-action-dropdown').forEach(el => el.classList.remove('show'));
      document.querySelectorAll('.user-action-menu-btn').forEach(btn => btn.classList.remove('active'));
    }

    document.addEventListener('click', (e) => {
      if (!e.target.closest('.topbar-actions')) closeAllDropdowns();
      if (!e.target.closest('.user-action-menu-wrap')) closeAllUserActionMenus();
    });

    // ---------- Search filter (scoped to the active panel's table) ----------
    document.getElementById('adminSearch').addEventListener('input', function (e) {
      const q = e.target.value.trim().toLowerCase();
      const activePanel = document.querySelector('.admin-panel.active');
      if (!activePanel) return;
      const rows = activePanel.querySelectorAll('.searchable tbody tr');
      rows.forEach(row => {
        row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
      });
    });

    // ---------- Admin API helper ----------
    async function callAdmin(action, params = {}, confirmMsg = null) {
      if (confirmMsg && !confirm(confirmMsg)) return null;
      const fd = new FormData();
      fd.append('action', action);
      fd.append('csrf_token', csrfToken);
      Object.entries(params).forEach(([k, v]) => fd.append(k, v));
      try {
        const res = await fetch('admin.php', { method: 'POST', headers: { 'X-CSRF-TOKEN': csrfToken }, body: fd });
        const data = await res.json();
        if (data.success) {
          showToast(data.message || 'Done.', 'success');
        } else {
          showToast(data.message || 'Something went wrong.', 'error');
        }
        return data;
      } catch (err) {
        showToast('Network error — please try again.', 'error');
        return null;
      }
    }
    function reloadSoon(ms = 700) { setTimeout(() => window.location.reload(), ms); }

    // ---------- Owner applications ----------
    async function approveApplication(userId, appId) {
      const data = await callAdmin('admin_approve_owner_application', { user_id: userId, application_id: appId }, 'Approve this owner application and grant Court Owner access?');
      if (data && data.success) reloadSoon();
    }
    async function rejectApplication(userId, appId) {
      const data = await callAdmin('admin_reject_owner_application', { user_id: userId, application_id: appId }, 'Reject this owner application?');
      if (data && data.success) reloadSoon();
    }

    // ---------- Users ----------
    async function updateRole(userId, role) {
      const data = await callAdmin('admin_update_role', { user_id: userId, role }, `Change this user's role to "${role}"?`);
      if (data && data.success) reloadSoon();
    }
    async function toggleVerify(userId) {
      const data = await callAdmin('admin_toggle_verify', { user_id: userId }, 'Toggle verification status for this user?');
      if (data && data.success) reloadSoon();
    }
    async function deactivateUser(userId, name) {
      if (userId === currentAdminId) { showToast('You cannot deactivate your own account.', 'error'); return; }
      const data = await callAdmin('admin_delete_user', { user_id: userId }, `Deactivate ${name}'s account? This can be reversed by support.`);
      if (data && data.success) reloadSoon();
    }

    // ---------- Bookings ----------
    async function updateBookingStatus(bookingId, status) {
      const data = await callAdmin('admin_update_booking_status', { booking_id: bookingId, status });
      if (data && data.success) reloadSoon();
    }
    async function cancelBooking(bookingId) {
      const data = await callAdmin('admin_cancel_booking', { booking_id: bookingId }, 'Cancel this booking? The player will be refunded if eligible.');
      if (data && data.success) reloadSoon();
    }

    // ---------- Modals ----------
    // Delegates to ux-core.js for Escape, focus trapping, backdrop dismissal,
    // reference-counted scroll lock and the exit animation.
    function openModal(id) {
      if (window.UX) return window.UX.openDialog(id);
      document.getElementById(id).classList.add('open');
    }
    function closeModal(id) {
      if (window.UX) return window.UX.closeDialog(id);
      document.getElementById(id).classList.remove('open');
    }

    function openWalletModal(userId, name) {
      walletTargetId = userId;
      document.getElementById('walletModalSub').textContent = `Credit or debit ${name}'s Pickle Credits balance.`;
      document.getElementById('walletAmount').value = '';
      document.getElementById('walletLabel').value = '';
      openModal('walletModal');
    }
    async function submitWalletAdjust() {
      const type = document.getElementById('walletType').value;
      const amount = parseFloat(document.getElementById('walletAmount').value);
      const label = document.getElementById('walletLabel').value.trim() || 'Admin Adjustment';
      if (!amount || amount <= 0) { showToast('Enter a valid amount.', 'error'); return; }
      const data = await callAdmin('admin_adjust_wallet', { user_id: walletTargetId, type, amount, label });
      if (data && data.success) { closeModal('walletModal'); reloadSoon(); }
    }

    function openResetModal(userId, name) {
      resetTargetId = userId;
      document.getElementById('resetModalSub').textContent = `Set a new password for ${name}.`;
      document.getElementById('resetPassword').value = '';
      openModal('resetModal');
    }
    async function submitResetPassword() {
      const pwd = document.getElementById('resetPassword').value;
      if (pwd.length < 6) { showToast('Password must be at least 6 characters.', 'error'); return; }
      const data = await callAdmin('admin_reset_password', { user_id: resetTargetId, new_password: pwd });
      if (data && data.success) { closeModal('resetModal'); }
    }

    function openNotifModal(userId, name) {
      notifTargetId = userId;
      document.getElementById('notifModalSub').textContent = `Send a direct inbox message to ${name}.`;
      document.getElementById('notifTitle').value = 'Admin Notice 🔔';
      document.getElementById('notifMessage').value = '';
      openModal('notifModal');
    }
    async function submitSendNotif() {
      const title = document.getElementById('notifTitle').value.trim() || 'Admin Notice 🔔';
      const msg = document.getElementById('notifMessage').value.trim();
      if (!msg) { showToast('Please enter message content.', 'error'); return; }
      const data = await callAdmin('admin_send_notification', { user_id: notifTargetId, title, message: msg });
      if (data && data.success) { closeModal('notifModal'); }
    }

    async function impersonateUser(userId, name) {
      if (!confirm(`Switch session and log in as ${name}? You can return to admin at any time.`)) return;
      const data = await callAdmin('admin_impersonate', { user_id: userId });
      if (data && data.success) {
        window.location.href = 'app.php';
      }
    }

    async function reactivateUser(userId, name) {
      const data = await callAdmin('admin_reactivate_user', { user_id: userId }, `Reactivate ${name}'s account?`);
      if (data && data.success) reloadSoon();
    }

    // ---------- Promo Code Engine ----------
    function openCreatePromoModal() {
      document.getElementById('promoCodeInput').value = '';
      document.getElementById('promoTypeInput').value = 'fixed';
      document.getElementById('promoValueInput').value = '';
      document.getElementById('promoMinSpendInput').value = '0';
      document.getElementById('promoLimitInput').value = '0';
      document.getElementById('promoUserLimitInput').value = '1';
      document.getElementById('promoExpiresInput').value = '';
      updatePromoTypeLabel();
      openModal('createPromoModal');
    }

    function updatePromoTypeLabel() {
      const type = document.getElementById('promoTypeInput').value;
      document.getElementById('promoValueLabel').textContent = type === 'percentage' ? 'Discount Value (%)' : 'Discount Value (₱)';
    }

    function autoGeneratePromoCode() {
      const prefixes = ['PICKLE', 'DINK', 'SMASH', 'VIP', 'WELCOME', 'BOUNCE'];
      const prefix = prefixes[Math.floor(Math.random() * prefixes.length)];
      const number = Math.floor(100 + Math.random() * 900);
      document.getElementById('promoCodeInput').value = `${prefix}${number}`;
    }

    async function submitCreatePromo() {
      const code = document.getElementById('promoCodeInput').value.trim();
      const discount_type = document.getElementById('promoTypeInput').value;
      const discount_value = parseFloat(document.getElementById('promoValueInput').value);
      const min_spend = parseFloat(document.getElementById('promoMinSpendInput').value) || 0;
      const usage_limit = parseInt(document.getElementById('promoLimitInput').value) || 0;
      const user_limit = parseInt(document.getElementById('promoUserLimitInput').value) || 1;
      const expires_at = document.getElementById('promoExpiresInput').value;

      if (!discount_value || discount_value <= 0) {
        showToast('Please enter a valid discount value.', 'error');
        return;
      }

      const data = await callAdmin('admin_create_promo', {
        code, discount_type, discount_value, min_spend, usage_limit, user_limit, expires_at
      });

      if (data && data.success) {
        closeModal('createPromoModal');
        reloadSoon();
      }
    }

    async function togglePromoStatus(promoId) {
      const data = await callAdmin('admin_toggle_promo', { promo_id: promoId });
      if (data && data.success) reloadSoon();
    }

    async function deletePromo(promoId, code) {
      const data = await callAdmin('admin_delete_promo', { promo_id: promoId }, `Delete promo code "${code}"?`);
      if (data && data.success) reloadSoon();
    }

    function copyPromoCode(code) {
      navigator.clipboard.writeText(code);
      showToast(`Copied "${code}" to clipboard!`, 'success');
    }

    document.querySelectorAll('.modal-overlay').forEach(overlay => {
      overlay.addEventListener('click', (e) => { if (e.target === overlay) overlay.classList.remove('open'); });
    });
  </script>
</body>
</html>
