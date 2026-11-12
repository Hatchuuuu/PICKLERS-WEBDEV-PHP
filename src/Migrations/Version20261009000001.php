<?php
declare(strict_types=1);

namespace Picklers\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Tournaments move from <data dir>/tournaments.json into MySQL. Each row keeps the
 * whole record as JSON (`data`), exactly as TournamentService wrote it to the file;
 * `seq` preserves the file's order. The file's records are imported, and the file
 * itself is left in place untouched.
 *
 * The unused, never-written tables of an earlier design (tournaments,
 * tournament_teams, tournament_matches) are dropped first — only when all are empty.
 *
 * Rollback: writes every row back to tournaments.json, then drops the table.
 */
final class Version20261009000001 extends AbstractMigration
{
    private const LEGACY_TABLES = ['tournament_matches', 'tournament_teams', 'tournaments'];
    private bool $dryRun = false;

    public function setDryRun(bool $dryRun): void
    {
        $this->dryRun = $dryRun;
    }

    public function getDescription(): string
    {
        return 'Tournaments stored in MySQL (imported from tournaments.json).';
    }

    public function up(Schema $schema): void
    {
        $records = $this->readJsonFile();
        $importStarted = $schema->hasTable('tournaments') && $schema->getTable('tournaments')->hasColumn('data');

        if (!$importStarted) {
            foreach (self::LEGACY_TABLES as $table) {
                if ($schema->hasTable($table)) {
                    $rows = (int)$this->connection->fetchOne("SELECT COUNT(*) FROM {$table}");
                    $this->abortIf($rows > 0, "Table {$table} holds {$rows} row(s); refusing to drop it. Nothing was changed.");
                }
            }

            foreach (self::LEGACY_TABLES as $table) {
                if ($schema->hasTable($table)) {
                    $this->addSql("DROP TABLE {$table}");
                }
            }
            $this->addSql(<<<'SQL'
                CREATE TABLE tournaments (
                    seq BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    id VARCHAR(64) NOT NULL,
                    facility_id VARCHAR(64) NOT NULL DEFAULT '',
                    data LONGTEXT NOT NULL,
                    PRIMARY KEY (seq),
                    UNIQUE KEY uniq_tournaments_id (id),
                    KEY idx_tournaments_facility (facility_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                SQL);
        }

        // find() returns the first record with an id, so a duplicate id was never reachable.
        // Retained DDL/rows after a failed import must not hide missing records on retry.
        $seen = $importStarted ? array_fill_keys($this->connection->fetchFirstColumn('SELECT id FROM tournaments'), true) : [];
        $imported = 0;
        foreach ($records as $row) {
            $id = is_array($row) ? (string)($row['id'] ?? '') : '';
            if ($id === '' || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            ++$imported;
            $this->addSql(
                'INSERT INTO tournaments (id, facility_id, data) VALUES (?, ?, ?)',
                [$id, (string)($row['facility_id'] ?? ''), json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)]
            );
        }
        $this->write(sprintf('Importing %d tournament(s) from %s', $imported, $this->jsonPath()));
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('tournaments') || !$schema->getTable('tournaments')->hasColumn('data')) {
            return;
        }
        if ($this->dryRun) {
            $this->addSql('DROP TABLE tournaments');
            return;
        }

        $rows = [];
        foreach ($this->connection->fetchFirstColumn('SELECT data FROM tournaments ORDER BY seq') as $json) {
            $row = json_decode((string)$json, true);
            $this->abortIf(json_last_error() !== JSON_ERROR_NONE || !is_array($row), 'Invalid tournament JSON in the database; the file and table were kept.');
            $rows[] = $row;
        }
        $export = json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
        $written = @file_put_contents($this->jsonPath(), $export, LOCK_EX);
        $this->abortIf($written !== strlen($export), 'Could not write ' . $this->jsonPath() . '; the table was kept.');
        $this->write(sprintf('Wrote %d tournament(s) back to %s', count($rows), $this->jsonPath()));

        $this->addSql('DROP TABLE tournaments');
    }

    public function isTransactional(): bool
    {
        return false;
    }

    private function jsonPath(): string
    {
        return (defined('DATA_PATH') ? DATA_PATH : dirname(__DIR__, 2) . '/database') . '/tournaments.json';
    }

    /** @return array<int,mixed> */
    private function readJsonFile(): array
    {
        $path = $this->jsonPath();
        if (!is_file($path)) {
            return [];
        }
        $contents = @file_get_contents($path);
        $this->abortIf($contents === false, "Could not read {$path}; refusing to import it. Nothing was changed.");
        $raw = trim($contents);
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        $this->abortIf(!is_array($decoded), "{$path} is not valid JSON; refusing to import it. Nothing was changed.");

        return $decoded;
    }
}
