<?php
declare(strict_types=1);

use Picklers\Admin\Http\AdminAreaMatcher;
use Picklers\Admin\Security\AdminAccessDeniedHandler;
use Picklers\Admin\Security\AdminUserProvider;
use Picklers\Admin\Security\AdminSessionAuthenticator;
use Picklers\Web\Security\SessionAuthenticator;
use Picklers\Web\Security\SessionUser;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $container->extension('security', [
        // Passwords are verified at sign-in (Web\Controller\AuthController) against the
        // existing hashes; 'auto' reads bcrypt/argon2 and hashes new passwords.
        'password_hashers' => [
            SessionUser::class => 'auto',
        ],
        'providers' => [
            'picklers_users' => ['id' => AdminUserProvider::class],
        ],
        'role_hierarchy' => [
            'ROLE_PRIVILEGED_ADMIN' => ['ROLE_ADMIN'],
            'ROLE_ADMIN' => ['ROLE_USER'],
            'ROLE_OWNER' => ['ROLE_USER'],
            'ROLE_PLAYER' => ['ROLE_USER'],
        ],
        'firewalls' => [
            // /admin* and the /api admin aliases (see Picklers\Admin\Http\AdminArea).
            'admin' => [
                'request_matcher' => AdminAreaMatcher::class,
                'stateless' => true,
                'provider' => 'picklers_users',
                'custom_authenticators' => [AdminSessionAuthenticator::class],
                'entry_point' => AdminSessionAuthenticator::class,
                'access_denied_handler' => AdminAccessDeniedHandler::class,
            ],
            // Migrated player/public sections. Anonymous visitors pass through;
            // a signed-in session becomes a SessionUser. Controllers decide what
            // needs an account, with the legacy JSON messages.
            'web' => [
                'pattern' => '^/',
                'stateless' => true,
                // Unused (the authenticator loads the user itself); required by the bundle.
                'provider' => 'picklers_users',
                'custom_authenticators' => [SessionAuthenticator::class],
            ],
        ],
        'access_control' => [
            ['request_matcher' => AdminAreaMatcher::class, 'roles' => 'ROLE_ADMIN'],
        ],
    ]);
};
