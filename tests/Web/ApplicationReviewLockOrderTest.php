<?php
declare(strict_types=1);

namespace Picklers\Tests\Web;

use Picklers\Tests\Admin\AdminWebTestCase;

final class ApplicationReviewLockOrderTest extends AdminWebTestCase
{
    public function testSubmissionAndApprovalUseTheSameLockOrder(): void
    {
        $this->exerciseReview('approve');
    }

    public function testSubmissionAndRejectionUseTheSameLockOrder(): void
    {
        $this->exerciseReview('reject');
    }

    private function exerciseReview(string $operation): void
    {
        $this->db()->executeStatement("DELETE FROM owner_applications WHERE user_id = 'usr_fx_p1'");
        if ($operation === 'reject') $this->db()->executeStatement("UPDATE users SET verification_status = 'pending_owner_review' WHERE id = 'usr_fx_p1'");
        $dir = $_ENV['PRIVATE_DOCUMENT_DIR'];
        $before = glob($dir . '/race_*.png') ?: [];
        $auditBefore = (int)$this->scalar("SELECT COUNT(*) FROM audit_events WHERE target_id = 'fx_app_lock_order'");
        $notificationsBefore = (int)$this->scalar("SELECT COUNT(*) FROM notifications WHERE user_id = 'usr_fx_p1'");
        $procs = $pipes = [];
        $lock = self::pdo();
        try {
            $start = function (string $worker, string $arg) use (&$procs, &$pipes): array {
                $i = count($procs);
                $procs[$i] = proc_open([PHP_BINARY, __DIR__ . '/' . $worker, $arg], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes[$i], dirname(__DIR__, 2), ['PICKLERS_SKIP_DB_REBUILD'=>'1'] + getenv());
                self::assertIsResource($procs[$i]);
                stream_set_timeout($pipes[$i][1], 15);
                $ready = json_decode(trim((string)fgets($pipes[$i][1])), true);
                self::assertIsArray($ready, 'worker reached its barrier');
                return $ready;
            };
            // Submission has already passed the no-pending preflight and uploaded its documents.
            $submit = $start('owner-application-worker.php', 'review_' . $operation);
            $this->db()->executeStatement("INSERT INTO owner_applications (id,user_id,facility_name,address,owner_name,business_email,phone,entity_name,reg_number,courts_count,status,created_at) VALUES ('fx_app_lock_order','usr_fx_p1','Lock Arena','Dumaguete','Paula','paula@fixture.test','09175550199','Lock Arena Inc','DTI-LOCK',1,'pending_review',NOW())");
            $lock->beginTransaction();
            $lock->query("SELECT id FROM users WHERE id = 'usr_fx_p1' FOR UPDATE");
            fwrite($pipes[0][0], "go\n"); fflush($pipes[0][0]);
            $submitWait = $this->waitForUserLock((int)$submit['connection']);
            $review = $start('application-review-worker.php', $operation);
            fwrite($pipes[1][0], "go\n"); fflush($pipes[1][0]);
            $reviewWait = $this->waitForUserLock((int)$review['connection']);
            // Database lock/query records establish overlap; elapsed time is never the proof.
            $evidence = ['operation' => $operation, 'submit_wait' => $submitWait, 'review_wait' => $reviewWait];
            $lock->commit();
            $results = [];
            foreach ($procs as $i => $proc) {
                $out = stream_get_contents($pipes[$i][1]);
                $err = stream_get_contents($pipes[$i][2]);
                self::assertSame(0, proc_close($proc), $err);
                unset($procs[$i]);
                $results[$i] = json_decode(trim($out), true);
                self::assertIsArray($results[$i], $out . $err);
            }
            $evidence['results'] = $results;
            if ($results[0]['status'] === 500 || $results[1]['status'] === 500) {
                $innodb = $this->db()->fetchAssociative('SHOW ENGINE INNODB STATUS');
                preg_match('/LATEST DETECTED DEADLOCK\n-+\n(.*?)(?=\n-+\nTRANSACTIONS)/s', (string)($innodb['Status'] ?? ''), $deadlock);
                $evidence['deadlock'] = preg_replace('/Record lock,.*?(?=\n\*\*\*|\z)/s', '[synthetic record contents omitted]', $deadlock[1] ?? (string)($innodb['Status'] ?? ''));
            }
            if ($path = getenv('F06_EVIDENCE_PATH')) file_put_contents($path, json_encode($evidence, JSON_PRETTY_PRINT) . "\n", FILE_APPEND);
            self::assertSame(409, $results[0]['status'], json_encode($evidence));
            self::assertSame(200, $results[1]['status'], json_encode($evidence));
            self::assertSame($operation === 'approve' ? 'approved' : 'rejected', $this->scalar("SELECT status FROM owner_applications WHERE id = 'fx_app_lock_order'"));
            self::assertSame(1, (int)$this->scalar("SELECT COUNT(*) FROM owner_applications WHERE user_id = 'usr_fx_p1'"));
            self::assertSame($operation === 'approve' ? 'verified' : 'unverified', $this->scalar("SELECT verification_status FROM users WHERE id = 'usr_fx_p1'"));
            self::assertSame($operation === 'approve' ? 'owner' : 'player', $this->scalar("SELECT role FROM users WHERE id = 'usr_fx_p1'"));
            self::assertSame($operation === 'approve' ? 1 : 0, (int)$this->scalar("SELECT is_owner FROM users WHERE id = 'usr_fx_p1'"));
            self::assertSame($auditBefore + 1, (int)$this->scalar("SELECT COUNT(*) FROM audit_events WHERE target_id = 'fx_app_lock_order'"));
            self::assertSame($notificationsBefore + 1, (int)$this->scalar("SELECT COUNT(*) FROM notifications WHERE user_id = 'usr_fx_p1'"));
            self::assertSame([], array_values(array_diff(glob($dir . '/race_*.png') ?: [], $before)), 'duplicate submission removes its uploads');
            self::assertSame($operation === 'approve' ? 1 : 0, (int)$this->scalar("SELECT COUNT(*) FROM facilities WHERE owner_id = 'usr_fx_p1' AND name = 'Lock Arena'"));
        } finally {
            if ($lock->inTransaction()) $lock->rollBack();
            foreach ($procs as $proc) { proc_terminate($proc); proc_close($proc); }
            foreach ($pipes as $p) foreach ($p as $pipe) if (is_resource($pipe)) fclose($pipe);
            $this->db()->executeStatement("DELETE FROM courts WHERE facility_id IN (SELECT id FROM facilities WHERE owner_id = 'usr_fx_p1' AND name = 'Lock Arena')");
            $this->db()->executeStatement("DELETE FROM owner_applications WHERE user_id = 'usr_fx_p1'");
            $this->db()->executeStatement("DELETE FROM facilities WHERE owner_id = 'usr_fx_p1' AND name = 'Lock Arena'");
            foreach (array_diff(glob($dir . '/race_*.png') ?: [], $before) as $file) unlink($file);
        }
    }

