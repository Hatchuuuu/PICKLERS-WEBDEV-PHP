<?php
declare(strict_types=1);

namespace Picklers\Exceptions;

/**
 * A promo code passed its quote-time checks but can no longer be redeemed when
 * the booking commits (exhausted, expired, disabled or archived meanwhile, or the
 * player's own limit reached). The booking transaction rolls back.
 */
class PromoUnavailableException extends \RuntimeException {
}
