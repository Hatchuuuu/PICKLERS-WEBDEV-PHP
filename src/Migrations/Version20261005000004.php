<?php
declare(strict_types=1);

namespace Picklers\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Content moderation and admin notifications.
 *
 * - moderation_cases / moderation_case_events: persisted review queue with full
 *   history. `open_marker` is 1 while a case is open and NULL afterwards, so the
 *   unique key allows at most ONE open case per post/comment.
 * - feed_posts / feed_comments: reversible hiding (never deletion) with actor and reason.
 * - notification_broadcasts: one row per broadcast, keyed by an idempotency key so a
 *   retried or double-clicked send cannot notify the same audience twice.
 * - notifications.broadcast_id links each recipient row to its broadcast.
 * - admin_activity_seen: per-admin "seen up to" marker behind the bell's unread state.
 *
 * Rollback: drops these tables/columns (moderation history and hidden flags are
 * lost — every hidden post becomes visible again). Back up first.
 */
final class Version20261005000004 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Moderation queue + history, reversible content hiding, broadcasts and admin activity read state.';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('moderation_cases')) {
            $this->addSql(<<<'SQL'
                CREATE TABLE moderation_cases (
                    id VARCHAR(40) NOT NULL PRIMARY KEY,
                    content_type VARCHAR(20) NOT NULL,
                    content_id VARCHAR(64) NOT NULL,
                    content_author_id VARCHAR(64) NULL,
                    content_snapshot TEXT NULL,
                    source VARCHAR(20) NOT NULL,
                    category VARCHAR(30) NOT NULL,
                    reason TEXT NOT NULL,
                    status VARCHAR(20) NOT NULL DEFAULT 'open',
                    open_marker TINYINT NULL DEFAULT 1,
                    opened_by VARCHAR(64) NULL,
                    opened_at DATETIME NOT NULL,
                    resolved_by VARCHAR(64) NULL,
                    resolved_at DATETIME NULL,
                    resolution VARCHAR(30) NULL,
                    resolution_note TEXT NULL,
                    UNIQUE INDEX uniq_open_case (content_type, content_id, open_marker),
                    INDEX idx_case_status (status, opened_at)
                ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
                SQL);
        }
        if (!$schema->hasTable('moderation_case_events')) {
            $this->addSql(<<<'SQL'
                CREATE TABLE moderation_case_events (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    case_id VARCHAR(40) NOT NULL,
                    action VARCHAR(30) NOT NULL,
                    actor_user_id VARCHAR(64) NULL,
                    note TEXT NULL,
                    created_at DATETIME NOT NULL,
                    INDEX idx_case_events (case_id, id)
                ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
                SQL);
        }
        foreach (['feed_posts', 'feed_comments'] as $table) {
            $t = $schema->getTable($table);
            foreach (['hidden_at' => 'DATETIME NULL', 'hidden_by' => 'VARCHAR(64) NULL', 'hidden_reason' => 'VARCHAR(255) NULL'] as $col => $def) {
                if (!$t->hasColumn($col)) {
                    $this->addSql("ALTER TABLE {$table} ADD COLUMN {$col} {$def}");
                }
            }
            if (!$t->hasIndex("idx_{$table}_hidden")) {
                $this->addSql("ALTER TABLE {$table} ADD INDEX idx_{$table}_hidden (hidden_at)");
            }
        }

        if (!$schema->hasTable('notification_broadcasts')) {
            $this->addSql(<<<'SQL'
                CREATE TABLE notification_broadcasts (
                    id VARCHAR(40) NOT NULL PRIMARY KEY,
                    idempotency_key VARCHAR(64) NOT NULL,
                    title VARCHAR(150) NOT NULL,
                    body TEXT NOT NULL,
                    type VARCHAR(30) NOT NULL,
                    audience VARCHAR(20) NOT NULL,
                    recipient_count INT NOT NULL DEFAULT 0,
                    status VARCHAR(20) NOT NULL,
                    created_by VARCHAR(64) NOT NULL,
                    created_at DATETIME NOT NULL,
                    UNIQUE INDEX uniq_broadcast_key (idempotency_key)
                ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
                SQL);
        }
        $n = $schema->getTable('notifications');
        if (!$n->hasColumn('broadcast_id')) {
            $this->addSql('ALTER TABLE notifications ADD COLUMN broadcast_id VARCHAR(40) NULL, ADD INDEX idx_notif_broadcast (broadcast_id)');
        }

        if (!$schema->hasTable('admin_activity_seen')) {
            $this->addSql(<<<'SQL'
                CREATE TABLE admin_activity_seen (
                    user_id VARCHAR(64) NOT NULL PRIMARY KEY,
                    seen_at DATETIME NOT NULL
                ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
                SQL);
        }
    }

    public function down(Schema $schema): void
    {
        foreach (['admin_activity_seen', 'notification_broadcasts', 'moderation_case_events', 'moderation_cases'] as $table) {
            if ($schema->hasTable($table)) {
                $this->addSql("DROP TABLE {$table}");
            }
        }
        $n = $schema->getTable('notifications');
        if ($n->hasColumn('broadcast_id')) {
            $this->addSql('ALTER TABLE notifications DROP INDEX idx_notif_broadcast, DROP COLUMN broadcast_id');
        }
        foreach (['feed_posts', 'feed_comments'] as $table) {
            $t = $schema->getTable($table);
            if ($t->hasIndex("idx_{$table}_hidden")) {
                $this->addSql("ALTER TABLE {$table} DROP INDEX idx_{$table}_hidden");
            }
            foreach (['hidden_at', 'hidden_by', 'hidden_reason'] as $col) {
                if ($t->hasColumn($col)) {
                    $this->addSql("ALTER TABLE {$table} DROP COLUMN {$col}");
                }
            }
        }
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
