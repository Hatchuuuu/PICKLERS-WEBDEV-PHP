<?php
declare(strict_types=1);

namespace Picklers\Tests\Web;

use Picklers\Tests\Admin\AdminWebTestCase;

/**
 * The player/owner API (/api?action=…) and owner portal writes (POST owner.php)
 * served by Symfony with the legacy JSON contract that app.js/owner.js use.
 */
final class ApiTest extends AdminWebTestCase
{
    private const TOKEN = 'web-test-token';

    /** @return array{status:int,json:array<string,mixed>} */
    private function api(string $action, array $params = [], string $method = 'POST', ?string $csrf = self::TOKEN, string $uri = '/api.php'): array
    {
        $_SESSION['_sf2_attributes']['_csrf/picklers'] = self::TOKEN;
        $query = $method === 'GET' ? ['action' => $action] + $params : ['action' => $action];
        $body = $method === 'GET' ? [] : $params;
        $this->client->request($method, $uri . '?' . http_build_query($query), $body, [], array_filter([
            'HTTP_X_CSRF_TOKEN' => $csrf,
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
        ]));
        $r = $this->client->getResponse();

        return ['status' => $r->getStatusCode(), 'json' => json_decode((string)$r->getContent(), true) ?? [], 'response' => $r];
    }

    private function owner(string $action, array $params = [], ?string $csrf = self::TOKEN): array
    {
        return $this->api($action, $params, 'POST', $csrf, '/owner.php');
    }

    private function facilityId(string $name = 'Fixture Arena Dumaguete'): string
    {
        return (string)$this->scalar('SELECT id FROM facilities WHERE name = ?', [$name]);
    }

    public function testSyncForGuestsAndPlayers(): void
    {
        $guest = $this->api('sync', [], 'GET');
        self::assertTrue($guest['json']['success']);
        self::assertArrayHasKey('versions', $guest['json']);
        self::assertArrayNotHasKey('account', $guest['json'], 'nothing personal for a guest');

        $this->signIn('usr_fx_p1');
        $me = $this->api('sync', [], 'GET');
        self::assertArrayHasKey('account', $me['json']);
        self::assertArrayHasKey('unread_notifications', $me['json']);
    }

    public function testUnknownActionAndCsrf(): void
    {
        self::assertSame(400, $this->api('no_such_action', [], 'GET')['status']);
        $this->signIn('usr_fx_p1');
        $r = $this->api('send_message', ['content' => 'Hello'], 'POST', 'forged');
        self::assertSame(403, $r['status']);
        self::assertSame('CSRF security verification failed.', $r['json']['message']);
        // Mutating by action even over GET: a crafted link cannot send a message.
        self::assertSame(403, $this->api('send_message', ['partner_id' => 'usr_fx_p2', 'content' => 'Hello'], 'GET', null)['status']);
    }

    public function testReadingOneNotificationIsScopedToItsOwner(): void
    {
        foreach (['notice_one' => 'usr_fx_p1', 'notice_two' => 'usr_fx_p1', 'notice_other' => 'usr_fx_p2'] as $id => $userId) {
            $this->db()->insert('notifications', ['id' => $id, 'user_id' => $userId, 'title' => 'Notice', 'body' => 'Details', 'type' => 'system', 'is_read' => 0, 'created_at' => date('Y-m-d H:i:s')]);
        }
        $this->signIn('usr_fx_p1');
        self::assertSame(403, $this->api('mark_notifications_read', ['id' => 'notice_one'], 'POST', 'forged')['status']);
        self::assertSame(400, $this->api('mark_notifications_read', ['id' => []])['status']);
        self::assertTrue($this->api('mark_notifications_read', ['id' => 'notice_one'])['json']['success']);
        self::assertSame(1, (int)$this->scalar("SELECT is_read FROM notifications WHERE id = 'notice_one'"));
        self::assertSame(0, (int)$this->scalar("SELECT is_read FROM notifications WHERE id = 'notice_two'"));
        $this->api('mark_notifications_read', ['id' => 'notice_other']);
        self::assertSame(0, (int)$this->scalar("SELECT is_read FROM notifications WHERE id = 'notice_other'"));
        self::assertTrue($this->api('mark_notifications_read')['json']['success']);
        self::assertSame(1, (int)$this->scalar("SELECT is_read FROM notifications WHERE id = 'notice_two'"));
        self::assertSame(0, (int)$this->scalar("SELECT is_read FROM notifications WHERE id = 'notice_other'"));
    }

