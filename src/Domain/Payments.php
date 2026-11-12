<?php
declare(strict_types=1);

namespace Picklers\Domain;

final class Payments
{
    /** Largest amount (₱) any single booking, rate or fee may charge. */
    public const MAX_PAYABLE = 100000.0;

    public static function methodLabel(?string $method): string
    {
        $method = trim((string)preg_replace('/^paid via\s+/i', '', trim($method ?? '')));
        return match (strtolower($method)) {
            'pickle credits' => 'Pickle Credits',
            'pay at venue' => 'Pay at Venue',
            '' => 'Payment method not recorded',
            default => $method . ' (manual payment)',
        };
    }
}
