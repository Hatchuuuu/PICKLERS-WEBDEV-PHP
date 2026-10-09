<?php
declare(strict_types=1);

namespace Picklers\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Admin foundations: append-only audit trail and the explicit privileged-admin grant.
 *
 * audit_events is append-only at the database level too: UPDATE and DELETE are
 * rejected by triggers, so no code path (including a compromised admin endpoint)
 * can rewrite history. Datetimes follow the platform convention (Asia/Manila wall clock).
 *
 * Rollback: down() refuses to drop a non-empty audit trail unless
 * PICKLERS_ALLOW_AUDIT_DROP=1 is set — export it first (Audit panel → CSV).
 */
final class Version20261005000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Admin foundations: audit_events (append-only) and admin_privileges.';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('audit_events')) {
            $this->addSql(<<<'SQL'
                CREATE TABLE audit_events (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    occurred_at DATETIME(6) NOT NULL,
                    actor_user_id VARCHAR(64) NULL,
                    actor_name VARCHAR(120) NULL,
                    effective_user_id VARCHAR(64) NULL,
                    action VARCHAR(80) NOT NULL,
                    target_type VARCHAR(40) NULL,
                    target_id VARCHAR(80) NULL,
                    reason TEXT NULL,
                    changes LONGTEXT NULL,
                    outcome VARCHAR(16) NOT NULL,
                    correlation_id VARCHAR(64) NOT NULL,
                    ip_address VARCHAR(45) NULL,
                    INDEX idx_audit_time (occurred_at),
                    INDEX idx_audit_actor (actor_user_id, occurred_at),
                    INDEX idx_audit_target (target_type, target_id),
                    INDEX idx_audit_action (action, occurred_at),
                    INDEX idx_audit_correlation (correlation_id)
                ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
                SQL);
            $this->addSql("CREATE TRIGGER audit_events_no_update BEFORE UPDATE ON audit_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_events is append-only'");
            $this->addSql("CREATE TRIGGER audit_events_no_delete BEFORE DELETE ON audit_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_events is append-only'");
        }

        if (!$schema->hasTable('admin_privileges')) {
            $this->addSql(<<<'SQL'
                CREATE TABLE admin_privileges (
                    user_id VARCHAR(64) NOT NULL PRIMARY KEY,
                    level VARCHAR(20) NOT NULL DEFAULT 'privileged',
                    granted_by VARCHAR(64) NULL,
                    granted_at DATETIME NOT NULL,
                    note VARCHAR(255) NULL
                ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
                SQL);
        }
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('audit_events')) {
            $rows = (int)$this->connection->fetchOne('SELECT COUNT(*) FROM audit_events');
            $this->abortIf(
                $rows > 0 && getenv('PICKLERS_ALLOW_AUDIT_DROP') !== '1',
                "audit_events holds {$rows} record(s). Export them, then set PICKLERS_ALLOW_AUDIT_DROP=1 to roll back."
            );
            $this->addSql('DROP TRIGGER IF EXISTS audit_events_no_update');
            $this->addSql('DROP TRIGGER IF EXISTS audit_events_no_delete');
            $this->addSql('DROP TABLE audit_events');
        }
        if ($schema->hasTable('admin_privileges')) {
            $this->addSql('DROP TABLE admin_privileges');
        }
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
