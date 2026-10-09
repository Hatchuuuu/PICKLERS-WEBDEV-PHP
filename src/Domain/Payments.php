<?php
declare(strict_types=1);

namespace Picklers\Domain;

final class Payments
{
    /** Largest amount (₱) any single booking, rate or fee may charge. */
    public const MAX_PAYABLE = 100000.0;
}
