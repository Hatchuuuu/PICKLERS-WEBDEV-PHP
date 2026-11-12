<?php
declare(strict_types=1);

namespace Picklers\Persistence;

use Doctrine\Common\EventManager;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Event\MigrationsVersionEventArgs;
use Doctrine\Migrations\EventDispatcher;
use Doctrine\Migrations\Events;
use Picklers\Migrations\Version20261009000001;

final class TournamentMigrationListener
{
    public function __invoke(DependencyFactory $factory): EventDispatcher
    {
        // DBAL 4 has no connection event manager; migrations need an explicit dispatcher.
        $events = new EventManager();
        $events->addEventListener([Events::onMigrationsVersionExecuting], $this);
        return new EventDispatcher($factory->getConnection(), $events);
    }

    public function onMigrationsVersionExecuting(MigrationsVersionEventArgs $event): void
    {
        $migration = $event->getPlan()->getMigration();
        if ($migration instanceof Version20261009000001) {
            $migration->setDryRun($event->getMigratorConfiguration()->isDryRun());
        }
    }
}
