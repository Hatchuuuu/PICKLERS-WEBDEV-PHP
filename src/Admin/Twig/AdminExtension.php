<?php
declare(strict_types=1);

namespace Picklers\Admin\Twig;

use Picklers\Admin\Security\RoleMapper;
use Picklers\Admin\Service\Money;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Presentation helpers for admin templates. Pure formatting only — no queries,
 * no request/session access, no business rules.
 */
final class AdminExtension extends AbstractExtension
{
    /** Stroke icon set carried over from the original console (same visual language). */
    private const ICONS = [
        'grid' => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
        'file-text' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>',
        'building' => '<rect x="4" y="2" width="16" height="20" rx="1"/><path d="M9 22v-4h6v4"/><path d="M8 6h.01"/><path d="M16 6h.01"/><path d="M8 10h.01"/><path d="M16 10h.01"/><path d="M8 14h.01"/><path d="M16 14h.01"/>',
        'calendar' => '<rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>',
        'users' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'shield' => '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/>',
        'wallet' => '<path d="M21 12V7H5a2 2 0 0 1 0-4h14v4"/><path d="M3 5v14a2 2 0 0 0 2 2h16v-5"/><path d="M18 12a2 2 0 0 0 0 4h4v-4Z"/>',
        'tag' => '<path d="M12.586 2.586A2 2 0 0 0 11.172 2H4a2 2 0 0 0-2 2v7.172a2 2 0 0 0 .586 1.414l8.704 8.704a2.426 2.426 0 0 0 3.42 0l6.58-6.58a2.426 2.426 0 0 0 0-3.42z"/><circle cx="7.5" cy="7.5" r="1.5"/>',
        'bar-chart' => '<line x1="12" y1="20" x2="12" y2="10"/><line x1="18" y1="20" x2="18" y2="4"/><line x1="6" y1="20" x2="6" y2="16"/>',
        'terminal' => '<polyline points="4 17 10 11 4 5"/><line x1="12" y1="19" x2="20" y2="19"/>',
        'search' => '<circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>',
        'bell' => '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/>',
        'alert-triangle' => '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
        'check-circle' => '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>',
        'user' => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
        'scroll' => '<path d="M8 21h12a2 2 0 0 0 2-2v-2H10v2a2 2 0 1 1-4 0V5a2 2 0 1 0-4 0v3h4"/><path d="M19 17V5a2 2 0 0 0-2-2H4"/>',
        'x' => '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>',
        'log-out' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>',
        'database' => '<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/>',
        'clock' => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
        'sparkle' => '<path d="M12 3v4M12 17v4M3 12h4M17 12h4M5.6 5.6l2.8 2.8M15.6 15.6l2.8 2.8M18.4 5.6l-2.8 2.8M8.4 15.6l-2.8 2.8"/>',
        'menu' => '<line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/>',
        'more' => '<circle cx="12" cy="5" r="1.6"/><circle cx="12" cy="12" r="1.6"/><circle cx="12" cy="19" r="1.6"/>',
        'trash' => '<polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>',
        'plus' => '<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>',
        'copy' => '<rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>',
        'download' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>',
        'eye' => '<path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/>',
        'eye-off' => '<path d="M9.88 9.88a3 3 0 1 0 4.24 4.24"/><path d="M10.73 5.08A10.43 10.43 0 0 1 12 5c7 0 10 7 10 7a13.16 13.16 0 0 1-1.67 2.68"/><path d="M6.61 6.61A13.526 13.526 0 0 0 2 12s3 7 10 7a9.74 9.74 0 0 0 5.39-1.61"/><line x1="2" y1="2" x2="22" y2="22"/>',
        'flag' => '<path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><line x1="4" y1="22" x2="4" y2="15"/>',
        'filter' => '<polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/>',
        'refresh' => '<polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/>',
        'chevron-left' => '<polyline points="15 18 9 12 15 6"/>',
        'chevron-right' => '<polyline points="9 18 15 12 9 6"/>',
        'external' => '<path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/>',
    ];

