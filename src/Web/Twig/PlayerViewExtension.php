<?php
declare(strict_types=1);

namespace Picklers\Web\Twig;

use Picklers\Core\Database;
use Picklers\Domain\BookingRules;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** How the player app labels wallet movements and bookings. */
final class PlayerViewExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('wallet_tx_view', self::walletTxView(...)),
            new TwigFunction('booking_type_details', self::bookingTypeDetails(...)),
            new TwigFunction('booking_display_date', self::bookingDisplayDate(...)),
            new TwigFunction('booking_js_args', self::bookingJsArgs(...), ['is_safe' => ['html']]),
            new TwigFunction('booking_is_past', BookingRules::isBookingPast(...)),
            new TwigFunction('court_label', static fn($name): string => Database::normalizeCourtName((string)$name)),
            new TwigFunction('joined_occurrence_keys', self::joinedOccurrenceKeys(...)),
            new TwigFunction('upcoming_days', self::upcomingDays(...)),
        ];
    }

    /**
     * The next $count calendar days for the booking date pickers, today first.
     *
     * @return list<array{full:string,day:string,sub:string,label:string,active:bool}>
     */
    public static function upcomingDays(int $count): array
    {
        $now = new \DateTimeImmutable('now');
        $days = [];
        for ($i = 0; $i < $count; $i++) {
            $d = $i > 0 ? $now->modify("+{$i} day") : $now;
            $month = $d->format('M');
            $days[] = [
                'full' => $d->format('D M j Y'),
                'day' => strtoupper($d->format('D')),
                'sub' => ($month === 'Sep' ? 'Sept' : $month) . ' ' . $d->format('j'),
                'label' => $i === 0 ? 'Today' : ($i === 1 ? 'Tomorrow' : $d->format('l')),
                'active' => $i === 0,
            ];
        }

        return $days;
    }

    /**
     * Open Play occurrences this player already holds a seat on (pending or
     * confirmed), keyed by match id / facility+court, each with and without the
     * occurrence date, so a card offers "Joined" instead of a doomed second join.
     *
     * @param list<array<string,mixed>> $bookings
     * @return array<string,true>
     */
    public static function joinedOccurrenceKeys(array $bookings): array
    {
        $keys = [];
        foreach ($bookings as $b) {
            if (!in_array($b['status'] ?? '', ['pending', 'confirmed'], true)) {
                continue;
            }
            $date = (string)($b['booking_date'] ?? '');
            if (!empty($b['match_id'])) {
                $keys[(string)$b['match_id']] = true;
                $keys[$b['match_id'] . '|' . $date] = true;
            }
            $facility = (string)($b['facility_id'] ?? '');
            $court = Database::normalizeCourtName((string)($b['court_name'] ?? ''));
            if ($facility !== '' && $court !== '') {
                $keys[$facility . '|' . $court] = true;
                $keys[$facility . '|' . $court . '|' . $date] = true;
            }
        }

        return $keys;
    }

    /**
     * One wallet movement as the Wallet tab shows it.
     *
     * @return array{category:string,is_credit:bool,title:string,code:string}
     */
    public static function walletTxView(array $t): array
    {
        $isCredit = ($t['type'] ?? '') === 'credit';
        $label = trim((string)($t['label'] ?? 'Transaction'));
        $isRefund = stripos($label, 'refund') !== false;
        $isOpenPlay = stripos($label, 'open play') !== false;
        $isBooking = stripos($label, 'booking') !== false;

        $payMethod = '';
        if (!empty($t['payment_method'])) {
            $payMethod = trim((string)$t['payment_method']);
        } elseif (!empty($t['method'])) {
            $payMethod = trim((string)$t['method']);
        } elseif (preg_match('/\bvia\s+([A-Za-z0-9\s]+?)(?:\s*\(|\s*$)/i', $label, $m)) {
            $payMethod = trim($m[1]);
        }

        $forFacility = (string)preg_replace('/\([^)]*\)/', '', (string)preg_replace('/\bvia\s+.*$/i', '', $label));
        $facility = preg_match('/at\s+([^(\n\v]+)/i', $forFacility, $m) ? trim($m[1]) : '';
        $method = $payMethod !== '' ? $payMethod : 'GCash';

        // Refunds, admin adjustments and opening balances are checked first.
        if ($isRefund) {
            $title = 'Refund' . ($isOpenPlay ? ' • Open Play' : ($isBooking ? ' • Booking' : ''));
        } elseif (str_starts_with($label, '[Admin]')) {
            $title = ($isCredit ? 'Credit' : 'Deduction') . ' by Picklers';
        } elseif ($label === 'Opening Balance') {
            $title = 'Opening Balance';
        } elseif ($isOpenPlay) {
            $title = ($facility !== '' ? "Open Play • {$facility}" : 'Open Play') . " via {$method}";
        } elseif ($isBooking) {
            $title = ($facility !== '' ? "Booked • {$facility}" : 'Booked') . " via {$method}";
        } else {
            $topUpMethod = trim((string)preg_replace('/\bWallet\s+/i', '', trim((string)preg_replace('/\s*(?:Wallet\s*)?Top[- ]?Up\b/i', '', $label))));
            $title = ($topUpMethod !== '' && $topUpMethod !== '0' ? $topUpMethod : $method) . ' Top-Up';
        }

        // The booking code from the label; otherwise the entry's own id, never an invented booking code.
        if (preg_match('/#(PKL-?[A-Z0-9]+)/i', $label, $m)) {
            $code = '#' . strtoupper(str_replace('#', '', $m[1]));
            if (!str_starts_with($code, '#PKL-') && str_starts_with($code, '#PKL')) {
                $code = '#PKL-' . substr($code, 4);
            }
        } elseif (preg_match('/\b(PKL-[A-Z0-9]+)\b/i', $label, $m)) {
            $code = '#' . strtoupper($m[1]);
        } else {
            $code = '#' . strtoupper((string)($t['id'] ?? 'TX'));
        }

        return [
            'category' => $isRefund ? 'refunds' : ($isCredit ? 'deposits' : 'bookings'),
            'is_credit' => $isCredit,
            'title' => $title,
            'code' => $code,
        ];
    }

    /**
     * How a booking card names itself: an Open Play session or a court reservation.
     *
     * @return array{is_op:bool,type_key:string,type_label:string,icon:string,display_name:string,facility_name:string,payment_label:string}
     */
    public static function bookingTypeDetails(array $b): array
    {
        $court = trim((string)($b['court_name'] ?? ''));
        $facility = trim((string)($b['facility_name'] ?? 'Pickleball Facility'));
        $isOp = ($b['type'] ?? null) === 'open_play'
            || ($b['booking_type'] ?? null) === 'open_play'
            || str_starts_with((string)($b['id'] ?? ''), 'PKL-OP-')
            || stripos($court, 'open play') !== false
            || stripos($court, 'king of the court') !== false
            || stripos($court, 'match') !== false;

        if ($isOp) {
            $name = ($court === '' || strcasecmp($court, $facility) === 0) ? '' : (string)preg_replace('/\s*[\(–-].*$/', '', $court);
            $displayName = trim($name) === '' ? 'Open Play Session' : $name;
        } elseif (preg_match('/Court\s*\d+/i', $court, $m)) {
            $displayName = (string)preg_replace('/^court\s*/i', 'Court ', $m[0]);
        } else {
            $name = (string)preg_replace('/\s*[\(–-].*$/', '', $court);
            $displayName = trim($name) === '' ? 'Court 1' : $name;
        }

        $pm = trim((string)($b['payment_method'] ?? 'Maya')) ?: 'Maya';
        $payment = match (true) {
            stripos($pm, 'paid via') !== false => $pm,
            stripos($pm, 'venue') !== false => 'Pay at Venue',
            default => 'Paid via ' . $pm,
        };

        return [
            'is_op' => $isOp,
            'type_key' => $isOp ? 'open_play' : 'book',
            'type_label' => $isOp ? 'Open Play' : 'Court Reservation',
            'icon' => $isOp ? '🔥' : '🏟️',
            'display_name' => $displayName,
            'facility_name' => $facility,
            'payment_label' => $payment,
        ];
    }

    /** "Everyday (2026-10-08)" / ISO dates → "Thu, Oct 8, 2026"; other labels as stored. */
    public static function bookingDisplayDate(array $b): string
    {
        $raw = trim((string)($b['date'] ?? ''));
        $bookingDate = trim((string)($b['booking_date'] ?? ''));
        if (preg_match('/Everyday\s*\(([^)]+)\)/i', $raw, $m)) {
            $raw = trim($m[1]);
        } elseif (strcasecmp($raw, 'everyday') === 0 && $bookingDate !== '') {
            $raw = $bookingDate;
        }
        $ts = strtotime($raw);

        return $ts !== false && $ts > 0 && preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) ? date('D, M j, Y', $ts) : $raw;
    }

    /** Inline onclick arguments: each value a JSON string literal, the list attribute-escaped. */
    public static function bookingJsArgs(...$values): string
    {
        $flags = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE;

        return htmlspecialchars(implode(', ', array_map(static fn($v) => json_encode((string)$v, $flags), $values)), ENT_QUOTES, 'UTF-8');
    }
}
