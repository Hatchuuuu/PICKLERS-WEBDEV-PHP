<?php
declare(strict_types=1);

namespace Picklers\Domain;

use Doctrine\DBAL\Connection;

/** In-app notifications and the sync channels clients poll to notice changes. */
final class Notifier
{
    public function __construct(private readonly Connection $db)
    {
    }

    /** @return array<string,mixed> the stored notification */
    public function notify(string $userId, string $title, string $body, string $type = 'system'): array
    {
        $row = [
            'id' => 'notif_' . bin2hex(random_bytes(5)),
            'user_id' => $userId,
            'title' => $title,
            'body' => $body,
            'type' => $type,
            'is_read' => 0,
            'time' => 'Just now',
            'created_at' => date('Y-m-d H:i:s'),
        ];
        $this->db->insert('notifications', $row);

        return $row;
    }

    /**
     * Bump one or more sync channels after a write another surface must notice
     * ('courts', 'facilities', 'bookings', 'feed', …). Never throws: a missed bump
     * makes a client's next poll one tick late, which is better than failing the write.
     */
    public function bumpSync(string ...$channels): void
    {
        try {
            foreach (array_unique($channels) as $channel) {
                $this->db->executeStatement(
                    'INSERT INTO sync_versions (channel, version) VALUES (?, 1) ON DUPLICATE KEY UPDATE version = version + 1',
                    [$channel]
                );
            }
        } catch (\Throwable $e) {
            error_log('[PICKLERS] bumpSync failed for [' . implode(',', $channels) . ']: ' . $e->getMessage());
        }
    }
}
