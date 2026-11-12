<?php
declare(strict_types=1);

namespace Picklers\Tests\Web;

use Picklers\Tests\Admin\AdminWebTestCase;

final class PayoutAuthorizationTest extends AdminWebTestCase
{
    public function testStaffAndOwnersStaffingAnotherVenueCannotRequestItsPayout(): void
    {
        $facility = (string)$this->scalar("SELECT id FROM facilities WHERE owner_id = 'usr_fx_owner1' LIMIT 1");
        foreach (['usr_fx_p1', 'usr_fx_owner2'] as $user) {
            $this->db()->insert('staff', ['id' => 'payout_' . $user, 'facility_id' => $facility, 'user_id' => $user, 'name' => 'Desk', 'role' => 'Front Desk', 'status' => 'Active']);
            $this->signIn($user);
            $before = $this->db()->fetchAllAssociative('SELECT * FROM payout_requests ORDER BY id');
            foreach ([['facility_id' => $facility], $this->validRequest($facility)] as $params) {
                self::assertSame(403, $this->requestPayout($params), 'Payout ownership is checked before payment validation.');
                self::assertSame($before, $this->db()->fetchAllAssociative('SELECT * FROM payout_requests ORDER BY id'));
            }
        }
    }

    public function testOwnerAndAdministratorCanStillRequestPayouts(): void
    {
        $facility = (string)$this->scalar("SELECT id FROM facilities WHERE owner_id = 'usr_fx_owner1' LIMIT 1");
        // Record the completed fixture's existing payment with its stable booking identity.
        $this->db()->update('wallet_transactions', ['booking_id' => 'FX-DONE01', 'entry_kind' => 'booking_payment'], ['id' => 'fx_tx02']);
        foreach (['usr_fx_owner1', 'usr_fx_admin'] as $user) {
            $this->signIn($user);
            self::assertSame(400, $this->requestPayout(['amount' => 10] + $this->validRequest($facility)));
            self::assertSame(200, $this->requestPayout($this->validRequest($facility)));
            self::assertSame(1, (int)$this->scalar('SELECT COUNT(*) FROM payout_requests WHERE owner_id = ? AND facility_id = ? AND amount = 100', [$user, $facility]));
        }
    }

    private function validRequest(string $facility): array
    {
        return ['facility_id' => $facility, 'amount' => 100, 'method' => 'GCash', 'account_name' => 'Recipient', 'account_number' => '09171234567'];
    }

    private function requestPayout(array $params): int
    {
        $_SESSION['_sf2_attributes']['_csrf/picklers'] = 'payout-policy';
        $this->client->request('POST', '/owner.php?action=request_payout', $params, [], ['HTTP_X_CSRF_TOKEN' => 'payout-policy', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        return $this->client->getResponse()->getStatusCode();
    }
}
