<?php
declare(strict_types=1);

namespace Picklers\Tests;

use Picklers\Services\LoginThrottle;

/**
 * Guards brute-force protection on sign-in.
 *
 * Regression origin: the sign-in handler cleared login_attempts on success, but
 * nothing ever incremented or checked them — only the reset half of the feature
 * had been written, so password guessing was unlimited.
 */
final class LoginThrottleTest extends TestCase {

    public function run(): void {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.99';
        $store = sys_get_temp_dir() . '/picklers_throttle_' . bin2hex(random_bytes(5)) . '.json';
        $t     = new LoginThrottle($store);
        $id    = 'victim@example.com';

        try {
            $this->assertFalse($t->check($id)['locked'], 'A fresh identifier starts unlocked');
            $this->assertSame(LoginThrottle::MAX_ATTEMPTS, $t->check($id)['attempts_left'],
                'A fresh identifier has the full attempt budget');

            for ($i = 1; $i < LoginThrottle::MAX_ATTEMPTS; $i++) {
                $t->recordFailure($id);
            }
            $this->assertFalse($t->check($id)['locked'],
                'Still unlocked one attempt short of the ceiling');
            $this->assertSame(1, $t->check($id)['attempts_left'],
                'One attempt remains before lockout');

            $final = $t->recordFailure($id);
            $this->assertTrue($final['locked'], 'REGRESSION: lockout engages at the attempt ceiling');
            $this->assertTrue($t->check($id)['locked'], 'A subsequent check still reports locked');
            $this->assertGreaterThan(0, $t->check($id)['seconds_remaining'],
                'A locked identifier reports a positive wait');
            $this->assertLessThanOrEqual(LoginThrottle::LOCKOUT_SECONDS,
                $t->check($id)['seconds_remaining'],
                'The wait never exceeds the configured lockout window');

            // Isolation: locking one identifier must not lock another.
            $other = 'someone-else@example.com';
            $this->assertFalse($t->check($other)['locked'],
                'A different identifier from the same IP is tracked separately');

            $t->clear($id);
            $this->assertFalse($t->check($id)['locked'],
                'A successful sign-in clears the lockout');
            $this->assertSame(LoginThrottle::MAX_ATTEMPTS, $t->check($id)['attempts_left'],
                'Clearing restores the full attempt budget');

            // The counter must survive a new instance (it is disk-backed, so
            // clearing cookies or rotating the session does not reset it).
            for ($i = 0; $i < LoginThrottle::MAX_ATTEMPTS; $i++) {
                $t->recordFailure($id);
            }
            $fresh = new LoginThrottle($store);
            $this->assertTrue($fresh->check($id)['locked'],
                'Lockout persists across instances — it is not session-scoped');

            $this->assertSame('about a minute', LoginThrottle::describeWait(45),
                'Short waits render in human terms');
            $this->assertSame('15 minutes', LoginThrottle::describeWait(900),
                'Long waits render in whole minutes');
        } finally {
            @unlink($store);
        }
    }
}
