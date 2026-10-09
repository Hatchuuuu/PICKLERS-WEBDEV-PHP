<?php
declare(strict_types=1);

// ==============================================================================
// PICKLERS — Front Controller (Symfony)
// ==============================================================================

use Picklers\Kernel;
use Picklers\Web\Http\InstallBase;
use Symfony\Component\HttpFoundation\Request;

// Path anchors, .env, timezone (Asia/Manila) and Composer autoloading — shared
// with bin/console and the test suites so they can never drift apart.
if (!defined('PUBLIC_PATH')) define('PUBLIC_PATH', __DIR__);
require dirname(__DIR__) . '/config/bootstrap.php';

$kernel = new Kernel(picklers_kernel_environment(), picklers_kernel_debug());
$request = new Request($_GET, $_POST, [], $_COOKIE, $_FILES, InstallBase::server($_SERVER));
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