    private const STATUS_LABELS = [
        'pending_review' => 'Pending review', 'pending' => 'Pending', 'upcoming' => 'Upcoming (legacy)', 'confirmed' => 'Confirmed',
        'completed' => 'Completed', 'cancelled' => 'Cancelled', 'declined' => 'Declined', 'approved' => 'Approved', 'rejected' => 'Rejected',
        'active' => 'Active', 'suspended' => 'Suspended', 'available' => 'Available', 'maintenance' => 'Maintenance', 'occupied' => 'In session',
        'unavailable' => 'Unavailable', 'disabled' => 'Disabled', 'expired' => 'Expired', 'exhausted' => 'Limit reached', 'archived' => 'Archived',
        'open' => 'Open', 'resolved' => 'Resolved', 'dismissed' => 'Dismissed', 'verified' => 'Verified', 'unverified' => 'Unverified',
        'success' => 'Success', 'failure' => 'Failed', 'denied' => 'Denied', 'deleted' => 'Deactivated',
        'collected_in_app' => 'Collected in app', 'refunded' => 'Refunded in app', 'external_unverified' => 'External · unverified',
        'pay_at_venue' => 'Pay at venue', 'no_wallet_record' => 'No wallet record',
        'top_up' => 'Top-up', 'booking_payment' => 'Booking payment', 'refund' => 'Refund', 'admin_adjustment' => 'Admin adjustment',
        'other_credit' => 'Other credit', 'other_debit' => 'Other debit', 'attached' => 'Attached', 'missing' => 'File missing', 'none' => 'Not provided',
    ];

    /** Tone drives colour; the text label always carries the meaning too. */
    private const STATUS_TONES = [
        'positive' => ['confirmed', 'completed', 'approved', 'active', 'available', 'verified', 'success', 'resolved', 'collected_in_app', 'attached', 'refunded', 'top_up'],
        'warning' => ['pending_review', 'pending', 'upcoming', 'maintenance', 'occupied', 'exhausted', 'open', 'external_unverified', 'pay_at_venue', 'missing', 'no_wallet_record', 'admin_adjustment', 'refund'],
        'negative' => ['cancelled', 'declined', 'rejected', 'suspended', 'failure', 'denied', 'deleted', 'unavailable', 'expired'],
    ];

    public function __construct(private readonly CsrfTokenManagerInterface $csrf)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('csrf_token_value', fn(string $id = 'admin'): string => $this->csrf->getToken($id)->getValue()),
            new TwigFunction('icon', $this->icon(...), ['is_safe' => ['html']]),
            new TwigFunction('status_tone', self::tone(...)),
            new TwigFunction('role_labels', static fn(array $u): array => RoleMapper::labels($u, (bool)($u['is_privileged'] ?? false))),
            new TwigFunction('primary_role', static fn(array $u): string => RoleMapper::primary($u, (bool)($u['is_privileged'] ?? false))),
        ];
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('peso', self::peso(...)),
            new TwigFilter('status_label', static fn(?string $s): string => self::STATUS_LABELS[(string)$s] ?? ucfirst(str_replace('_', ' ', (string)$s))),
            new TwigFilter('when', self::when(...)),
            new TwigFilter('day', static fn(?string $v): string => $v ? self::when($v, 'M j, Y') : '—'),
            new TwigFilter('initial', static fn(?string $name): string => mb_strtoupper(mb_substr(trim((string)$name) ?: '?', 0, 1))),
            new TwigFilter('plural', static fn(int $n, string $one, ?string $many = null): string => $n . ' ' . ($n === 1 ? $one : ($many ?? $one . 's'))),
        ];
    }

    public function icon(string $name, int $size = 18, string $label = ''): string
    {
        $path = self::ICONS[$name] ?? self::ICONS['grid'];
        $a11y = $label !== '' ? 'role="img" aria-label="' . htmlspecialchars($label, ENT_QUOTES) . '"' : 'aria-hidden="true" focusable="false"';

        return '<svg ' . $a11y . ' width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' . $path . '</svg>';
    }

    public static function tone(?string $status): string
    {
        foreach (self::STATUS_TONES as $tone => $list) {
            if (in_array((string)$status, $list, true)) {
                return $tone;
            }
        }

        return 'neutral';
    }

    /** DECIMAL string / number → ₱1,234.50 (exact, via centavos). */
    public static function peso(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }
        try {
            return Money::format(Money::fromColumn($value));
        } catch (\Throwable) {
            return '—';
        }
    }

    /** Stored Manila wall-clock DATETIME → readable text (no timezone conversion needed). */
    public static function when(?string $value, string $format = 'M j, Y · g:i A'): string
    {
        if ($value === null || trim($value) === '') {
            return '—';
        }
        $ts = strtotime($value);

        return $ts === false ? $value : date($format, $ts);
    }
}
