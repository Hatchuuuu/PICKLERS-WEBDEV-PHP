<?php
declare(strict_types=1);

namespace Picklers\Web\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** How the owner portal presents stored facility, revenue and tournament data. */
final class OwnerViewExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('opening_hours_fields', self::openingHoursFields(...)),
            new TwigFunction('court_card_view', self::courtCardView(...)),
            new TwigFunction('tournament_heading', self::tournamentHeading(...)),
        ];
    }

    /**
     * Display title, reference code and date range for a tournament card/console.
     * A trailing 6+ digit number in the title is its reference ("Summer Cup 202610"
     * → "Summer Cup", #PKLT202610); otherwise the reference comes from the id.
     *
     * @return array{title:string,ref:string,dates:string}
     */
    public static function tournamentHeading(array $t): array
    {
        $format = static function (string $d): string {
            $d = trim($d);
            $ts = $d === '' ? false : strtotime($d);

            return $d === '' ? '' : ($ts ? date('F, j Y', $ts) : $d);
        };
        $start = $format((string)($t['date'] ?? ''));
        $end = !empty($t['end_date']) && $t['end_date'] !== ($t['date'] ?? null) ? $format((string)$t['end_date']) : '';
        $title = (string)($t['title'] ?? '');
        if (preg_match('/^(.*?)\s+(\d{6,})$/', $title, $m)) {
            $title = trim($m[1]);
            $ref = '#PKLT' . $m[2];
        } else {
            $cleanId = strtoupper(str_replace(['tourn_', '_'], '', (string)($t['id'] ?? '')));
            $ref = '#' . (str_starts_with($cleanId, 'PKLT') ? $cleanId : 'PKLT' . substr($cleanId, 0, 8));
        }

        return ['title' => $title, 'ref' => $ref, 'dates' => $start !== '' && $end !== '' ? "{$start} – {$end}" : $start];
    }

    /**
     * A court card's badge and figures on the My Courts tab.
     *
     * @return array{active:bool,has_open_play:bool,status:string,badge:string,occupied:bool,rate:float,surface:string,player:mixed,player_time:mixed,player_avatar:mixed}
     */
    public static function courtCardView(array $c): array
    {
        $active = (bool)($c['active'] ?? true);
        $hasOpenPlay = !empty($c['has_open_play']);
        $status = strtoupper(trim((string)($c['status'] ?? (!$active ? 'UNAVAILABLE' : 'AVAILABLE'))));
        [$status, $badge] = match (true) {
            $hasOpenPlay && in_array($status, ['AVAILABLE', 'HOSTED OPEN PLAY', 'OPEN PLAY', 'OCCUPIED'], true) => ['HOSTED OPEN PLAY', 'badge-amber-glow'],
            $status === 'OCCUPIED' => ['OCCUPIED', 'badge-amber-glow'],
            $status === 'UNAVAILABLE' || !$active => ['UNAVAILABLE', 'badge-red-glow'],
            default => ['AVAILABLE', 'badge-emerald-glow'],
        };
        $rawRate = $c['rate'] ?? 350;
        $rate = is_numeric($rawRate) ? (float)$rawRate : (float)preg_replace('/[^0-9.]/', '', (string)$rawRate);

        return [
            'active' => $active,
            'has_open_play' => $hasOpenPlay,
            'status' => $status,
            'badge' => $badge,
            'occupied' => $status === 'OCCUPIED',
            'rate' => $rate <= 0 ? 350.0 : $rate,
            'surface' => (string)($c['surface'] ?? 'Premium Hard'),
            'player' => $c['player'] ?? null,
            'player_time' => $c['player_time'] ?? null,
            'player_avatar' => $c['player_avatar'] ?? $c['user_avatar'] ?? $c['avatar_url'] ?? $c['avatar'] ?? null,
        ];
    }

    /**
     * The settings form's hours fields from what update_facility_settings saved:
     * the literal "Open 24 Hours" or "{open} – {close}".
     *
     * @return array{open24:bool,open:string,close:string}
     */
    public static function openingHoursFields(?string $saved): array
    {
        $saved = trim((string)$saved);
        $fields = ['open24' => strcasecmp($saved, 'Open 24 Hours') === 0, 'open' => '06:00 AM', 'close' => '10:00 PM'];
        if (!$fields['open24'] && $saved !== '') {
            $parts = preg_split('/\s*[–-]\s*/u', $saved) ?: [];
            if (count($parts) === 2) {
                $fields['open'] = trim($parts[0]);
                $fields['close'] = trim($parts[1]);
            }
        }

        return $fields;
    }
}
