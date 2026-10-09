<?php
declare(strict_types=1);

namespace Picklers\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Governed-workflow columns. Additive only: every column is NULLable or has a
 * default that preserves the current behaviour of existing rows (all facilities
 * stay `active`, no historical wallet row is rewritten or re-classified).
 *
 * - owner_applications: reviewer, review time, reason, the provisioned facility.
 * - facilities / courts: availability status with reason and actor.
 * - bookings: who changed the status last, when, and why.
 * - wallet_transactions: idempotency key (unique), actor, kind, booking link,
 *   reason and resulting balance for NEW entries.
 * - promo_codes: archive instead of delete once redeemed; creator.
 * - promo_redemptions: one redemption per promo per booking.
 *
 * Rollback: down() drops only these columns/indexes. Back up first — admin
 * review history, availability reasons and idempotency keys are lost on rollback.
 */
final class Version20261005000003 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Workflow columns for applications, facilities, courts, bookings, wallet ledger and promos.';
    }

    /** @return array<string, array<string,string>> table => column => definition */
    private function columns(): array
    {
        return [
            'owner_applications' => [
                'reviewed_by' => 'VARCHAR(64) NULL',
                'reviewed_at' => 'DATETIME NULL',
                'review_reason' => 'TEXT NULL',
                'facility_id' => 'INT NULL',
            ],
            'facilities' => [
                'operating_status' => "VARCHAR(20) NOT NULL DEFAULT 'active'",
                'status_reason' => 'TEXT NULL',
                'status_changed_at' => 'DATETIME NULL',
                'status_changed_by' => 'VARCHAR(64) NULL',
            ],
            'courts' => [
                'status_reason' => 'VARCHAR(255) NULL',
                'status_changed_at' => 'DATETIME NULL',
                'status_changed_by' => 'VARCHAR(64) NULL',
            ],
            'bookings' => [
                'status_reason' => 'TEXT NULL',
                'status_changed_at' => 'DATETIME NULL',
                'status_changed_by' => 'VARCHAR(64) NULL',
            ],
            'wallet_transactions' => [
                'idempotency_key' => 'VARCHAR(100) NULL',
                'actor_user_id' => 'VARCHAR(64) NULL',
                'entry_kind' => 'VARCHAR(30) NULL',
                'booking_id' => 'VARCHAR(64) NULL',
                'reason' => 'TEXT NULL',
                'balance_after' => 'DECIMAL(10,2) NULL',
            ],
            'promo_codes' => [
                'archived_at' => 'DATETIME NULL',
                'archived_by' => 'VARCHAR(64) NULL',
                'created_by' => 'VARCHAR(64) NULL',
            ],
        ];
    }

    /** @return array<string, array<string,string>> table => index => definition */
    private function indexes(): array
    {
        return [
            'owner_applications' => ['uniq_app_facility' => 'UNIQUE INDEX uniq_app_facility (facility_id)'],
            'facilities' => ['idx_facility_status' => 'INDEX idx_facility_status (operating_status)'],
            'wallet_transactions' => [
                'uniq_wallet_idempotency' => 'UNIQUE INDEX uniq_wallet_idempotency (idempotency_key)',
                'idx_wallet_booking' => 'INDEX idx_wallet_booking (booking_id)',
                'idx_wallet_created' => 'INDEX idx_wallet_created (created_at)',
            ],
            'bookings' => ['idx_booking_created' => 'INDEX idx_booking_created (created_at)'],
            'promo_redemptions' => ['uniq_redemption_booking' => 'UNIQUE INDEX uniq_redemption_booking (promo_id, booking_id)'],
        ];
    }

    public function up(Schema $schema): void
    {
        foreach ($this->columns() as $table => $cols) {
            $t = $schema->getTable($table);
            foreach ($cols as $name => $definition) {
                if (!$t->hasColumn($name)) {
                    $this->addSql("ALTER TABLE {$table} ADD COLUMN {$name} {$definition}");
                }
            }
        }

        $duplicateRedemptions = (int)$this->connection->fetchOne(
            'SELECT COUNT(*) FROM (SELECT promo_id, booking_id FROM promo_redemptions WHERE booking_id IS NOT NULL GROUP BY promo_id, booking_id HAVING COUNT(*) > 1) d'
        );

        foreach ($this->indexes() as $table => $indexes) {
            $t = $schema->getTable($table);
            foreach ($indexes as $name => $definition) {
                if ($t->hasIndex($name)) {
                    continue;
                }
                if ($name === 'uniq_redemption_booking' && $duplicateRedemptions > 0) {
                    $this->warnIf(true, "Skipped {$name}: {$duplicateRedemptions} historical duplicate redemption(s) exist. They are preserved; resolve them before re-running.");
                    continue;
                }
                $this->addSql("ALTER TABLE {$table} ADD {$definition}");
            }
        }
    }

    public function down(Schema $schema): void
    {
        foreach ($this->indexes() as $table => $indexes) {
            $t = $schema->getTable($table);
            foreach (array_keys($indexes) as $name) {
                if ($t->hasIndex($name)) {
                    $this->addSql("ALTER TABLE {$table} DROP INDEX {$name}");
                }
            }
        }
        foreach ($this->columns() as $table => $cols) {
            $t = $schema->getTable($table);
            foreach (array_keys($cols) as $name) {
                if ($t->hasColumn($name)) {
                    $this->addSql("ALTER TABLE {$table} DROP COLUMN {$name}");
                }
            }
        }
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
