<?php
declare(strict_types=1);

namespace Picklers\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * users.password_changed_at — when a password is reset or changed, every session
 * that signed in before that moment is signed out on its next request (legacy
 * area and admin console alike). NULL for existing accounts, so no live session
 * is affected by running this migration.
 *
 * Rollback: drops the column; session invalidation after password resets stops.
 */
final class Version20261005000005 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'users.password_changed_at for session invalidation after password resets.';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->getTable('users')->hasColumn('password_changed_at')) {
            $this->addSql('ALTER TABLE users ADD COLUMN password_changed_at DATETIME NULL');
        }
    }

    public function down(Schema $schema): void
    {
        if ($schema->getTable('users')->hasColumn('password_changed_at')) {
            $this->addSql('ALTER TABLE users DROP COLUMN password_changed_at');
        }
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
