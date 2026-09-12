<?php
declare(strict_types=1);

namespace Picklers\Services;

/**
 * LoginThrottle — brute-force protection for the sign-in endpoint.
 *
 * Attempts are keyed by (client IP + identifier) and persisted to disk, so
 * clearing cookies or rotating sessions does not reset the counter. Storage
 * lives in the database directory, which is already denied over HTTP.
 */
final class LoginThrottle {

    /** Failed attempts allowed before the IP+account pair is locked out. */
    public const MAX_ATTEMPTS = 5;

    /** Account-wide ceiling — limits IP-rotation attacks. */
    public const MAX_ATTEMPTS_ACCOUNT = 15;

    /** Lockout duration, in seconds, once a ceiling is hit. */
    public const LOCKOUT_SECONDS = 900; // 15 minutes

    /** Window after which an idle failure counter decays back to zero. */
    public const DECAY_SECONDS = 900;

    private string $storePath;

    public function __construct(?string $storePath = null) {
        $dir = defined('DATA_PATH') ? DATA_PATH : (dirname(__DIR__, 2) . '/database');
        $this->storePath = $storePath ?? ($dir . '/login_attempts.json');
    }

    /**
     * @return array{locked:bool,seconds_remaining:int,attempts_left:int}
     */
    public function check(string $identifier): array {
        $store = $this->read();
        $now   = time();

        // Check the per-IP+account pair first (tight limit).
        $key   = $this->key($identifier);
        $entry = $store[$key] ?? null;

        if ($entry) {
            $lockedUntil = (int)($entry['locked_until'] ?? 0);
            if ($lockedUntil > $now) {
                return [
                    'locked'            => true,
                    'seconds_remaining' => $lockedUntil - $now,
                    'attempts_left'     => 0,
                ];
            }
        }

        // Also check the account-only backstop (blocks IP-rotation).
        $acctKey   = $this->accountKey($identifier);
        $acctEntry = $store[$acctKey] ?? null;

        if ($acctEntry) {
            $acctLockedUntil = (int)($acctEntry['locked_until'] ?? 0);
            if ($acctLockedUntil > $now) {
                return [
                    'locked'            => true,
                    'seconds_remaining' => $acctLockedUntil - $now,
                    'attempts_left'     => 0,
                ];
            }
        }

        if (!$entry) {
            return ['locked' => false, 'seconds_remaining' => 0, 'attempts_left' => self::MAX_ATTEMPTS];
        }

        // Counter decays once the window has passed with no new failures.
        $lastAttempt = (int)($entry['last_attempt'] ?? 0);
        if (($now - $lastAttempt) > self::DECAY_SECONDS) {
            return ['locked' => false, 'seconds_remaining' => 0, 'attempts_left' => self::MAX_ATTEMPTS];
        }

        $attempts = (int)($entry['attempts'] ?? 0);
        return [
            'locked'            => false,
            'seconds_remaining' => 0,
            'attempts_left'     => max(0, self::MAX_ATTEMPTS - $attempts),
        ];
    }