    public function testBookingIsPricedServerSideAndValidated(): void
    {
        $fac = $this->facilityId();
        $date = (new \DateTimeImmutable('+3 days'))->format('Y-m-d');
        self::assertSame(401, $this->api('book_court', ['facility_id' => $fac])['status'], 'guests cannot book');

        $this->signIn('usr_fx_p1');
        $base = ['facility_id' => $fac, 'court_id' => "crt_{$fac}_1", 'court_name' => 'Court 1', 'date' => $date, 'payment_method' => 'Pickle Credits', 'price' => '1'];
        self::assertSame('Unsupported payment method.', $this->api('book_court', ['payment_method' => 'Bitcoin', 'time' => '9:00 AM - 10:00 AM'] + $base)['json']['message']);
        self::assertSame(400, $this->api('book_court', ['time' => '9:00 AM - 11:00 AM', 'duration' => 1] + $base)['status'], 'slot length must match the paid duration');

        $quote = $this->api('quote_booking', ['facility_id' => $fac, 'court_id' => "crt_{$fac}_1", 'court_name' => 'Court 1', 'duration' => 1], 'GET');
        self::assertTrue($quote['json']['success']);
        $r = $this->api('book_court', ['time' => '9:00 AM - 10:00 AM', 'duration' => 1] + $base);
        self::assertTrue($r['json']['success'], json_encode($r['json']));
        self::assertSame((float)$quote['json']['quote']['total'], (float)$r['json']['quote']['total'], 'charged what was quoted, never the client price');
        $tx = $this->db()->fetchAssociative('SELECT * FROM wallet_transactions WHERE booking_id = ?', [$r['json']['booking']['id']]);
        self::assertSame('booking_payment', $tx['entry_kind']);
        self::assertSame('debit', $tx['type']);
        self::assertSame(number_format(1200 - (float)$quote['json']['quote']['total'], 2, '.', ''), $tx['balance_after']);
        self::assertSame($this->scalar("SELECT wallet_balance FROM users WHERE id = 'usr_fx_p1'"), $tx['balance_after']);

        $bookings = $this->api('bookings', [], 'GET');
        self::assertTrue($bookings['json']['success']);
        self::assertNotEmpty($bookings['json']['bookings']);
    }

    public function testDecimalTopUpsRecordResultingBalances(): void
    {
        $wallet = static::getContainer()->get(\Picklers\Repositories\WalletRepository::class);
        foreach ([['0.10', '80.60'], ['0.20', '80.80']] as [$amount, $after]) {
            self::assertTrue($wallet->topUpWallet('usr_fx_p2', $amount, 'Ledger test ' . $amount)['success']);
            $tx = $this->db()->fetchAssociative('SELECT * FROM wallet_transactions WHERE user_id = ? AND label = ?', ['usr_fx_p2', 'Ledger test ' . $amount]);
            self::assertSame('top_up', $tx['entry_kind']);
            self::assertSame('credit', $tx['type']);
            self::assertSame($amount, $tx['amount']);
            self::assertSame($after, $tx['balance_after']);
            self::assertSame($after, $this->scalar("SELECT wallet_balance FROM users WHERE id = 'usr_fx_p2'"));
        }
    }

    public function testWalletAndDisabledTopUp(): void
    {
        self::assertFalse($this->api('wallet', [], 'GET')['json']['success']);
        $this->signIn('usr_fx_p1');
        $wallet = $this->api('wallet', [], 'GET');
        self::assertEquals(1200, $wallet['json']['balance']);
        // No gateway: a top-up either fails closed or is explicitly marked simulated
        // (PAYMENTS_SIMULATED outside production).
        $topUp = $this->api('top_up', ['amount' => 500]);
        if ($topUp['status'] === 503) {
            self::assertSame('1200.00', (string)$this->scalar("SELECT wallet_balance FROM users WHERE id = 'usr_fx_p1'"));
        } else {
            self::assertTrue($topUp['json']['simulated'] ?? false, json_encode($topUp['json']));
        }
    }

    public function testMessages(): void
    {
        $this->signIn('usr_fx_p1');
        self::assertSame(404, $this->api('send_message', ['partner_id' => 'usr_fx_p1', 'content' => 'me'])['status'], 'not to yourself');
        self::assertTrue($this->api('send_message', ['partner_id' => 'usr_fx_p2', 'content' => 'Game tomorrow?'])['json']['success']);
        $thread = $this->api('messages', ['partner_id' => 'usr_fx_p2'], 'GET');
        self::assertSame('usr_fx_p2', $thread['json']['partner']['id']);
        self::assertNotEmpty($thread['json']['messages']);
    }

