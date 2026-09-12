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

        // 2. Reject new password shorter than 6 chars
        $failShort = $authService->changePassword($userId, '', '12345');
        $this->assertFalse($failShort['success'], 'Rejects new password shorter than 6 chars');

        // 3. Successfully change password without requiring current password field
        $newPass = 'BrandNewSecret456!';
        $success = $authService->changePassword($userId, '', $newPass);
        $this->assertTrue($success['success'], 'Successfully updates password');

        // 4. Verify authentication works with new password and fails with old
        $oldAuth = $authService->authenticate($testEmail, $initialPass);
        $this->assertNull($oldAuth, 'Authentication fails with old password after update');

        $newAuth = $authService->authenticate($testEmail, $newPass);
        $this->assertNotNull($newAuth, 'Authentication succeeds with new password after update');
        $this->assertSame($userId, (string)($newAuth['id'] ?? ''), 'Authenticated user matches updated user');
    }
}
