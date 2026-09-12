<?php
declare(strict_types=1);

namespace Picklers\Repositories;

use Picklers\Core\Database;

class WalletRepository {
    private Database $db;

    public function __construct(?Database $db = null) {
        $this->db = $db ?? Database::get();
    }

    public function getTransactions(string $userId): array {
        return $this->db->getWalletTransactions($userId);
    }

    public function addTransaction(string $userId, string $type, float $amount, string $label): array {
        return $this->db->addTransaction($userId, $type, $amount, $label);
    }

    public function topUp(string $userId, float $amount, string $method): array {
        return $this->db->topUpWallet($userId, $amount, $method);
    }
}
