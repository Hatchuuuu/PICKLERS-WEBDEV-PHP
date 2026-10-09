<?php
declare(strict_types=1);

namespace Picklers;

use Doctrine\DBAL\Connection;
use Picklers\Core\Database;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

/**
 * Symfony kernel for the whole PICKLERS application: every request
 * (public/index.php), its routes (config/symfony/routes.php), security, session
 * and database connection.
 *
 * Symfony configuration lives in config/symfony/; config/app.php and
 * config/database.php hold the shared session and database settings.
 */
final class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function getProjectDir(): string
    {
        return \dirname(__DIR__);
    }

    public function getCacheDir(): string
    {
        return $this->getProjectDir() . '/var/cache/' . $this->environment;
    }

    public function getLogDir(): string
    {
        return $this->getProjectDir() . '/var/log';
    }

    private function getConfigDir(): string
    {
        return $this->getProjectDir() . '/config/symfony';
    }

    public function boot(): void
    {
        parent::boot();
        // The persistence engine runs on Doctrine's connection (resolved on first use).
        $container = $this->getContainer();
        Database::useConnection(static fn(): Connection => $container->get('doctrine')->getConnection());
    }

    protected function build(ContainerBuilder $container): void
    {
        if ($this->environment !== 'test') {
            return;
        }
        // The test suites drive the run's one PHP session and arrange it between
        // requests; the session listener's per-request reset (meant for long-running
        // workers) would abort and empty it each time the test kernel handles a request.
        $container->addCompilerPass(new class implements CompilerPassInterface {
            public function process(ContainerBuilder $container): void
            {
                if ($container->has('session_listener')) {
                    $container->findDefinition('session_listener')->clearTag('kernel.reset');
                }
            }
        });
    }
}
