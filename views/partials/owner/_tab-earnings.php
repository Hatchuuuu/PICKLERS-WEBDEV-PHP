      <div class="owner-topbar">
        <div class="owner-topbar-title-wrap">
          <h1>Earnings &amp; Financial Accounting</h1>
          <p>Gross court earnings and disbursement status.</p>
        </div>
        <button type="button" class="btn-walkin-open" onclick="openModal('payoutModal')">
          <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M6 3h7a5.5 5.5 0 0 1 0 11H6z"/><line x1="3" y1="7" x2="18" y2="7"/><line x1="3" y1="11" x2="18" y2="11"/><line x1="6" y1="3" x2="6" y2="21"/></svg>
          <span>Request Payout</span>
        </button>
      </div>

      <!-- 4 Revenue Summary Cards -->
      <div class="kpi-grid-4col">
        <div class="kpi-card">
          <div class="kpi-label">Gross Revenue</div>
          <div class="kpi-val" style="color:#FFFFFF; margin-top:8px;">₱<?php echo number_format($earnings['gross'], 2); ?></div>
          <div style="font-size:12px; color:#94A3B8; margin-top:4px;">Cumulative bookings</div>
        </div>
        <div class="kpi-card">
          <div class="kpi-label">Platform Fee (5%)</div>
          <div class="kpi-val" style="color:#EF4444; margin-top:8px;">-₱<?php echo number_format($earnings['fee_amount'], 2); ?></div>
          <div style="font-size:12px; color:#94A3B8; margin-top:4px;">Transparent service rate</div>
        </div>
        <div class="kpi-card">
          <div class="kpi-label">Net Revenue</div>
          <div class="kpi-val" style="color:#00D98B; margin-top:8px;">₱<?php echo number_format($earnings['net'], 2); ?></div>
          <div style="font-size:12px; color:#00D98B; margin-top:4px;">Net owner earnings</div>
        </div>
        <div class="kpi-card">
          <div class="kpi-label">Available for Payout</div>
          <div class="kpi-val" style="color:#00E5FF; margin-top:8px;">₱<?php echo number_format($earnings['available'], 2); ?></div>
          <div style="font-size:12px; color:#00E5FF; margin-top:4px;">Cleared liquid funds</div>
        </div>
      </div>

      <!-- Settlement Audit Ledger Table -->
      <div class="ledger-table-wrap">
        <div style="padding:18px 24px; border-bottom:1px solid rgba(255,255,255,0.06); display:flex; justify-content:space-between; align-items:center;">
          <h2 style="font-size:16px; font-weight:800; color:#FFFFFF;">Settlement Audit Ledger</h2>
          <span style="font-size:12px; color:#94A3B8;">Real-time automated transaction audit trail</span>
        </div>
        <table class="ledger-table">
          <thead>
            <tr>
              <th>TX ID</th>
              <th>Timestamp</th>
              <th>Court & Player</th>
              <th>Gross</th>
              <th>Platform Fee</th>
              <th>Net Payout</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($earnings['ledger'] as $tx): ?>
              <tr>
                <td style="font-family:monospace; color:#00E5FF;"><?php echo $tx['tx_id']; ?></td>
                <td style="color:#94A3B8;"><?php echo $tx['time']; ?></td>
                <td>
                  <div style="font-weight:700;"><?php echo htmlspecialchars($tx['court']); ?></div>
                  <div style="font-size:11px; color:#94A3B8;"><?php echo htmlspecialchars($tx['player']); ?></div>
                </td>
                <td>₱<?php echo number_format($tx['gross'], 2); ?></td>
                <td style="color:#EF4444;">-₱<?php echo number_format($tx['fee'], 2); ?></td>
                <td style="color:#00D98B; font-weight:800;">₱<?php echo number_format($tx['net'], 2); ?></td>
                <td>
                  <span class="<?php echo $tx['status'] === 'Settled' ? 'status-badge-settled' : 'status-badge-pending'; ?>">
                    <?php echo $tx['status']; ?>
                  </span>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

