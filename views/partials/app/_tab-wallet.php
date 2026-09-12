<?php if ($activeTab === 'wallet'): ?>
  <?php
    $allTransactions = $db->getWalletTransactions($currentUser['id'] ?? '');
    $totalCredits = floatval($currentUser['wallet_balance'] ?? 0);
    $totalSpent = 0;
    $totalTopups = 0;
    foreach ($allTransactions as $t) {
        $amt = floatval($t['amount'] ?? 0);
        if (($t['type'] ?? '') === 'debit') {
            $totalSpent += $amt;
        } elseif (($t['type'] ?? '') === 'credit' && stripos($t['label'] ?? '', 'refund') === false) {
            $totalTopups += $amt;
        }
    }
  ?>
  <div style="width: 100%;">
    <!-- Header -->
    <div style="margin-bottom: 24px;">
      <h1 style="font-family: 'Montserrat', var(--font-heading), sans-serif; font-size: 26px; font-weight: 800; color: #FFFFFF; margin: 0 0 6px;">Wallet &amp; Credits</h1>
      <p style="font-size: 13px; color: #94A3B8; margin: 0;">Manage your Pickle Credits, instant top-ups, and transaction history.</p>
    </div>

    <!-- Balance Card (Clean & Minimalist Hero) -->
    <div class="wallet-balance-card">
      <div class="wallet-balance-main-row">
        <div class="wallet-balance-info-col">
          <div class="wallet-balance-num">₱<?php echo number_format($totalCredits); ?></div>
          <div class="wallet-balance-header-tag">
            <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12V7H5a2 2 0 0 1 0-4h14v4"/><path d="M3 5v14a2 2 0 0 0 2 2h16v-5"/><path d="M18 12a2 2 0 0 0 0 4h4v-4Z"/></svg>
            <span>Available Pickle Credits</span>
          </div>
        </div>

        <button type="button" class="wallet-topup-card-btn" onclick="openModal('topUpModal')">
          <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
          <span>Top Up</span>
        </button>
      </div>
    </div>

    <!-- Transactions Container -->
    <div class="wallet-ledger-box">
      <div class="wallet-ledger-header">
        <h3 style="font-family: 'Montserrat', var(--font-heading), sans-serif; font-size: 22px; font-weight: 800; color: #FFFFFF; margin: 0; letter-spacing: -0.015em;">Transactions</h3>

        <!-- Filter Pills Bar -->
        <div class="wallet-filter-pills">
          <button type="button" class="wallet-filter-btn active" id="btnFilterTxnAll" onclick="filterWalletTxns('all')">All</button>
          <button type="button" class="wallet-filter-btn" id="btnFilterTxnDeposits" onclick="filterWalletTxns('deposits')">Top-Ups</button>
          <button type="button" class="wallet-filter-btn" id="btnFilterTxnBookings" onclick="filterWalletTxns('bookings')">Bookings</button>
          <button type="button" class="wallet-filter-btn" id="btnFilterTxnRefunds" onclick="filterWalletTxns('refunds')">Refunds</button>
        </div>
      </div>

      <!-- Empty state when filter matches 0 rows -->
      <div id="walletFilteredEmptyState" style="display: none; padding: 48px 20px; text-align: center;">
        <div style="width: 52px; height: 52px; border-radius: 16px; background: rgba(255, 255, 255, 0.04); border: 1px solid rgba(255, 255, 255, 0.08); display: inline-flex; align-items: center; justify-content: center; margin-bottom: 14px; color: var(--pk-text-muted, #94A3B8);">
          <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        </div>
        <h4 style="font-family: 'Montserrat', var(--font-heading), sans-serif; font-size: 15px; font-weight: 700; color: #FFFFFF; margin: 0 0 4px; letter-spacing: -0.01em;">No Transactions in this Category</h4>
        <p style="font-size: 12.5px; color: var(--pk-text-muted, #94A3B8); margin: 0;">Try selecting a different filter above.</p>
      </div>

      <!-- Transactions List -->
      <div id="walletTxnListContainer">
        <?php if (empty($allTransactions)): ?>
          <div style="padding: 48px 20px; text-align: center;">
            <div style="width: 52px; height: 52px; border-radius: 16px; background: rgba(0, 217, 139, 0.08); border: 1px solid rgba(0, 217, 139, 0.15); display: inline-flex; align-items: center; justify-content: center; margin-bottom: 14px; color: #00D98B;">
              <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12V7H5a2 2 0 0 1 0-4h14v4"/><path d="M3 5v14a2 2 0 0 0 2 2h16v-5"/><path d="M18 12a2 2 0 0 0 0 4h4v-4Z"/></svg>
            </div>
            <h4 style="font-family: 'Montserrat', var(--font-heading), sans-serif; font-size: 15px; font-weight: 700; color: #FFFFFF; margin: 0 0 4px; letter-spacing: -0.01em;">No Transactions Yet</h4>
            <p style="font-size: 12.5px; color: var(--pk-text-muted, #94A3B8); margin: 0;">Your court reservations and credit deposits will appear here.</p>
          </div>
        <?php else: ?>
          <?php foreach ($allTransactions as $t):
            $isCredit = ($t['type'] ?? '') === 'credit';
            $rawLabel = trim($t['label'] ?? 'Transaction');
            $isRefund = stripos($rawLabel, 'refund') !== false;
            $isOpenPlay = stripos($rawLabel, 'open play') !== false || stripos($rawLabel, 'match') !== false;
            $isBooking = stripos($rawLabel, 'booking') !== false;
            $isTopUp = $isCredit && !$isRefund;
            
            $catType = $isRefund ? 'refunds' : ($isTopUp ? 'deposits' : 'bookings');

            // Format clean title based on transaction type
            if ($isOpenPlay) {
                $cleanTitle = 'Open Play';
            } elseif ($isBooking) {
                $cleanTitle = 'Booked';
            } elseif ($isRefund) {
                $cleanTitle = 'Refund';
            } else {
                // Payment method / Top Up name (e.g. GCash, Maya, BDO)
                $cleanTitle = trim(preg_replace('/\s*(?:Wallet\s*)?Top[- ]?Up\b/i', '', $rawLabel));
                $cleanTitle = trim(preg_replace('/\bWallet\s+/i', '', $cleanTitle));
                if (empty($cleanTitle)) {
                    $cleanTitle = 'GCash';
                }
            }

            // Extract booking code from label if present, otherwise generate stable code
            if (preg_match('/#(PKL-?[A-Z0-9]+)/i', $rawLabel, $matches)) {
                $txCode = '#' . strtoupper(str_replace('#', '', $matches[1]));
                if (!str_starts_with($txCode, '#PKL-') && str_starts_with($txCode, '#PKL')) {
                    $txCode = '#PKL-' . substr($txCode, 4);
                }
            } elseif (preg_match('/\b(PKL-[A-Z0-9]+)\b/i', $rawLabel, $matches)) {
                $txCode = '#' . strtoupper($matches[1]);
            } else {
                $txCode = '#PKL-' . strtoupper(substr(md5($rawLabel . ($t['date'] ?? '') . ($t['id'] ?? '')), 0, 6));
            }
          ?>
            <div class="wallet-txn-item" data-category="<?php echo $catType; ?>">
              <div class="wallet-txn-row-top">
                <div class="wallet-txn-title"><?php echo htmlspecialchars($cleanTitle); ?></div>
                <div class="wallet-txn-amt <?php echo $isCredit ? 'credit' : 'debit'; ?>">
                  <?php echo $isCredit ? '+₱' : '-₱'; ?><?php echo number_format(floatval($t['amount'] ?? 0)); ?>
                </div>
              </div>
              <div class="wallet-txn-row-bottom">
                <div class="wallet-txn-code"><?php echo htmlspecialchars($txCode); ?></div>
                <div class="wallet-txn-date"><?php echo strtoupper(htmlspecialchars($t['date'] ?? 'Recent')); ?></div>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
<?php endif; ?>
