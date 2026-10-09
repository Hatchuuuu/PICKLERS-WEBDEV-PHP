<?php
declare(strict_types=1);

namespace Picklers\Tests\Admin;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Base for admin functional tests: an in-process Symfony client driven through
 * the same legacy session the real app uses, plus fixture reset and helpers.
 *
 * Fixture accounts (scripts/seed-fixtures.php): usr_fx_admin (admin),
 * usr_fx_root (privileged admin), usr_fx_owner1/2 (owners), usr_fx_p1..p5
 * (players), usr_fx_gone (deactivated).
 */
abstract class AdminWebTestCase extends WebTestCase
{
    protected KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        self::reseed();
        // Sign-in throttle and API rate-limit counters persist on disk; start each test from zero.
        @unlink(DATA_PATH . '/login_attempts.json');
        array_map('unlink', glob(DATA_PATH . '/rate_limits/*.json') ?: []);
        $_SESSION = [];
        $this->client = static::createClient();
        $this->client->disableReboot();
        // Shutting down the previous test's kernel resets Symfony's session listener,
        // which aborts the PHP session; reopen the run's one session.
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        parent::tearDown();
    }

    /** Restore the synthetic fixtures (idempotent; audit rows are append-only and kept). */
    protected static function reseed(): void
    {
        static $script = null;
        $script ??= escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__, 2) . '/scripts/seed-fixtures.php') . ' picklers_admin_test';
        exec($script . ' 2>&1', $out, $code);
        if ($code !== 0) {
            throw new \RuntimeException('Fixture reseed failed: ' . implode("\n", $out));
        }
        $db = self::pdo();
        // Rows created by earlier tests that the fixture script does not own.
        $db->exec("DELETE FROM courts WHERE facility_id IN (SELECT facility_id FROM owner_applications WHERE id LIKE 'fx_%' AND facility_id IS NOT NULL)");
        $db->exec("DELETE FROM facilities WHERE owner_id IN ('usr_fx_p4','usr_fx_p5','usr_fx_p2')");
        $db->exec("DELETE FROM moderation_case_events WHERE case_id IN (SELECT id FROM moderation_cases WHERE content_id LIKE 'fx_%')");
        $db->exec("DELETE FROM moderation_cases WHERE content_id LIKE 'fx_%'");
        $db->exec("DELETE FROM notification_broadcasts");
        $db->exec("DELETE FROM notifications WHERE user_id LIKE 'usr_fx_%'");
        $db->exec("DELETE FROM wallet_transactions WHERE user_id LIKE 'usr_fx_%' AND id NOT LIKE 'fx_%'");
        $db->exec("DELETE FROM promo_codes WHERE id NOT LIKE 'fx_%' AND created_by LIKE 'usr_fx_%'");
        $db->exec("DELETE FROM promo_redemptions WHERE user_id LIKE 'usr_fx_%' AND id NOT LIKE 'fx_%'");
        $db->exec("DELETE FROM bookings WHERE user_id LIKE 'usr_fx_%' AND id NOT LIKE 'FX-%'");
        $db->exec("DELETE FROM admin_activity_seen WHERE user_id LIKE 'usr_fx_%'");
        $db->exec("DELETE FROM admin_privileges WHERE user_id LIKE 'usr_fx_%' AND user_id <> 'usr_fx_root'");
        $db->exec("INSERT IGNORE INTO admin_privileges (user_id, level, granted_by, granted_at, note) VALUES ('usr_fx_root', 'privileged', NULL, NOW(), 'fixture')");
        \Picklers\Core\Database::get()->invalidateReadCache();
    }

    protected static function pdo(): \PDO
    {
        return \Picklers\Core\Database::get()->pdo();
    }

    protected function db(): Connection
    {
        return static::getContainer()->get(Connection::class);
    }

    protected function signIn(string $userId, ?int $authAt = null): void
    {
        $_SESSION['_sf2_attributes']['picklers_user_id'] = $userId;
        $_SESSION['_sf2_attributes']['picklers_last_activity'] = time();
        $_SESSION['_sf2_attributes']['picklers_auth_at'] = $authAt ?? time();
    }

    /** Who the player/owner side sees as signed in (null when the session was refused). */
    protected function webUser(): ?array
    {
        $this->client->request('GET', '/api/me');

        return json_decode((string)$this->client->getResponse()->getContent(), true)['user'] ?? null;
    }

    /** The session's `admin` CSRF token, stored where Symfony's SessionTokenStorage keeps it. */
    protected function csrf(): string
    {
        return $_SESSION['_sf2_attributes']['_csrf/admin'] ??= bin2hex(random_bytes(16));
    }

    /** POST an admin action through the real endpoint. @return array{status:int,json:array<string,mixed>} */
    protected function action(string $action, array $params = [], ?string $csrf = null, string $uri = '/admin/api', string $method = 'POST'): array
    {
        $payload = ['action' => $action] + $params;
        if ($method === 'POST') {
            $payload['csrf_token'] = $csrf ?? $this->csrf();
        }
        $this->client->request($method, $uri, $method === 'GET' ? $payload : $payload, [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest', 'HTTP_ACCEPT' => 'application/json']);
        $response = $this->client->getResponse();
        $json = json_decode((string)$response->getContent(), true);

        return ['status' => $response->getStatusCode(), 'json' => is_array($json) ? $json : [], 'response' => $response];
    }

    protected function lastAudit(string $action): ?array
    {
        $row = self::pdo()->query('SELECT * FROM audit_events WHERE action = ' . self::pdo()->quote($action) . ' ORDER BY id DESC LIMIT 1')->fetch();

        return $row ?: null;
    }

    protected function scalar(string $sql, array $params = []): mixed
    {
        $s = self::pdo()->prepare($sql);
        $s->execute($params);

        return $s->fetchColumn();
    }

    /**
     * Run the same operation in N separate PHP processes started together and
     * return each worker's JSON result (real concurrency against MySQL).
     *
     * @return list<array<string,mixed>>
     */
    protected function concurrently(int $workers, string $operation, array $args): array
    {
        $root = dirname(__DIR__, 2);
        $go = time() + 2; // shared start instant
        $procs = [];
        for ($i = 0; $i < $workers; $i++) {
            $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/tests/Admin/Concurrency/worker.php') . ' '
                . escapeshellarg(base64_encode((string)json_encode(['op' => $operation, 'args' => $args, 'worker' => $i, 'go' => $go])));
            $procs[] = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i], $root, ['PICKLERS_SKIP_DB_REBUILD' => '1'] + getenv());
        }
        $results = [];
        foreach ($procs as $i => $p) {
            $out = stream_get_contents($pipes[$i][1]);
            $err = stream_get_contents($pipes[$i][2]);
            proc_close($p);
            $decoded = json_decode(trim((string)substr((string)$out, (int)strrpos((string)$out, "\n{") ?: 0)), true);
            $results[] = is_array($decoded) ? $decoded : ['error' => trim($out . ' ' . $err)];
        }

        return $results;
    }
}
