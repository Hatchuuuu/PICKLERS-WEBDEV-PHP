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
        $previousIp = $_SERVER['REMOTE_ADDR'] ?? null;
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

            $this->phoneAliases($t, $store);

            $this->assertSame('about a minute', LoginThrottle::describeWait(45),
                'Short waits render in human terms');
            $this->assertSame('15 minutes', LoginThrottle::describeWait(900),
                'Long waits render in whole minutes');
        } finally {
            @unlink($store);
            if ($previousIp === null) {
                unset($_SERVER['REMOTE_ADDR']);
            } else {
                $_SERVER['REMOTE_ADDR'] = $previousIp;
            }
        }
    }
    private function phoneAliases(LoginThrottle $t, string $store): void {
        $aliases = ['09171234567', '0917-123-4567', '+63 917 123 4567', '(0917) 123 4567'];
        $t->clearAll();
        for ($i = 0; $i < LoginThrottle::MAX_ATTEMPTS; $i++) {
            $result = $t->recordFailure($aliases[$i % count($aliases)]);
            $this->assertSame($i === LoginThrottle::MAX_ATTEMPTS - 1, $result['locked'],
                'Equivalent phone spellings share the same-IP failure ceiling');
        }
        foreach ($aliases as $alias) {
            $this->assertTrue($t->check($alias)['locked'], 'Every phone alias observes the same-IP lockout');
        }
        $this->assertFalse($t->check('09181234567')['locked'], 'Unrelated phone remains independent');
        $this->assertFalse($t->check('phone@example.com')['locked'], 'Email remains independent of phone');
        $t->clear($aliases[3]);
        foreach ($aliases as $alias) {
            $this->assertSame(5, $t->check($alias)['attempts_left'], 'Success through any alias clears the pair');
        }

        for ($i = 0; $i < LoginThrottle::MAX_ATTEMPTS_ACCOUNT; $i++) {
            $_SERVER['REMOTE_ADDR'] = '203.0.113.' . ($i + 1);
            $result = $t->recordFailure($aliases[$i % count($aliases)]);
            $this->assertSame($i === LoginThrottle::MAX_ATTEMPTS_ACCOUNT - 1, $result['locked'],
                'Equivalent phone spellings share the account-wide ceiling across IPs');
        }
        $_SERVER['REMOTE_ADDR'] = '203.0.113.200';
        foreach ($aliases as $alias) {
            $this->assertTrue($t->check($alias)['locked'], 'Every alias observes account lockout on a fresh IP');
        }
        $this->assertFalse($t->check('09181234567')['locked'], 'Account lockout leaves another phone independent');
        $t->clear($aliases[1]);
        foreach ($aliases as $alias) {
            $this->assertFalse($t->check($alias)['locked'], 'Success clears the normalized account backstop');
        }

        $t->clearAll();
        $_SERVER['REMOTE_ADDR'] = '203.0.113.99';
        $entry = ['attempts' => 5, 'last_attempt' => time() - 901, 'locked_until' => time() - 1];
        file_put_contents($store, json_encode([
            hash('sha256', '+63 917 123 4567|203.0.113.99') => $entry,
            'acct_' . hash('sha256', '+63 917 123 4567') => array_replace($entry, ['attempts' => 15]),
        ]));
        foreach ($aliases as $alias) {
            $this->assertFalse($t->check($alias)['locked'], 'Expired phone lockouts release every alias');
            $this->assertSame(5, $t->check($alias)['attempts_left'], 'Idle phone counters decay');
        }
        $this->assertFalse($t->recordFailure($aliases[0])['locked'], 'First failure after expiry starts a new window');
        $this->assertSame(4, $t->check($aliases[2])['attempts_left'], 'New window is shared across aliases');

        $entry = ['attempts' => 5, 'last_attempt' => time(), 'locked_until' => time() + 300];
        file_put_contents($store, json_encode([
            hash('sha256', '+63 917 123 4567|203.0.113.99') => $entry,
            'acct_' . hash('sha256', '+63 917 123 4567') => array_replace($entry, ['attempts' => 15]),
        ]));
        foreach ($aliases as $alias) {
            $this->assertTrue($t->check($alias)['locked'], 'Existing canonical legacy pair lock survives normalization');
        }
        $_SERVER['REMOTE_ADDR'] = '203.0.113.201';
        foreach ($aliases as $alias) {
            $this->assertTrue($t->check($alias)['locked'], 'Existing canonical legacy account lock survives on a new IP');
        }
        $t->clearAll();
        $t->recordFailure('  PERSON@EXAMPLE.COM ');
        $this->assertSame(4, $t->check('person@example.com')['attempts_left'], 'Email case and whitespace handling is preserved');
        $t->clear('person@example.com');
        $this->assertSame(5, $t->check('PERSON@EXAMPLE.COM')['attempts_left'], 'Email clear behavior is preserved');
    }
}
