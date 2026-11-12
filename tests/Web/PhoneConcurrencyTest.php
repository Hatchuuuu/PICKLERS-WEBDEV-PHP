<?php
declare(strict_types=1);

namespace Picklers\Tests\Web;

use PHPUnit\Framework\Attributes\DataProvider;
use Picklers\Repositories\UserRepository;
use Picklers\Tests\Admin\AdminWebTestCase;

final class PhoneConcurrencyTest extends AdminWebTestCase
{
    public static function races(): iterable
    {
        yield 'registration/registration' => ['signup', 'signup'];
        yield 'profile/profile' => ['profile', 'profile'];
        yield 'registration/profile' => ['signup', 'profile'];
    }

    #[DataProvider('races')]
    public function testExactlyOneHttpClaimantAfterBothAvailabilityChecks(string $first, string $second): void
    {
        $phone = '+63 917 888 7788';
        $db = $this->db();
        $db->executeStatement("DELETE FROM users WHERE email LIKE 'phone-race%@fixture.test'");
        $before = $db->fetchAllAssociative("SELECT * FROM users WHERE id IN ('usr_fx_p4','usr_fx_p5') ORDER BY id");
        $db->fetchOne("SELECT GET_LOCK('test_phone_claim_gate', 0)");
        foreach (['INSERT', 'UPDATE'] as $operation) {
            $db->executeStatement("CREATE TRIGGER test_phone_" . strtolower($operation) . " BEFORE $operation ON users FOR EACH ROW BEGIN IF NEW.phone = '$phone' THEN DO GET_LOCK(CONCAT('test_phone_arrival_', NEW.email), 0); IF GET_LOCK('test_phone_claim_gate', 20) <> 1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Phone barrier timed out'; END IF; DO RELEASE_LOCK('test_phone_claim_gate'); END IF; END");
        }
        $processes = [];
        $pipes = [];
        try {
            foreach ([$first, $second] as $i => $mode) {
                $job = ['mode' => $mode, 'user' => 'usr_fx_p' . ($i + 4), 'phone' => $i === 0 ? '09178887788' : '+639178887788', 'email' => 'phone-race' . $i . '@fixture.test', 'name' => 'Phone race ' . $i, 'ip' => '127.0.10.' . ($i + 1)];
                $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/Concurrency/phone-worker.php') . ' ' . escapeshellarg(base64_encode(json_encode($job)));
                $processes[$i] = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i], dirname(__DIR__, 2), ['PICKLERS_SKIP_DB_REBUILD' => '1'] + getenv());
            }
            $deadline = microtime(true) + 15;
            do {
                $arrivals = (int)$db->fetchOne("SELECT (IS_USED_LOCK('test_phone_arrival_phone-race0@fixture.test') IS NOT NULL) + (IS_USED_LOCK('test_phone_arrival_phone-race1@fixture.test') IS NOT NULL)");
                if ($arrivals === 2) {
                    break;
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            $db->fetchOne("SELECT RELEASE_LOCK('test_phone_claim_gate')");
            $results = [];
            $workerErrors = [];
            foreach ($processes as $i => $process) {
                $out = stream_get_contents($pipes[$i][1]);
                $err = stream_get_contents($pipes[$i][2]);
                $workerErrors[$i] = $err;
                fclose($pipes[$i][1]);
                fclose($pipes[$i][2]);
                self::assertSame(0, proc_close($process), $err . $out);
                $results[] = json_decode($out, true);
            }
            self::assertSame(2, $arrivals, json_encode($results));
            $statuses = array_column($results, 'status');
            sort($statuses);
            self::assertSame([200, 400], $statuses, json_encode($results));
            foreach ($results as $i => $result) {
                if ($result['status'] === 400) {
                    self::assertSame('This mobile number is already linked to another account.', $result['json']['message'], $workerErrors[$i]);
                    if ([$first, $second][$i] === 'profile') {
                        self::assertSame($before[$i], $db->fetchAssociative('SELECT * FROM users WHERE id = ?', [$before[$i]['id']]));
                    } else {
                        self::assertSame(0, (int)$db->fetchOne('SELECT COUNT(*) FROM users WHERE email = ?', ['phone-race' . $i . '@fixture.test']));
                    }
                }
            }
            self::assertSame(1, (int)$db->fetchOne('SELECT COUNT(*) FROM users WHERE phone = ?', [$phone]));
            $repository = static::getContainer()->get(UserRepository::class);
            $owner = $repository->getUserByEmailOrPhone($phone);
            foreach (['09178887788', '+639178887788', $phone, $owner['email']] as $identifier) {
                self::assertSame($owner['id'], $repository->getUserByEmailOrPhone($identifier)['id']);
            }
        } finally {
            $db->fetchOne("SELECT RELEASE_LOCK('test_phone_claim_gate')");
            foreach (['insert', 'update'] as $operation) {
                $db->executeStatement('DROP TRIGGER IF EXISTS test_phone_' . $operation);
            }
            $db->executeStatement("DELETE FROM users WHERE email LIKE 'phone-race%@fixture.test' AND id NOT LIKE 'usr_fx_%'");
        }
    }
}
