<?php
declare(strict_types=1);

namespace Picklers\Admin\Service;

use Doctrine\DBAL\Connection;
use Picklers\Admin\Repository\AuditRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Kernel;

/**
 * Runtime and storage facts for operators. Deliberately excludes anything
 * secret: no credentials, no secret keys, no environment dump, no file paths
 * beyond whether a location is usable.
 */
final class SystemHealthService
{
    public function __construct(
        private readonly Connection $db,
        private readonly AuditRepository $audit,
        private readonly DocumentStorage $documents,
        #[Autowire('%kernel.environment%')] private readonly string $environment,
        #[Autowire('%kernel.debug%')] private readonly bool $debug,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
        #[Autowire('%kernel.cache_dir%')] private readonly string $cacheDir,
    ) {
    }

    /** @return list<array{label:string,value:string,state:string,hint?:string}> */
    public function facts(): array
    {
        $facts = [];
        $facts[] = ['label' => 'PHP runtime', 'value' => PHP_VERSION, 'state' => 'ok'];
        $facts[] = ['label' => 'Symfony', 'value' => Kernel::VERSION . ' (' . $this->environment . ($this->debug ? ', debug on' : '') . ')', 'state' => $this->environment === 'prod' && $this->debug ? 'warn' : 'ok'];

        try {
            $version = (string)$this->db->fetchOne('SELECT VERSION()');
            $facts[] = ['label' => 'Primary database', 'value' => 'MySQL/MariaDB ' . $version . ' — connected', 'state' => 'ok'];
        } catch (\Throwable $e) {
            $facts[] = ['label' => 'Primary database', 'value' => 'Unavailable', 'state' => 'error'];
        }

        $pending = $this->pendingMigrations();
        $facts[] = ['label' => 'Schema migrations', 'value' => $pending === null ? 'Unknown' : ($pending === 0 ? 'Up to date' : "{$pending} pending"), 'state' => $pending === 0 ? 'ok' : 'warn',
            'hint' => $pending ? 'Run php bin/console doctrine:migrations:migrate' : null];

        $facts[] = ['label' => 'Timezone', 'value' => date_default_timezone_get() . ' (stored datetimes are Manila wall-clock)', 'state' => date_default_timezone_get() === 'Asia/Manila' ? 'ok' : 'warn'];
        $facts[] = ['label' => 'Audit trail', 'value' => number_format($this->audit->count()) . ' events (append-only)', 'state' => 'ok'];

        $docOk = $this->documents->isConfigured();
        $facts[] = ['label' => 'Private document storage', 'value' => $docOk ? 'Readable' : 'Missing or unreadable', 'state' => $docOk ? 'ok' : 'error'];
        $facts[] = ['label' => 'Cache directory', 'value' => is_writable($this->cacheDir) ? 'Writable' : 'Not writable', 'state' => is_writable($this->cacheDir) ? 'ok' : 'error'];
        $sessionPath = (string)ini_get('session.save_path');
        $facts[] = ['label' => 'Session storage', 'value' => ($sessionPath === '' || is_writable($sessionPath)) ? 'Writable' : 'Not writable', 'state' => ($sessionPath === '' || is_writable($sessionPath)) ? 'ok' : 'error'];
        $simulated = filter_var($_ENV['PAYMENTS_SIMULATED'] ?? false, FILTER_VALIDATE_BOOLEAN) && $this->environment !== 'prod';
        $facts[] = ['label' => 'Payment gateway', 'value' => $simulated ? 'None — simulated top-ups enabled (development only)' : 'None integrated — external payments are unverified', 'state' => 'warn'];

        return $facts;
    }

    private function pendingMigrations(): ?int
    {
        try {
            $available = count(glob($this->projectDir . '/src/Migrations/Version*.php') ?: []);
            $executed = (int)$this->db->fetchOne('SELECT COUNT(*) FROM doctrine_migration_versions');

            return max(0, $available - $executed);
        } catch (\Throwable) {
            return null;
        }
    }
}
