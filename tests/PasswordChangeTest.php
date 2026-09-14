<?php
declare(strict_types=1);

namespace Picklers\Tests;

use Picklers\Services\AuthService;
use Picklers\Repositories\UserRepository;

final class PasswordChangeTest extends TestCase {

    public function run(): void {
        $userRepo = new UserRepository();
        $authService = new AuthService($userRepo);

        // 1. Create a test user
        $testEmail = 'pass_test_' . bin2hex(random_bytes(4)) . '@picklers.ph';
        $initialPass = 'Secret123!';
        $created = $authService->createUser([
            'name' => 'Password Test User',
            'email' => $testEmail,
            'password_hash' => password_hash($initialPass, PASSWORD_BCRYPT),
            'role' => 'player'
        ]);

        $userId = (string)($created['id'] ?? '');
        $this->assertTrue($userId !== '', 'Test user created successfully');

        $newPass = 'BrandNewSecret456!';

        // 2. REGRESSION: the current password is required. An empty field used
        // to skip verification, so anyone holding a live session could lock
        // the account owner out.
        $noCurrent = $authService->changePassword($userId, '', $newPass);
        $this->assertFalse($noCurrent['success'], 'Rejects a change with no current password');

        $wrongCurrent = $authService->changePassword($userId, 'not-my-password1', $newPass);
        $this->assertFalse($wrongCurrent['success'], 'Rejects a change with the wrong current password');

        // 3. The same password policy as sign-up (8+ characters, one number).
        $failShort = $authService->changePassword($userId, $initialPass, 'abc1234');
        $this->assertFalse($failShort['success'], 'Rejects a new password shorter than 8 characters');

        $failNoDigit = $authService->changePassword($userId, $initialPass, 'NoDigitsInHere');
        $this->assertFalse($failNoDigit['success'], 'Rejects a new password without a number');

        $this->assertNull(AuthService::passwordPolicyError($newPass), 'A compliant password passes the shared policy');

        // 4. Successful change with the correct current password
        $success = $authService->changePassword($userId, $initialPass, $newPass);
        $this->assertTrue($success['success'], 'Successfully updates password with the correct current password');

        // 5. Verify authentication works with new password and fails with old
        $oldAuth = $authService->authenticate($testEmail, $initialPass);
        $this->assertNull($oldAuth, 'Authentication fails with old password after update');

        $newAuth = $authService->authenticate($testEmail, $newPass);
        $this->assertNotNull($newAuth, 'Authentication succeeds with new password after update');
        $this->assertSame($userId, (string)($newAuth['id'] ?? ''), 'Authenticated user matches updated user');

        // 6. REGRESSION: a deactivated account cannot authenticate, even with
        // the right password.
        $authService->updateUser($userId, ['role' => 'deleted']);
        $this->assertNull($authService->authenticate($testEmail, $newPass), 'A deactivated account cannot authenticate');
    }
}
