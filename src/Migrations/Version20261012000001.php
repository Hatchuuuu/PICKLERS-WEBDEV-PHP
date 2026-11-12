<?php
declare(strict_types=1);

namespace Picklers\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Picklers\Repositories\UserRepository;

final class Version20261012000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Atomic nullable account phone identities; refuses conflicting or noncanonical historic contacts.';
    }

    public function up(Schema $schema): void
    {
        // MySQL DDL commits implicitly: preflight every row before scheduling any mutation.
        $phones = [];
        $blankPhones = [];
        $noncanonical = [];
        $collisions = [];
        foreach ($this->connection->fetchAllAssociative('SELECT id, phone FROM users ORDER BY id') as $row) {
            $phone = UserRepository::normalizePhone($row['phone']);
            if ($row['phone'] !== null && trim($row['phone']) === '') {
                $blankPhones[] = $row;
            } elseif ($phone !== $row['phone']) {
                $noncanonical[] = $row['id'];
            }
            if ($phone !== null) {
                if (isset($phones[$phone])) {
                    $collisions[] = $phones[$phone] . '/' . $row['id'];
                }
                $phones[$phone] = $row['id'];
            }
        }
        $this->abortIf($collisions !== [] || $noncanonical !== [],
            'Phone migration refused; nothing changed. Resolve ownership collisions and explicitly canonicalize populated historic contacts after backup, then retry. Collision account IDs: '
            . implode(', ', $collisions) . '; noncanonical account IDs: ' . implode(', ', $noncanonical));
        $table = $schema->getTable('users');
        foreach ($blankPhones as $row) {
            $this->addSql('UPDATE users SET phone = NULL WHERE id = ? AND BINARY phone = ?', [$row['id'], $row['phone']]);
        }
        if (!$table->hasIndex('uniq_users_phone')) {
            $this->addSql('ALTER TABLE users ADD UNIQUE INDEX uniq_users_phone (phone)');
        }
    }

    public function down(Schema $schema): void
    {
        if ($schema->getTable('users')->hasIndex('uniq_users_phone')) {
            $this->addSql('ALTER TABLE users DROP INDEX uniq_users_phone');
        }
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
