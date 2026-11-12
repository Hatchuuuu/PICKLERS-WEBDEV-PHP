<?php
declare(strict_types=1);

namespace Picklers\Tests\Web;

use Picklers\Repositories\WalletRepository;
use Picklers\Tests\Admin\AdminWebTestCase;
use Picklers\Web\Twig\PlayerViewExtension;

final class WalletDisplayTest extends AdminWebTestCase
{
    public function testCancelledLinkedOpenPlayShowsActualMethodAndCompleteCode(): void
    {
        $facility = $this->scalar("SELECT id FROM facilities WHERE owner_id='usr_fx_owner1' LIMIT 1");
        $this->db()->insert('bookings', ['id'=>'PKL-OP-ABC123','user_id'=>'usr_fx_p1','facility_id'=>$facility,'court_name'=>'Open Play','price'=>165,'payment_method'=>'Pickle Credits','status'=>'cancelled']);
        $this->db()->insert('wallet_transactions', ['id'=>'tx_display_op','user_id'=>'usr_fx_p1','type'=>'debit','amount'=>165,'label'=>'Open Play #PKL-OP-ABC123 at Display Arena','booking_id'=>'PKL-OP-ABC123','entry_kind'=>'booking_payment']);
        $this->signIn('usr_fx_p1');
        $crawler = $this->client->request('GET', '/app.php', ['tab'=>'wallet']);
        self::assertResponseIsSuccessful();
        $row = $crawler->filter('.wallet-txn-item')->reduce(fn($node)=>str_contains($node->text(),'Display Arena'));
        self::assertCount(1,$row);
        self::assertSame('Open Play • Display Arena via Pickle Credits',$row->filter('.wallet-txn-title')->text());
        self::assertSame('#PKL-OP-ABC123',$row->filter('.wallet-txn-code')->text());
        self::assertSame(1,(int)$this->scalar("SELECT COUNT(*) FROM wallet_transactions WHERE id='tx_display_op' AND label='Open Play #PKL-OP-ABC123 at Display Arena'"));
    }

    public function testForeignBookingLinkDoesNotSupplyPaymentMethod(): void
    {
        $this->db()->insert('wallet_transactions', ['id'=>'tx_display_foreign','user_id'=>'usr_fx_p1','type'=>'debit','amount'=>10,'label'=>'Booking #FX-CONF02 at Unknown Arena','booking_id'=>'FX-CONF02']);
        $rows=static::getContainer()->get(WalletRepository::class)->getWalletTransactions('usr_fx_p1');
        $tx=array_values(array_filter($rows,fn($row)=>$row['id']==='tx_display_foreign'))[0];
        self::assertArrayNotHasKey('payment_method',$tx);
        self::assertSame('Booked • Unknown Arena',PlayerViewExtension::walletTxView($tx)['title']);
    }

    public function testLegacyLabelRetainsFullCodeAndGenericTransactionsAreUnchanged(): void
    {
        $view=PlayerViewExtension::walletTxView(['id'=>'tx_legacy','type'=>'debit','label'=>'Open Play #PKL-OP-DEF456 at Legacy Arena via Maya']);
        self::assertSame('#PKL-OP-DEF456',$view['code']);
        self::assertSame('Open Play • Legacy Arena via Maya',$view['title']);
        self::assertSame('#PKL-OP-123ABC',PlayerViewExtension::walletTxView(['id'=>'tx_legacy_no_hash','type'=>'debit','label'=>'Open Play PKL-OP-123ABC at Legacy Arena'])['code']);
        self::assertSame('Open Play • Legacy Arena',PlayerViewExtension::walletTxView(['id'=>'tx_unknown','type'=>'debit','label'=>'Open Play #PKL-OP-123ABC at Legacy Arena'])['title']);
        self::assertSame('Booked • Regular Arena via GCash',PlayerViewExtension::walletTxView(['id'=>'tx_regular','type'=>'debit','label'=>'Booking #PKL-ABC123 at Regular Arena via GCash'])['title']);
        self::assertSame('Refund • Open Play',PlayerViewExtension::walletTxView(['id'=>'tx_refund','type'=>'credit','label'=>'Refund Open Play #PKL-OP-ABC123 at Display Arena'])['title']);
        self::assertSame('GCash Top-Up',PlayerViewExtension::walletTxView(['id'=>'tx_topup','type'=>'credit','label'=>'GCash'])['title']);
        self::assertSame('Deduction by Picklers',PlayerViewExtension::walletTxView(['id'=>'tx_admin','type'=>'debit','label'=>'[Admin] Adjustment'])['title']);
        self::assertSame('Opening Balance',PlayerViewExtension::walletTxView(['id'=>'tx_opening','type'=>'credit','label'=>'Opening Balance'])['title']);
    }
}