    /**
     * Record a failed sign-in and lock the pair out once the ceiling is hit.
     *
     * @return array{locked:bool,seconds_remaining:int,attempts_left:int}
     */
    public function recordFailure(string $identifier): array {
        $key     = $this->key($identifier);
        $acctKey = $this->accountKey($identifier);
        $now     = time();

        $store = $this->mutate(function (array $store) use ($key, $acctKey, $now): array {
            // Per-IP+account counter (tight, 5 attempts).
            $entry       = $store[$key] ?? ['attempts' => 0, 'last_attempt' => 0, 'locked_until' => 0];
            $lastAttempt = (int)($entry['last_attempt'] ?? 0);
            $attempts    = (($now - $lastAttempt) > self::DECAY_SECONDS) ? 1 : ((int)$entry['attempts'] + 1);
            $entry['attempts']     = $attempts;
            $entry['last_attempt'] = $now;
            $entry['locked_until'] = $attempts >= self::MAX_ATTEMPTS ? ($now + self::LOCKOUT_SECONDS) : 0;
            $store[$key] = $entry;

            // Account-only backstop counter (wider, 15 attempts across all IPs).
            $acctEntry       = $store[$acctKey] ?? ['attempts' => 0, 'last_attempt' => 0, 'locked_until' => 0];
            $acctLastAttempt = (int)($acctEntry['last_attempt'] ?? 0);
            $acctAttempts    = (($now - $acctLastAttempt) > self::DECAY_SECONDS) ? 1 : ((int)$acctEntry['attempts'] + 1);
            $acctEntry['attempts']     = $acctAttempts;
            $acctEntry['last_attempt'] = $now;
            $acctEntry['locked_until'] = $acctAttempts >= self::MAX_ATTEMPTS_ACCOUNT ? ($now + self::LOCKOUT_SECONDS) : 0;
            $store[$acctKey] = $acctEntry;

            return $store;
        });

        $entry       = $store[$key] ?? [];
        $attempts    = (int)($entry['attempts'] ?? 0);
        $lockedUntil = max((int)($entry['locked_until'] ?? 0), (int)(($store[$acctKey] ?? [])['locked_until'] ?? 0));

        return [
            'locked'            => $lockedUntil > $now,
            'seconds_remaining' => max(0, $lockedUntil - $now),
            'attempts_left'     => max(0, self::MAX_ATTEMPTS - $attempts),
        ];
    }

    /** Clear both the IP+account and account-only counters after a successful sign-in. */
    public function clear(string $identifier): void {
        $key     = $this->key($identifier);
        $acctKey = $this->accountKey($identifier);
        $this->mutate(function (array $store) use ($key, $acctKey): array {
            unset($store[$key], $store[$acctKey]);
            return $store;
        });
    }

    /** Clear all failure counters across all identifiers. */
    public function clearAll(): void {
        $this->mutate(function (array $store): array {
            return [];
        });
    }

    /** Human-readable wait time for user-facing messages. */
    public static function describeWait(int $seconds): string {
        if ($seconds <= 60) {
            return 'about a minute';
        }
        $minutes = (int)ceil($seconds / 60);
        return $minutes . ' minute' . ($minutes === 1 ? '' : 's');
    }

    private function key(string $identifier): string {
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        return hash('sha256', strtolower(trim($identifier)) . '|' . $ip);
    }

    private function accountKey(string $identifier): string {
        return 'acct_' . hash('sha256', strtolower(trim($identifier)));
    }

    private function read(): array {
        if (!file_exists($this->storePath)) {
            return [];
        }
        $fp = @fopen($this->storePath, 'r');
        if (!$fp) {
            return [];
        }
        try {
            flock($fp, LOCK_SH);
            $raw = stream_get_contents($fp);
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
        $data = json_decode((string)$raw, true);
        return is_array($data) ? $data : [];
    }

    /**
     * Apply a mutation to the store under an exclusive lock.
     */
    private function mutate(callable $mutator): array {
        $dir = dirname($this->storePath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }

        $fp = @fopen($this->storePath, 'c+');
        if (!$fp) {
            return [];
        }

        try {
            if (!flock($fp, LOCK_EX)) {
                return [];
            }
            $raw   = stream_get_contents($fp);
            $store = json_decode((string)$raw, true);
            $store = is_array($store) ? $store : [];

            $store = $mutator($store);
            $store = $this->prune($store);

            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode($store, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            fflush($fp);

            return $store;
        } finally {
            if (is_resource($fp)) {
                flock($fp, LOCK_UN);
                fclose($fp);
            }
        }
    }

    /** Drop entries that are both unlocked and past their decay window. */
    private function prune(array $store): array {
        $now = time();
        foreach ($store as $key => $entry) {
            $lockedUntil = (int)($entry['locked_until'] ?? 0);
            $lastAttempt = (int)($entry['last_attempt'] ?? 0);
            if ($lockedUntil <= $now && ($now - $lastAttempt) > self::DECAY_SECONDS) {
                unset($store[$key]);
            }
        }
        return $store;
    }
}
