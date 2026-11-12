<?php
declare(strict_types=1);

namespace Picklers\Tests\Web;

use Picklers\Tests\Admin\AdminWebTestCase;

final class OwnerApplicationConcurrencyTest extends AdminWebTestCase
{
    public function testSeparateSessionsCannotSubmitTwoPendingApplications(): void
    {
        $this->db()->executeStatement("DELETE FROM owner_applications WHERE user_id = 'usr_fx_p1'");
        $dir = $_ENV['PRIVATE_DOCUMENT_DIR'];
        $before = glob($dir . '/race_*.png') ?: [];
        $procs = $pipes = $ready = [];
        $lock = self::pdo();
        $lock->beginTransaction();
        $lock->query("SELECT id FROM users WHERE id = 'usr_fx_p1' FOR UPDATE");
        try {
            for ($i = 0; $i < 2; $i++) {
                $procs[$i] = proc_open([PHP_BINARY, __DIR__ . '/owner-application-worker.php', (string)$i], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes[$i], dirname(__DIR__, 2), ['PICKLERS_SKIP_DB_REBUILD'=>'1'] + getenv());
                self::assertIsResource($procs[$i]);
                stream_set_timeout($pipes[$i][1], 10);
                $ready[$i] = json_decode(trim((string)fgets($pipes[$i][1])), true);
                self::assertIsArray($ready[$i], 'worker reached upload barrier');
            }
            self::assertNotSame($ready[0]['session'], $ready[1]['session']);
            self::assertNotSame($ready[0]['connection'], $ready[1]['connection']);
            foreach ($pipes as $p) { fwrite($p[0], "go\n"); fflush($p[0]); }
            $deadline = microtime(true) + 5;
            do {
                $waiting = (int)$this->scalar("SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID IN (?, ?) AND COMMAND = 'Query' AND (INFO LIKE 'UPDATE users SET %' OR INFO LIKE 'SELECT % FROM users WHERE id = % FOR UPDATE')", [$ready[0]['connection'], $ready[1]['connection']]);
                if ($waiting === 2) break;
                usleep(20000);
            } while (microtime(true) < $deadline);
            self::assertSame(2, $waiting, 'both requests overlap while waiting for the applicant row: ' . json_encode($this->db()->fetchAllAssociative('SELECT ID, STATE, INFO FROM information_schema.PROCESSLIST WHERE ID IN (?, ?)', [$ready[0]['connection'], $ready[1]['connection']])));
            $lock->commit();
            $statuses = [];
            foreach ($procs as $i => $proc) {
                $out = stream_get_contents($pipes[$i][1]);
                $err = stream_get_contents($pipes[$i][2]);
                self::assertSame(0, proc_close($proc), $err);
                unset($procs[$i]);
                $result = json_decode(trim($out), true);
                self::assertIsArray($result, $out . $err);
                $statuses[] = $result['status'];
            }
            sort($statuses);
            self::assertSame([200, 409], $statuses);
            $apps = $this->db()->fetchAllAssociative("SELECT * FROM owner_applications WHERE user_id = 'usr_fx_p1'");
            self::assertCount(1, $apps);
            self::assertSame('pending_review', $apps[0]['status']);
            self::assertSame('verified', $this->scalar("SELECT verification_status FROM users WHERE id = 'usr_fx_p1'"), 'the fixture player was already verified');
            $expected = [$dir . '/' . $apps[0]['permit_file'], $dir . '/' . $apps[0]['gov_id_file']];
            $actual = array_values(array_diff(glob($dir . '/race_*.png') ?: [], $before));
            sort($expected); sort($actual);
            self::assertSame($expected, $actual, 'only accepted private documents survive');
        } finally {
            if ($lock->inTransaction()) $lock->rollBack();
            foreach ($procs as $proc) { proc_terminate($proc); proc_close($proc); }
            foreach ($pipes as $p) foreach ($p as $pipe) if (is_resource($pipe)) fclose($pipe);
            $this->db()->executeStatement("DELETE FROM owner_applications WHERE user_id = 'usr_fx_p1'");
            foreach (array_diff(glob($dir . '/race_*.png') ?: [], $before) as $file) unlink($file);
        }
    }
}
