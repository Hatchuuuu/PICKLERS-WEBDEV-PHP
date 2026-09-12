<?php
declare(strict_types=1);

namespace Picklers\Middleware;

/**
 * General-purpose request throttling, generalized from the same flock()-based
 * counter LoginThrottle already uses for sign-in. Before this, only sign-in
 * had any throttling at all — every other mutating action (bookings, promo
 * quotes, posts, messages) could be hammered without limit, and the
 * database/rate_limits/ directory this reads/writes existed but nothing
 * referenced it.
 */
final class RateLimitMiddleware {

    /**
     * @return bool true if the identity has exceeded $limit requests to
     *              $bucket within the trailing $windowSeconds.
     */
    public static function tooMany(string $bucket, int $limit, int $windowSeconds, string $identity): bool {
        $dir = (defined('DATA_PATH') ? DATA_PATH : dirname(__DIR__, 2) . '/database') . '/rate_limits';
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }

        $key = hash('sha256', $bucket . '|' . $identity);
        $path = "$dir/$key.json";

        $fp = @fopen($path, 'c+');
        if (!$fp) {
            // Fail open rather than block real traffic on a filesystem hiccup.
            return false;
        }

        try {
            if (!flock($fp, LOCK_EX)) {
                return false;
            }
            $now = time();
            $raw = stream_get_contents($fp);
            $data = json_decode((string)$raw, true);
            if (!is_array($data) || !isset($data['count'], $data['reset'])) {
                $data = ['count' => 0, 'reset' => $now + $windowSeconds];
            }
            if ($now > (int)$data['reset']) {
                $data = ['count' => 0, 'reset' => $now + $windowSeconds];
            }
            $data['count'] = (int)$data['count'] + 1;

            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode($data));
            fflush($fp);

            return $data['count'] > $limit;
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }
}
