<?php
declare(strict_types=1);

namespace Picklers\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'picklers:schema:install', description: 'Create the configured database if missing, install the base schema and seed development data.')]
final class SchemaInstallCommand extends Command
{
    public function __construct(
        private readonly Connection $db,
        private readonly SchemaInstaller $schema,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $params = $this->db->getParams();
        $database = $params['dbname'] ?? null;
        if (!is_string($database) || trim($database) === '') {
            throw new \RuntimeException('The Doctrine connection must configure a database name.');
        }

        // Connect to the same server without selecting a database that may not exist yet.
        unset($params['dbname'], $params['url']);
        $server = DriverManager::getConnection($params);
        try {
            $manager = $server->createSchemaManager();
            if (!in_array($database, $manager->listDatabases(), true)) {
                $server->executeStatement(
                    'CREATE DATABASE ' . $server->getDatabasePlatform()->quoteSingleIdentifier($database)
                    . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
                );
            }
        } finally {
            $server->close();
        }

        $this->schema->ensure();
        $output->writeln('Base schema installed. Run doctrine:migrations:migrate to apply the remaining migrations.');
        return Command::SUCCESS;
    }
}
