<?php
declare(strict_types=1);

/**
 * PICKLERS — Unified Application Route Registry
 * Supports clean modern URLs and backward-compatible .php endpoints
 *
 * @var \Picklers\Core\Router $router
 */

use Picklers\Controllers\HomeController;
use Picklers\Controllers\AuthController;
use Picklers\Controllers\AppController;
use Picklers\Controllers\ApiController;
use Picklers\Controllers\OwnerController;
use Picklers\Controllers\AdminController;

// ------------------------------------------------------------------------------
// Landing Page
// ------------------------------------------------------------------------------
$router->get('/', [HomeController::class, 'index']);
$router->get('/index.php', [HomeController::class, 'index']);

// ------------------------------------------------------------------------------
// Authentication & Session
// ------------------------------------------------------------------------------
$router->get('/auth', [AuthController::class, 'index']);
$router->get('/auth.php', [AuthController::class, 'index']);
$router->post('/auth', [AuthController::class, 'handlePost']);
$router->post('/auth.php', [AuthController::class, 'handlePost']);
$router->get('/logout', [AuthController::class, 'logout']);

// ------------------------------------------------------------------------------
// Player App Shell (SPA Tabs: play, explore, community, bookings, settings)
// ------------------------------------------------------------------------------
$router->get('/app', [AppController::class, 'index']);
$router->get('/app.php', [AppController::class, 'index']);
$router->post('/app', [AppController::class, 'index']);
$router->post('/app.php', [AppController::class, 'index']);

// ------------------------------------------------------------------------------
// Court Owner Portal & Onboarding Pipeline
// ------------------------------------------------------------------------------
$router->get('/owner', [OwnerController::class, 'index']);
$router->get('/owner.php', [OwnerController::class, 'index']);
$router->get('/app/owner', [OwnerController::class, 'index']);
$router->post('/owner', [OwnerController::class, 'handlePost']);
$router->post('/owner.php', [OwnerController::class, 'handlePost']);
$router->post('/app/owner', [OwnerController::class, 'handlePost']);
$router->get('/owner/tournaments/{id}', [OwnerController::class, 'tournamentDetail']);
$router->get('/app/owner/tournaments/{id}', [OwnerController::class, 'tournamentDetail']);
$router->get('/owner-application', [OwnerController::class, 'application']);
$router->get('/owner-application.php', [OwnerController::class, 'application']);
$router->get('/app/owner-application', [OwnerController::class, 'application']);
$router->post('/owner-application', [OwnerController::class, 'submitApplication']);
$router->post('/owner-application.php', [OwnerController::class, 'submitApplication']);
$router->post('/app/owner-application', [OwnerController::class, 'submitApplication']);

// ------------------------------------------------------------------------------
// Platform Admin Console
// ------------------------------------------------------------------------------
$router->get('/admin', [AdminController::class, 'index']);
$router->get('/admin.php', [AdminController::class, 'index']);
$router->post('/admin', [AdminController::class, 'handle']);
$router->post('/admin.php', [AdminController::class, 'handle']);
$router->post('/admin/api', [AdminController::class, 'handle']);

// ------------------------------------------------------------------------------
// Central REST / AJAX API
// ------------------------------------------------------------------------------
$router->any('/api', [ApiController::class, 'handle']);
$router->any('/api.php', [ApiController::class, 'handle']);
