<?php
declare(strict_types=1);

namespace Picklers\Web\Controller;

use Picklers\Admin\Security\PlatformSession;
use Picklers\Domain\PasswordPolicy;
use Picklers\Helpers\Format;
use Picklers\Web\Security\RateLimiter;
use Picklers\Services\AuthService;
use Picklers\Services\LoginThrottle;
use Picklers\Web\Http\LegacyInput;
use Picklers\Web\Security\SessionUser;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Sign-in, registration and sign-out. Passwords are checked with Symfony's
 * password hasher against the existing hashes; a successful sign-in starts the
 * platform session (PlatformSession::signIn()). The page posts here with fetch()
 * (JSON answers) or as a plain form (redirect back with a flash message).
 */
final class AuthController extends AbstractWebController
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly LoginThrottle $throttle,
        private readonly PlatformSession $session,
        private readonly PasswordHasherFactoryInterface $hashers,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/auth', name: 'auth', methods: ['GET'])]
    #[Route('/auth.php', name: 'auth_php', methods: ['GET'])]
    public function page(Request $request): Response
    {
        $flashes = $request->getSession()->getFlashBag();
        $error = (string)($flashes->get('auth_error')[0] ?? '');
        $success = (string)($flashes->get('auth_success')[0] ?? '');
        if ($request->query->has('logout')) {
            $this->session->destroy();
            $success = 'You have been safely signed out.';
        }
        $intent = (string)$request->query->get('intent', '');
        $tab = (string)($flashes->get('auth_tab')[0] ?? (in_array($intent, ['signup', 'owner'], true) ? 'signup' : 'signin'));

        return $this->render('web/auth.html.twig', [
            'error' => $error,
            'success' => $success,
            'initialTab' => $tab,
            'csrfToken' => $this->csrfToken(),
        ]);
    }

    #[Route('/logout', name: 'logout', methods: ['GET'])]
    public function logout(Request $request): Response
    {
        $this->session->destroy();

        return $this->redirectToPath($request, 'auth?logout=1');
    }

    #[Route('/auth', name: 'auth_post', methods: ['POST'])]
    #[Route('/auth.php', name: 'auth_post_php', methods: ['POST'])]
    public function post(Request $request): Response
    {
        $in = new LegacyInput($request);
        $action = (string)$in->input('auth_action', 'signin');
        $ajax = $request->headers->get('X-Requested-With') === 'XMLHttpRequest'
            || str_contains((string)$request->headers->get('Accept', ''), 'application/json');
        $tab = $action === 'signup' ? 'signup' : 'signin';
        $fail = function (string $message, int $status) use ($request, $ajax, $tab): Response {
            if ($ajax) {
                return $this->legacyError($message, $status);
            }
            $this->addFlash('auth_error', $message);
            $this->addFlash('auth_tab', $tab);

            return $this->redirectToPath($request, 'auth');
        };

        if (!$this->validCsrf($request)) {
            return $fail('Security verification token invalid or expired. Please refresh and try again.', 403);
        }

        return match ($action) {
            'signin' => $this->signIn($request, $in, $ajax, $fail),
            'signup' => $this->signUp($request, $in, $ajax, $fail),
            default => $fail('Authentication failed', 400),
        };
    }

    /** @param callable(string,int):Response $fail */
    private function signIn(Request $request, LegacyInput $in, bool $ajax, callable $fail): Response
    {
        $identifier = trim((string)$in->input('identifier', ''));
        $password = (string)$in->input('password', '');
        if ($identifier === '' || $password === '') {
            return $fail('Please enter your email/phone and password.', 400);
        }
        $state = $this->throttle->check($identifier);
        if ($state['locked']) {
            return $fail('Too many failed sign-in attempts. Please try again in ' . LoginThrottle::describeWait((int)$state['seconds_remaining']) . '.', 429);
        }

        $row = $this->auth->getUserByEmailOrPhone($identifier);
        if (!$row) {
            $result = $this->throttle->recordFailure($identifier);

            return $fail($result['locked']
                ? 'Too many failed attempts. Please try again in ' . LoginThrottle::describeWait((int)$result['seconds_remaining']) . '.'
                : 'No account found with this email or phone. Please check your account or create a new one.', 400);
        }
        $user = new SessionUser($row);
        $hash = $user->getPassword();
        if ($hash === null || !$this->hashers->getPasswordHasher($user)->verify($hash, $password)) {
            $result = $this->throttle->recordFailure($identifier);
            if ($result['locked']) {
                return $fail('Too many failed sign-in attempts. Please try again in ' . LoginThrottle::describeWait((int)$result['seconds_remaining']) . '.', 400);
            }

            return $fail($result['attempts_left'] <= 2
                ? 'Incorrect password. ' . $result['attempts_left'] . ' attempt(s) remaining before a temporary lockout.'
                : 'Incorrect password for this account. Please try again or click Forgot Password.', 400);
        }
        // A deactivated account keeps its phone number (so it can be reactivated);
        // that must not let it sign in.
        if (($row['role'] ?? '') === 'deleted') {
            return $fail('This account has been deactivated. Please contact Picklers support.', 400);
        }

        $this->throttle->clear($identifier);
        $this->session->signIn((string)$row['id']);
        $redirect = $request->getBaseUrl() . '/app.php';
        if ($ajax) {
            return $this->legacySuccess('Successfully signed in!', ['redirect' => $redirect, 'user' => $user->row()]);
        }

        return $this->redirect($redirect);
    }

    /** @param callable(string,int):Response $fail */
    private function signUp(Request $request, LegacyInput $in, bool $ajax, callable $fail): Response
    {
        if (RateLimiter::tooMany('auth.signup', 10, 3600, (string)($request->getClientIp() ?? 'unknown'))) {
            return $fail('Too many account registrations from your network. Please try again later.', 429);
        }
        $name = trim((string)$in->input('name', ''));
        $email = trim((string)$in->input('email', ''));
        $phone = trim((string)$in->input('phone', ''));
        if ($phone !== '') {
            $phone = Format::phMobile($phone) ?? $phone;
        }
        $password = (string)$in->input('password', '');

        $error = match (true) {
            $name === '' || $email === '' || $password === '' => 'Please fill in all required fields (Name, Email, Password).',
            // users.name is VARCHAR(120) (a hard error under strict mode); angle brackets have no place in a name.
            mb_strlen($name) > 120 || preg_match('/[<>]/', $name) === 1 => 'Please enter a valid name (up to 120 characters, no < or >).',
            strlen($email) > 120 || !filter_var($email, FILTER_VALIDATE_EMAIL) => 'Please enter a valid email address.',
            $phone !== '' && (strlen($phone) > 32 || !preg_match('/^[0-9+()\-\s]{7,32}$/', $phone)) => 'Please enter a valid mobile number.',
            default => PasswordPolicy::error($password),
        };
        if ($error === null && $this->auth->getUserByEmailOrPhone($email)) {
            $error = 'An account with this email address already exists. Please sign in instead.';
        }
        // Phone is a sign-in identifier too: two accounts sharing one number would be ambiguous.
        if ($error === null && $phone !== '' && $this->auth->getUserByEmailOrPhone($phone) !== null) {
            $error = 'This mobile number is already linked to another account.';
        }
        if ($error !== null) {
            return $fail($error, 400);
        }

        // Registration always creates a player; owners go through the reviewed application.
        try {
            $row = $this->auth->createUser([
                'name' => $name,
                'email' => $email,
                'phone' => $phone !== '' ? $phone : null,
                'password_hash' => $this->hashers->getPasswordHasher(SessionUser::class)->hash($password),
                'role' => 'player',
                'is_admin' => 0,
                'is_owner' => 0,
            ]);
        } catch (\Throwable $e) {
            // Two sign-ups racing for one email both pass the lookup; the UNIQUE index rejects the second.
            $this->logger->warning('[PICKLERS Auth] createUser failed: {message}', ['message' => $e->getMessage()]);
            $row = null;
        }
        if (empty($row['id'])) {
            return $fail('We could not create your account. If you already registered, please sign in instead.', 400);
        }

        $this->session->signIn((string)$row['id']);
        $redirect = (string)$in->input('role', 'player') === 'owner' ? 'owner-application' : 'app';
        if ($ajax) {
            return $this->legacySuccess('Account created successfully!', ['redirect' => $redirect, 'user' => (new SessionUser($row))->row()]);
        }

        return $this->redirectToPath($request, $redirect);
    }
}
