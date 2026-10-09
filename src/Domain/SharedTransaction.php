<?php
declare(strict_types=1);

namespace Picklers\Domain;

/**
 * Transaction and schema helpers for rules that run both inside Symfony
 * (Doctrine-managed transactions) and from the legacy layer, whose callers may
 * already hold a raw PDO transaction on the same handle.
 *
 * Expects a `private readonly \Doctrine\DBAL\Connection $db` property.
 */
trait SharedTransaction
{
    /** @var array<string,bool> */
    private array $columnCache = [];

    /** Begin a transaction unless the caller already holds one; true when this call owns it. */
    private function beginOwnTransaction(): bool
    {
        if ($this->inTransaction()) {
            return false;
        }
        $this->db->beginTransaction();

        return true;
    }

    private function inTransaction(): bool
    {
        if ($this->db->isTransactionActive()) {
            return true;
        }
        $native = $this->db->getNativeConnection();

        return $native instanceof \PDO && $native->inTransaction();
    }

    private function rollBackOwn(bool $owns): void
    {
        if ($owns && $this->db->isTransactionActive()) {
            $this->db->rollBack();
        }
    }

    /** Whether a column exists (memoised). Lets columns stay optional until their migration has run. */
    private function hasColumn(string $table, string $column): bool
    {
        return $this->columnCache[$table . '.' . $column] ??= (int)$this->db->fetchOne(
            'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column]
        ) > 0;
    }
}
