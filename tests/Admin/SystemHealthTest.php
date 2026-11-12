<?php
declare(strict_types=1);

namespace Picklers\Tests\Admin;

use Picklers\Admin\Service\SystemHealthService;

final class SystemHealthTest extends AdminWebTestCase
{
    public function testUnknownExecutedVersionCannotHideAMissingMigration(): void
    {
        $db = $this->db();
        $row = $db->fetchAssociative('SELECT * FROM doctrine_migration_versions ORDER BY version LIMIT 1');
        self::assertNotFalse($row);
        $count = $this->scalar('SELECT COUNT(*) FROM doctrine_migration_versions');
        $db->beginTransaction();
        try {
            $db->update('doctrine_migration_versions', ['version' => 'Unknown\\Version20990101000000'], ['version' => $row['version']]);
            self::assertSame($count, $this->scalar('SELECT COUNT(*) FROM doctrine_migration_versions'));
            $facts = static::getContainer()->get(SystemHealthService::class)->facts();
            $migration = array_values(array_filter($facts, fn($fact) => $fact['label'] === 'Schema migrations'))[0];
            self::assertSame('1 pending', $migration['value']);
            self::assertSame('warn', $migration['state']);
        } finally {
            $db->rollBack();
        }
    }
}