    private function waitForUserLock(int $connection): array
    {
        $waitSql = str_contains((string)$this->db()->fetchOne('SELECT VERSION()'), 'MariaDB')
            ? <<<'SQL'
                SELECT t.trx_mysql_thread_id, l.lock_table, l.lock_mode
                  FROM information_schema.INNODB_LOCK_WAITS w
                  JOIN information_schema.INNODB_TRX t ON t.trx_id = w.requesting_trx_id
                  JOIN information_schema.INNODB_LOCKS l ON l.lock_id = w.requested_lock_id
                SQL
            : <<<'SQL'
                SELECT t.PROCESSLIST_ID AS trx_mysql_thread_id,
                       CONCAT('`', l.OBJECT_SCHEMA, '`.`', l.OBJECT_NAME, '`') AS lock_table,
                       l.LOCK_MODE AS lock_mode
                  FROM performance_schema.data_lock_waits w
                  JOIN performance_schema.threads t ON t.THREAD_ID = w.REQUESTING_THREAD_ID
                  JOIN performance_schema.data_locks l
                    ON l.ENGINE = w.ENGINE AND l.ENGINE_LOCK_ID = w.REQUESTING_ENGINE_LOCK_ID
                SQL;
        $deadline = microtime(true) + 10;
        do {
            $process = $this->db()->fetchAssociative('SELECT ID,DB,STATE,INFO FROM information_schema.PROCESSLIST WHERE ID=?', [$connection]);
            $waits = $this->db()->fetchAllAssociative($waitSql);
            foreach ($waits as $wait) {
                if ((int)$wait['trx_mysql_thread_id'] === $connection && $wait['lock_table'] === '`' . $this->db()->getDatabase() . '`.`users`') {
                    return ['process' => $process, 'lock_wait' => $wait];
                }
            }
            // MariaDB can queue a second locking read in Statistics before exposing its InnoDB transaction.
            if ($process !== false && ($process['STATE'] ?? '') !== ''
                && preg_match("/^SELECT .* FROM users WHERE id = 'usr_fx_p1' FOR UPDATE$/", (string)($process['INFO'] ?? ''))) {
                return ['process' => $process, 'lock_wait' => 'locking read queued on the held user row'];
            }
            usleep(20000);
        } while (microtime(true) < $deadline);
        self::fail('Worker did not queue on users: ' . json_encode([
            'expected_connection' => $connection,
            'process' => $this->db()->fetchAllAssociative('SELECT ID,DB,STATE,INFO FROM information_schema.PROCESSLIST'),
            'waits' => $waits,
            'transactions' => $this->db()->fetchAllAssociative('SELECT trx_id,trx_state,trx_mysql_thread_id,trx_query FROM information_schema.INNODB_TRX'),
        ]));
    }
}
