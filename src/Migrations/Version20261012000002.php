<?php
declare(strict_types=1);

namespace Picklers\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261012000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Scope Open Play booking counts to the match, status and calendar date.';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->getTable('bookings')->hasIndex('idx_booking_match_date')) {
            $this->addSql('ALTER TABLE bookings ADD INDEX idx_booking_match_date (match_id, status, booking_date)');
        }
    }

    public function down(Schema $schema): void
    {
        if ($schema->getTable('bookings')->hasIndex('idx_booking_match_date')) {
            $this->addSql('ALTER TABLE bookings DROP INDEX idx_booking_match_date');
        }
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
