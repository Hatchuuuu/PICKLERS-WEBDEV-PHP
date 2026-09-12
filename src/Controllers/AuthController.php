<?php
declare(strict_types=1);

namespace Picklers\Controllers;

use Picklers\Core\Request;
use Picklers\Core\Response;
use Picklers\Middleware\AuthMiddleware;
use Picklers\Services\AuthService;
use Picklers\Services\LoginThrottle;

class AuthController extends BaseController {
    private AuthService $authService;

    public function __construct(?AuthService $authService = null) {
        $this->authService = $authService ?? new AuthService();
    }

    public function index(Request $request) {
        $error = '';
        $success = '';

        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }

        if (!empty($_SESSION['auth_error'])) {
            $error = (string)$_SESSION['auth_error'];
            unset($_SESSION['auth_error']);
        }

        if (!empty($_SESSION['auth_success'])) {
            $success = (string)$_SESSION['auth_success'];
            unset($_SESSION['auth_success']);
        }

        if ($request->query('logout') !== null) {
            AuthMiddleware::logout();
            $success = 'You have been safely signed out.';
        }

        $intent = $request->query('intent', '');
        $initialTab = !empty($_SESSION['auth_tab']) ? $_SESSION['auth_tab'] : (($intent === 'signup' || $intent === 'owner') ? 'signup' : 'signin');
        if (isset($_SESSION['auth_tab'])) {
            unset($_SESSION['auth_tab']);
        }

        return $this->view('pages/auth', [
            'error' => $error,
            'success' => $success,
            'initialTab' => $initialTab,
            'csrfToken' => $this->csrfToken()
        ]);
    }

    public function handlePost(Request $request) {
        $action = (string)$request->input('auth_action', 'signin');
        $isAjax = $request->header('X-Requested-With') === 'XMLHttpRequest' || str_contains((string)$request->header('Accept', ''), 'application/json');
        $error = '';
        $success = '';

        // Validate CSRF token
        if (!$this->validateCsrf($request)) {
            $msg = 'Security verification token invalid or expired. Please refresh and try again.';
            if ($isAjax) {
                return $this->jsonError($msg, 403);
            }
            if (session_status() === PHP_SESSION_NONE) { @session_start(); }
            $_SESSION['auth_error'] = $msg;
            $_SESSION['auth_tab'] = $action === 'signup' ? 'signup' : 'signin';
            return $this->redirect('auth');
        }

        if ($action === 'signin') {
            $identifier = trim((string)$request->input('identifier', ''));
            $password = (string)$request->input('password', '');

            if (empty($identifier) || empty($password)) {
                $error = 'Please enter your email/phone and password.';
            } else {
                $throttle = new LoginThrottle();
                $state    = $throttle->check($identifier);

                if ($state['locked']) {
                    $wait = LoginThrottle::describeWait((int)$state['seconds_remaining']);
                    $msg  = "Too many failed sign-in attempts. Please try again in {$wait}.";
                    if ($isAjax) {
                        return $this->jsonError($msg, 429);
                    }
                    if (session_status() === PHP_SESSION_NONE) { @session_start(); }
                    $_SESSION['auth_error'] = $msg;
                    $_SESSION['auth_tab'] = 'signin';
                    return $this->redirect('auth');
                }

                $user = $this->authService->getUserByEmailOrPhone($identifier);

                if (!$user) {
                    $result = $throttle->recordFailure($identifier);
                    if ($result['locked']) {
                        $wait  = LoginThrottle::describeWait((int)$result['seconds_remaining']);
                        $error = "Too many failed attempts. Please try again in {$wait}.";
                    } else {
                        $error = 'No account found with this email or phone. Please check your account or create a new one.';
                    }
                } else {
                    $passwordHash = $user['password_hash'] ?? '';
                    if (password_verify($password, $passwordHash)) {
                        $throttle->clear($identifier);

                        AuthMiddleware::login($user['id']);
                        // Direct all sign-ins to player account dashboard (app.php)
                        $redirect = \Picklers\Helpers\Url::to('app.php');
                        if ($isAjax) {
                            return $this->jsonSuccess([
                                'redirect' => $redirect,
                                'user' => $this->sanitizeUser($user)
                            ], 'Successfully signed in!');
                        }
                        return $this->redirect($redirect);
                    } else {
                        $result = $throttle->recordFailure($identifier);
                        if ($result['locked']) {
                            $wait  = LoginThrottle::describeWait((int)$result['seconds_remaining']);
                            $error = "Too many failed sign-in attempts. Please try again in {$wait}.";
                        } elseif ($result['attempts_left'] <= 2) {
                            $error = 'Incorrect password. '
                                   . $result['attempts_left'] . ' attempt(s) remaining before a temporary lockout.';
                        } else {
                            $error = 'Incorrect password for this account. Please try again or click Forgot Password.';
                        }
                    }
                }
            }
        } elseif ($action === 'signup') {
            $signupIp = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
            if (\Picklers\Middleware\RateLimitMiddleware::tooMany('auth.signup', 10, 3600, $signupIp)) {
                $msg = 'Too many account registrations from your network. Please try again later.';
                if ($isAjax) {
                    return $this->jsonError($msg, 429);
                }
                if (session_status() === PHP_SESSION_NONE) { @session_start(); }
                $_SESSION['auth_error'] = $msg;
                $_SESSION['auth_tab'] = 'signup';
                return $this->redirect('auth');
            }

            $name = trim((string)$request->input('name', ''));
            $email = trim((string)$request->input('email', ''));
            $phone = trim((string)$request->input('phone', ''));
            $password = (string)$request->input('password', '');
            $intentRole = (string)$request->input('role', 'player');

            if (empty($name) || empty($email) || empty($password)) {
                $error = 'Please fill in all required fields (Name, Email, Password).';
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = 'Please enter a valid email address.';
            } elseif (strlen($password) < 8) {
                $error = 'Password must be at least 8 characters long.';
            } elseif (!preg_match('/[0-9]/', $password)) {
                $error = 'Password must contain at least one number.';
            } else {
                $existing = $this->authService->getUserByEmailOrPhone($email);
                if ($existing) {
                    $error = 'An account with this email address already exists. Please sign in instead.';
                } else {
                    // Security: Strictly enforce 'player' role at registration.
                    // Owners must go through the owner-application pipeline and be approved.
                    $user = $this->authService->createUser([
                        'name' => $name,
                        'email' => $email,
                        'phone' => $phone,
                        'password_hash' => password_hash($password, PASSWORD_BCRYPT),
                        'role' => 'player',
                        'is_admin' => 0,
                        'is_owner' => 0
                    ]);

                    AuthMiddleware::login($user['id']);
                    $redirect = ($intentRole === 'owner') ? 'owner-application' : 'app';
                    if ($isAjax) {
                        return $this->jsonSuccess([
                            'redirect' => $redirect,
                            'user' => $this->sanitizeUser($user)
                        ], 'Account created successfully!');
                    }
                    return $this->redirect($redirect);
                }
            }
        }

        if ($isAjax) {
            return $this->jsonError($error ?: 'Authentication failed', 400);
        }

        if (session_status() === PHP_SESSION_NONE) { @session_start(); }
        $_SESSION['auth_error'] = $error ?: 'Authentication failed';
        $_SESSION['auth_tab'] = $action === 'signup' ? 'signup' : 'signin';
        return $this->redirect('auth');
    }

    public function logout(): Response {
        AuthMiddleware::logout();
        return $this->redirect('auth?logout=1');
    }
}
