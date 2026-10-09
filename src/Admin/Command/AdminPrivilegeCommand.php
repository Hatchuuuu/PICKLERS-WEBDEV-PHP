<?php
declare(strict_types=1);

namespace Picklers\Admin\Command;

use Doctrine\DBAL\Connection;
use Picklers\Admin\Repository\AccountRepository;
use Picklers\Admin\Security\Capability;
use Picklers\Admin\Security\RoleMapper;
use Picklers\Admin\Service\AuditContext;
use Picklers\Admin\Service\AuditLog;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Controlled bootstrap of privileged administrators (run by an operator with
 * server access — there is deliberately no web path to the FIRST grant).
 *
 *   php bin/console picklers:admin:privilege list
 *   php bin/console picklers:admin:privilege grant  <email-or-id> --reason="…"
 *   php bin/console picklers:admin:privilege revoke <email-or-id> --reason="…"
 *   php bin/console picklers:admin:privilege matrix
 *
 * The target must already be an active administrator (is_admin = 1). Every
 * change is written to the audit trail with actor "console".
 */
#[AsCommand(name: 'picklers:admin:privilege', description: 'List, grant or revoke privileged administrator access; print the capability matrix.')]
final class AdminPrivilegeCommand extends Command
{
    public function __construct(
        private readonly Connection $db,
        private readonly AccountRepository $accounts,
        private readonly AuditLog $audit,
        private readonly AuditContext $context,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('operation', InputArgument::REQUIRED, 'list | grant | revoke | matrix')
            ->addArgument('user', InputArgument::OPTIONAL, 'Email address or user id')
            ->addOption('reason', null, InputOption::VALUE_REQUIRED, 'Why (recorded in the audit trail)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $op = (string)$input->getArgument('operation');

        if ($op === 'matrix') {
            $rows = [];
            foreach (Capability::MATRIX as $cap => $role) {
                $rows[] = [Capability::LABELS[$cap], $role === 'ROLE_ADMIN' ? 'yes' : 'no', 'yes', $cap];
            }
            $io->table(['Capability', 'Admin', 'Privileged admin', 'Key'], $rows);

            return Command::SUCCESS;
        }
        if ($op === 'list') {
            $rows = $this->db->fetchAllAssociative(
                "SELECT u.id, u.name, u.email, (ap.user_id IS NOT NULL) AS privileged, ap.granted_at
                   FROM users u LEFT JOIN admin_privileges ap ON ap.user_id = u.id
                  WHERE u.is_admin = 1 AND u.role <> 'deleted' ORDER BY privileged DESC, u.name"
            );
            $io->table(['ID', 'Name', 'Email', 'Privileged', 'Granted'], array_map(static fn($r) => [$r['id'], $r['name'], $r['email'], $r['privileged'] ? 'yes' : 'no', $r['granted_at'] ?? '—'], $rows));

            return Command::SUCCESS;
        }
        if (!in_array($op, ['grant', 'revoke'], true)) {
            $io->error('Operation must be list, grant, revoke or matrix.');

            return Command::INVALID;
        }
        $who = trim((string)$input->getArgument('user'));
        $reason = trim((string)$input->getOption('reason'));
        if ($who === '' || mb_strlen($reason) < 5) {
            $io->error('Give the user (email or id) and --reason (at least 5 characters).');

            return Command::INVALID;
        }
        $id = $this->db->fetchOne('SELECT id FROM users WHERE id = ? OR email = ? LIMIT 1', [$who, $who]);
        $user = $id !== false ? $this->accounts->find((string)$id) : null;
        if ($user === null || !RoleMapper::isAdmin($user)) {
            $io->error('No active administrator matches that email/id. Privileged access can only be granted to an existing administrator.');

            return Command::FAILURE;
        }
        $this->context->set('console', 'console (' . get_current_user() . ')', null, null);

        if ($op === 'grant') {
            if ($user['is_privileged']) {
                $io->note("{$user['name']} is already a privileged administrator.");

                return Command::SUCCESS;
            }
            $this->db->transactional(function () use ($user, $reason): void {
                $this->accounts->grantPrivilege((string)$user['id'], null, $reason);
                $this->audit->record('user.privilege.grant', 'user', (string)$user['id'], ['privileged' => ['from' => false, 'to' => true], 'via' => 'console'], $reason);
            });
            $io->success("{$user['name']} <{$user['email']}> is now a privileged administrator.");

            return Command::SUCCESS;
        }

        if (!$user['is_privileged']) {
            $io->note("{$user['name']} is not a privileged administrator.");

            return Command::SUCCESS;
        }
        if ($this->accounts->countActivePrivileged() <= 1) {
            $io->error('This is the last privileged administrator; grant someone else first.');

            return Command::FAILURE;
        }
        $this->db->transactional(function () use ($user, $reason): void {
            $this->accounts->revokePrivilege((string)$user['id']);
            $this->audit->record('user.privilege.revoke', 'user', (string)$user['id'], ['privileged' => ['from' => true, 'to' => false], 'via' => 'console'], $reason);
        });
        $io->success("Privileged access revoked from {$user['name']}.");

        return Command::SUCCESS;
    }
}
