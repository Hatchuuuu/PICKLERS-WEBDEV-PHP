<?php
declare(strict_types=1);

namespace Picklers\Models;

use Picklers\Services\WalletService;

class Wallet {
    private WalletService $walletService;

    public function __construct(?WalletService $walletService = null) {
        $this->walletService = $walletService ?? new WalletService();
    }

    public function getTransactions(string $userId): array {
        return $this->walletService->getWalletTransactions($userId);
    }

    public function addTransaction(string $userId, string $type, float $amount, string $label): array {
        return $this->walletService->addTransaction($userId, $type, $amount, $label);
    }

    public function topUp(string $userId, float $amount, string $method): array {
        return $this->walletService->topUpWallet($userId, $amount, $method);
    }
}
