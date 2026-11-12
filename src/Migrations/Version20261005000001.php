<?php
declare(strict_types=1);

namespace Picklers\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Picklers\Persistence\SchemaInstaller;

/**
 * Baseline: the pre-Symfony schema.
 *
 * The 23 base tables are created and evolved by SchemaInstaller (idempotent
 * CREATE TABLE IF NOT EXISTS + column/index checks). This migration does not
 * recreate or diff them — it only asserts they exist so every later migration has
 * a known starting point. Run picklers:schema:install before migrating a fresh database.
 *
 * Rollback: nothing to undo. Never drop legacy tables from a migration.
 */
final class Version20261005000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Baseline: assert the legacy PICKLERS schema is present (no changes).';
    }

    public function up(Schema $schema): void
    {
        $missing = array_values(array_filter(
            SchemaInstaller::REQUIRED_TABLES,
            static fn(string $t) => !$schema->hasTable($t)
        ));
        $this->abortIf($missing !== [], 'Base tables missing: ' . implode(', ', $missing) . '. Run php bin/console picklers:schema:install before migrating.');
        $this->write('Base schema present: ' . count(SchemaInstaller::REQUIRED_TABLES) . ' tables verified.');
    }

    public function down(Schema $schema): void
    {
        $this->write('Baseline has nothing to roll back.');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
