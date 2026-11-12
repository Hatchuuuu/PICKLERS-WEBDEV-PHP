<?php
declare(strict_types=1);

namespace Picklers\Tests;

use Doctrine\DBAL\Connection;
use Picklers\Repositories\OwnerRepository;

final class PayoutCollectionTest extends TestCase
{
    public function run(): void
    {
        $db = $this->service(Connection::class);
        $owners = $this->service(OwnerRepository::class);
        $tag = bin2hex(random_bytes(4));
        $db->beginTransaction();
        try {
            $db->insert('facilities', ['owner_id' => 'payout_test', 'name' => 'Payout collection test', 'location' => 'Synthetic']);
            $facility = (string)$db->lastInsertId();
            $booking = 'payout_collection_' . $tag;
            $db->insert('bookings', ['id' => $booking, 'user_id' => 'payout_player', 'facility_id' => $facility,
                'status' => 'confirmed', 'price' => '1000.00', 'payment_method' => 'Pay at Venue',
                'booking_date' => date('Y-m-d', strtotime('-2 days')), 'start_min' => 540, 'end_min' => 600]);
            foreach (['Pay at Venue', 'GCash', 'Maya', 'Card', 'Pickle Credits'] as $method) {
                $db->update('bookings', ['payment_method' => $method], ['id' => $booking]);
                $money = $owners->getOwnerFinancials($facility);
                $this->assertEquals(1000.0, $money['gross'], 'Gross reporting retains ' . $method . ' bookings');
                $this->assertEquals(0.0, $money['available'], 'Uncollected ' . $method . ' cannot fund a payout');
            }
            $db->insert('wallet_transactions', ['id' => 'tx_payout_' . $tag, 'user_id' => 'payout_player', 'type' => 'debit',
                'amount' => '1000.00', 'label' => 'Synthetic booking payment', 'entry_kind' => 'booking_payment', 'booking_id' => $booking]);
            $this->assertEquals(760.0, $owners->getOwnerFinancials($facility)['available'], 'Collected ended session retains 5% fee and 20% reserve');
            $db->update('bookings', ['booking_date' => date('Y-m-d', strtotime('+2 days'))], ['id' => $booking]);
            $this->assertEquals(0.0, $owners->getOwnerFinancials($facility)['available'], 'Future refundable session is not withdrawable');
            $db->update('bookings', ['status' => 'completed'], ['id' => $booking]);
            $this->assertEquals(0.0, $owners->getOwnerFinancials($facility)['available'], 'Premature completion does not bypass settlement time');
            $db->update('bookings', ['booking_date' => date('Y-m-d', strtotime('-2 days'))], ['id' => $booking]);
            $db->insert('wallet_transactions', ['id' => 'tx_refund_' . $tag, 'user_id' => 'payout_player', 'type' => 'credit',
                'amount' => '1000.00', 'label' => 'Synthetic refund', 'entry_kind' => 'refund', 'booking_id' => $booking]);
            $this->assertEquals(0.0, $owners->getOwnerFinancials($facility)['available'], 'Refunded collections cannot fund payouts');
            $db->delete('wallet_transactions', ['id' => 'tx_refund_' . $tag]);
            $db->update('wallet_transactions', ['user_id' => 'another_player'], ['id' => 'tx_payout_' . $tag]);
            $this->assertEquals(0.0, $owners->getOwnerFinancials($facility)['available'], 'Another user debit is not collection evidence');
            $db->update('wallet_transactions', ['user_id' => 'payout_player', 'amount' => '999.00'], ['id' => 'tx_payout_' . $tag]);
            $this->assertEquals(0.0, $owners->getOwnerFinancials($facility)['available'], 'Partial debit cannot fund the full booking');
            $db->update('wallet_transactions', ['amount' => '1000.00'], ['id' => 'tx_payout_' . $tag]);
            $db->insert('payout_requests', ['id' => 'pay_test_' . $tag, 'owner_id' => 'payout_test', 'facility_id' => $facility,
                'amount' => '100.00', 'method' => 'GCash', 'status' => 'pending']);
            $this->assertEquals(660.0, $owners->getOwnerFinancials($facility)['available'], 'Earlier requests remain deducted');
        } finally {
            $db->rollBack();
        }
    }
}
