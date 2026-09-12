<?php
declare(strict_types=1);

namespace Picklers\Services;

use Picklers\Repositories\WalletRepository;

class WalletService {
    private WalletRepository $walletRepo;

    public function __construct(?WalletRepository $walletRepo = null) {
        $this->walletRepo = $walletRepo ?? new WalletRepository();
    }

    public function getWalletTransactions(string $userId): array {
        return $this->walletRepo->getTransactions($userId);
    }

    public function addTransaction(string $userId, string $type, float $amount, string $label): array {
        return $this->walletRepo->addTransaction($userId, $type, $amount, $label);
    }

    public function topUpWallet(string $userId, float $amount, string $method): array {
        return $this->walletRepo->topUp($userId, $amount, $method);
    }
}