    public function testOwnerApprovesAndDeclinesOnlyTheirOwnRequests(): void
    {
        $this->signIn('usr_fx_p1');
        self::assertSame(403, $this->api('approve_booking', ['booking_id' => 'FX-PEND01'])['status'], 'players are not owners');

        $this->signIn('usr_fx_owner2');
        self::assertSame(403, $this->api('approve_booking', ['booking_id' => 'FX-PEND01'])['status'], "another venue's booking");

        $this->signIn('usr_fx_owner1');
        $pending = $this->api('get_pending_requests', [], 'GET');
        self::assertContains('FX-PEND01', array_column($pending['json']['requests'], 'id'));
        $ok = $this->api('approve_booking', ['booking_id' => 'FX-PEND01']);
        self::assertTrue($ok['json']['success'], json_encode($ok['json']));
        self::assertSame('confirmed', $this->scalar("SELECT status FROM bookings WHERE id = 'FX-PEND01'"));

        $declined = $this->api('decline_booking', ['booking_id' => 'FX-PEND02', 'reason' => 'Court maintenance']);
        self::assertTrue($declined['json']['success']);
        self::assertFalse($declined['json']['refunded'], 'a GCash payment is never "refunded" in-app');
        self::assertTrue($declined['json']['external_payment']);
        self::assertSame('0.00', $declined['json']['refund_amount']);
        self::assertStringContainsString('No refund was issued in-app', $declined['json']['message']);
        self::assertSame('cancelled', $this->scalar("SELECT status FROM bookings WHERE id = 'FX-PEND02'"));
        self::assertSame(1, (int)$this->scalar("SELECT COUNT(*) FROM notifications WHERE user_id = 'usr_fx_p2' AND body LIKE '%Reason: Court maintenance.%'"));
    }

    public function testOwnerDeclineReportsWalletAndPayAtVenueOutcomes(): void
    {
        $this->signIn('usr_fx_owner1');
        $this->db()->update('bookings', ['payment_method' => 'Pay at Venue'], ['id' => 'FX-PEND01']);
        $venue = $this->api('decline_booking', ['booking_id' => 'FX-PEND01']);
        self::assertTrue($venue['json']['success']);
        self::assertFalse($venue['json']['refunded']);
        self::assertFalse($venue['json']['external_payment']);
        self::assertStringContainsString('Nothing was charged', $venue['json']['message']);

        $this->db()->update('bookings', ['payment_method' => 'Pickle Credits', 'price' => '500.00'], ['id' => 'FX-PEND02']);
        $wallet = $this->api('decline_booking', ['booking_id' => 'FX-PEND02']);
        self::assertTrue($wallet['json']['success']);
        self::assertTrue($wallet['json']['refunded']);
        self::assertSame('500.00', $wallet['json']['refund_amount']);
        self::assertStringContainsString('₱500.00 refunded to Pickle Credits', $wallet['json']['message']);
    }

    public function testOwnerDeclineRejectsOverlongReasonWithoutCancelling(): void
    {
        $this->signIn('usr_fx_owner1');
        self::assertSame(400, $this->api('decline_booking', ['booking_id' => 'FX-PEND01', 'reason' => str_repeat('x', 501)])['status']);
        self::assertSame('pending', $this->scalar("SELECT status FROM bookings WHERE id = 'FX-PEND01'"));
    }

    public function testOwnerDeclineDoesNotReportSuccessWhenTransitionIsRejected(): void
    {
        $this->signIn('usr_fx_owner1');
        $this->db()->update('bookings', ['status' => 'declined'], ['id' => 'FX-PEND01']);
        $result = $this->api('decline_booking', ['booking_id' => 'FX-PEND01']);
        self::assertSame(409, $result['status']);
        self::assertFalse($result['json']['success']);
        self::assertSame('declined', $this->scalar("SELECT status FROM bookings WHERE id = 'FX-PEND01'"));
    }

    public function testCheckinOnlyForTodaysConfirmedPassesAtYourVenue(): void
    {
        $this->signIn('usr_fx_owner1');
        self::assertSame(404, $this->api('verify_checkin', ['code' => 'NOPE'], 'GET')['status']);
        self::assertSame(409, $this->api('verify_checkin', ['code' => 'PICKLERS:FX-PEND01:x'], 'GET')['status'], 'pending is not checked in');
        $future = $this->api('verify_checkin', ['code' => 'FX-CONF01'], 'GET');
        self::assertSame(409, $future['status']);
        self::assertStringContainsString('not today', $future['json']['message']);
        self::assertSame(403, $this->api('verify_checkin', ['code' => 'FX-CONF03'], 'GET')['status'], 'another venue');
    }

    public function testUserSearchIsOwnerOnly(): void
    {
        self::assertSame(401, $this->api('search_users', ['q' => 'pa'], 'GET')['status']);
        $this->signIn('usr_fx_owner1');
        $found = $this->api('search_users', ['q' => 'paula'], 'GET');
        self::assertSame('usr_fx_p1', $found['json']['users'][0]['id']);
        self::assertNotContains('usr_fx_gone', array_column($found['json']['users'], 'id'));
    }

