<?php
declare(strict_types=1);

namespace Picklers\Tests\Web;

use PHPUnit\Framework\TestCase;
use Picklers\Domain\Payments;
use Picklers\Web\Twig\PlayerViewExtension;

final class PaymentLabelsTest extends TestCase
{
    public function testMethodSelectionNeverClaimsAnExternalPaymentWasCollected(): void
    {
        foreach ([
            ['GCash', 'GCash (manual payment)'],
            ['Paid via Maya', 'Maya (manual payment)'],
            ['Pickle Credits', 'Pickle Credits'],
            ['pay at venue', 'Pay at Venue'],
            ['', 'Payment method not recorded'],
        ] as [$method, $label]) {
            self::assertSame($label, Payments::methodLabel($method));
            self::assertSame($label, PlayerViewExtension::bookingTypeDetails(['payment_method' => $method])['payment_label']);
        }
    }
}