    public function testTournamentLifecycleIsOwnerScoped(): void
    {
        $this->signIn('usr_fx_owner1');
        $created = $this->api('create_tournament', ['title' => 'Fixture Open', 'category' => 'Singles', 'max_teams' => 4]);
        self::assertTrue($created['json']['success'], json_encode($created['json']));
        $id = $created['json']['tournament']['id'];
        foreach (['Ana', 'Ben'] as $name) {
            $r = $this->api('add_tournament_entrant', ['tournament_id' => $id, 'name' => $name, 'player1' => $name]);
            self::assertTrue($r['json']['success'], json_encode($r['json']));
        }
        $before = $this->api('get_tournament', ['tournament_id' => $id], 'GET')['json']['tournament'];
        $teamId = $before['teams'][0]['id'];
        $invalid = $this->api('update_tournament_team', ['tournament_id' => $id, 'team_id' => $teamId, 'player1' => '']);
        self::assertSame(422, $invalid['status']);
        self::assertFalse($invalid['json']['success']);
        self::assertSame($before, $this->api('get_tournament', ['tournament_id' => $id], 'GET')['json']['tournament']);
        $renamed = $this->api('update_tournament_team', ['tournament_id' => $id, 'team_id' => $teamId, 'name' => 'Renamed Ana']);
        self::assertTrue($renamed['json']['success']);
        self::assertSame('Ana', $renamed['json']['tournament']['teams'][0]['player1']);
        self::assertTrue($this->api('generate_tournament_bracket', ['tournament_id' => $id])['json']['success']);
        self::assertTrue($this->api('reset_tournament_bracket', ['tournament_id' => $id])['json']['success']);
        self::assertContains($id, array_column(array_merge(...array_values($this->api('list_tournaments', [], 'GET')['json']['tournaments'])), 'id'));

        $this->signIn('usr_fx_owner2');
        self::assertSame(404, $this->api('get_tournament', ['tournament_id' => $id], 'GET')['status'], "another owner's tournament is not confirmed");
        self::assertSame(404, $this->api('delete_tournament', ['tournament_id' => $id])['status']);

        $this->signIn('usr_fx_owner1');
        self::assertTrue($this->api('delete_tournament', ['tournament_id' => $id])['json']['success']);
    }

    public function testOwnerPortalWrites(): void
    {
        $fac = $this->facilityId();

        $this->signIn('usr_fx_p1');
        $r = $this->owner('add_court', ['name' => 'Court 9']);
        self::assertSame(302, $r['status']);
        self::assertStringEndsWith('/owner-application.php?notice=verification_required', (string)$r['response']->headers->get('Location'));

        $this->signIn('usr_fx_owner1');
        self::assertSame(403, $this->owner('add_court', ['name' => 'Court 9'], 'forged')['status']);
        self::assertSame(400, $this->owner('no_such_action')['status']);

        $added = $this->owner('add_court', ['name' => 'Court 9', 'surface' => 'Hard Court', 'rate' => 320]);
        self::assertTrue($added['json']['success'], json_encode($added['json']));
        self::assertSame(409, $this->owner('add_court', ['name' => 'Court 9', 'surface' => 'Hard Court'])['status'], 'no duplicate court numbers');
        self::assertSame(400, $this->owner('edit_court', ['court_id' => "crt_{$fac}_1", 'name' => 'Center Court'])['status'], 'names keep a number');

        $hosted = $this->owner('host_open_play', ['court_name' => 'Court 2', 'date' => 'Everyday', 'start_time' => '6:00 PM', 'end_time' => '9:00 PM', 'capacity' => 8, 'fee' => 150]);
        self::assertTrue($hosted['json']['success'], json_encode($hosted['json']));
        self::assertSame(400, $this->owner('toggle_court_status', ['court_id' => "crt_{$fac}_2", 'name' => 'Court 2', 'active' => 0])['status'], 'a court hosting Open Play stays enabled');
        self::assertTrue($this->owner('cancel_open_play', ['court_name' => 'Court 2', 'match_id' => (string)$hosted['json']['session']['id']])['json']['success']);

        self::assertSame(400, $this->owner('add_staff', ['name' => 'Desk', 'email' => 'not-an-email'])['status']);
        self::assertSame(400, $this->owner('request_payout', ['amount' => 10, 'method' => 'GCash', 'account_name' => 'O', 'account_number' => '09171234567'])['status'], 'below the minimum payout');

        $this->signIn('usr_fx_owner2');
        self::assertSame(403, $this->owner('edit_court', ['court_id' => "crt_{$fac}_1", 'name' => 'Court 1'])['status'], "another owner's court");
    }
}
