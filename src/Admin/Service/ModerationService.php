<?php
declare(strict_types=1);

namespace Picklers\Admin\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Picklers\Admin\Http\AdminActionException;
use Picklers\Admin\Security\AdminUser;
use Picklers\Domain\Notifier;

/**
 * Community content moderation.
 *
 * Queue entry: the app has no end-user "report" feature, so cases are opened by
 * an administrator flagging a real post or comment from the content list (source
 * `admin_flag`). The schema already distinguishes `user_report` for when one exists.
 *
 * Case lifecycle: open → resolved | dismissed (both final, both with a note).
 * Hiding is reversible and is never deletion: hidden posts/comments disappear
 * from every public read (feed, comments, likes) and can be restored.
 */
final class ModerationService
{
    public const CONTENT_TYPES = ['post', 'comment'];
    public const CATEGORIES = ['spam', 'scam', 'harassment', 'hate', 'inappropriate', 'privacy', 'other'];
    public const STATUSES = ['open', 'resolved', 'dismissed'];
    public const SORTS = ['opened_at'];

    public function __construct(
        private readonly Connection $db,
        private readonly Notifier $notifier,
        private readonly AuditLog $audit,
    ) {
    }

    /** @return Page<array<string,mixed>> */
    public function searchCases(ListQuery $query): Page
    {
        $where = ['1=1'];
        $params = [];
        if ($query->q !== '') {
            $where[] = '(c.id = ? OR c.content_id = ? OR c.reason LIKE ? OR c.content_snapshot LIKE ?)';
            array_push($params, $query->q, $query->q, $query->likePattern(), $query->likePattern());
        }
        foreach (['status' => 'c.status', 'type' => 'c.content_type', 'category' => 'c.category'] as $f => $col) {
            if (($v = $query->filter($f)) !== null) {
                $where[] = "{$col} = ?";
                $params[] = $v;
            }
        }
        $sql = implode(' AND ', $where);
        $total = (int)$this->db->fetchOne("SELECT COUNT(*) FROM moderation_cases c WHERE {$sql}", $params);
        if ($total > 0 && $query->offset() >= $total) {
            $query = $query->withPage((int)ceil($total / $query->perPage));
        }
        $dir = $query->dir === 'asc' ? 'ASC' : 'DESC';
        $rows = $this->db->fetchAllAssociative(
            "SELECT c.*, au.name AS author_name, ob.name AS opened_by_name, rb.name AS resolved_by_name,
                    CASE c.content_type
                      WHEN 'post' THEN (SELECT p.hidden_at FROM feed_posts p WHERE p.id = c.content_id)
                      ELSE (SELECT m.hidden_at FROM feed_comments m WHERE m.id = c.content_id) END AS hidden_at,
                    CASE c.content_type
                      WHEN 'post' THEN (SELECT COUNT(*) FROM feed_posts p WHERE p.id = c.content_id)
                      ELSE (SELECT COUNT(*) FROM feed_comments m WHERE m.id = c.content_id) END AS content_exists
               FROM moderation_cases c
               LEFT JOIN users au ON au.id = c.content_author_id
               LEFT JOIN users ob ON ob.id = c.opened_by
               LEFT JOIN users rb ON rb.id = c.resolved_by
              WHERE {$sql} ORDER BY c.opened_at {$dir}, c.id ASC LIMIT {$query->perPage} OFFSET {$query->offset()}",
            $params
        );

        return new Page($rows, $total, $query);
    }

    /** Recent posts/comments to review or flag (the manual queue entry path). @return Page<array<string,mixed>> */
    public function searchContent(ListQuery $query): Page
    {
        $type = $query->filter('ctype') ?? 'post';
        $params = [];
        if ($type === 'comment') {
            $base = "SELECT m.id, 'comment' AS content_type, m.comment AS body, m.user_id AS author_id, m.author_name, m.created_at, m.hidden_at, m.post_id,
                            (SELECT c.id FROM moderation_cases c WHERE c.content_type = 'comment' AND c.content_id = m.id AND c.status = 'open') AS open_case_id
                       FROM feed_comments m";
            $where = $query->q !== '' ? ' WHERE (m.comment LIKE ? OR m.author_name LIKE ? OR m.id = ?)' : '';
            $count = 'SELECT COUNT(*) FROM feed_comments m' . $where;
        } else {
            $base = "SELECT p.id, 'post' AS content_type, p.content AS body, p.author_id, p.author_name, p.created_at, p.hidden_at, NULL AS post_id,
                            (SELECT c.id FROM moderation_cases c WHERE c.content_type = 'post' AND c.content_id = p.id AND c.status = 'open') AS open_case_id
                       FROM feed_posts p";
            $where = $query->q !== '' ? ' WHERE (p.content LIKE ? OR p.author_name LIKE ? OR p.id = ?)' : '';
            $count = 'SELECT COUNT(*) FROM feed_posts p' . $where;
        }
        if ($query->q !== '') {
            $params = [$query->likePattern(), $query->likePattern(), $query->q];
        }
        $total = (int)$this->db->fetchOne($count, $params);
        if ($total > 0 && $query->offset() >= $total) {
            $query = $query->withPage((int)ceil($total / $query->perPage));
        }
        $rows = $this->db->fetchAllAssociative("{$base}{$where} ORDER BY created_at DESC LIMIT {$query->perPage} OFFSET {$query->offset()}", $params);

        return new Page($rows, $total, $query);
    }

    public function openCount(): int
    {
        return (int)$this->db->fetchOne("SELECT COUNT(*) FROM moderation_cases WHERE status = 'open'");
    }

    /** @return array<string,mixed> */
    public function caseDetail(string $id): array
    {
        $case = $this->db->fetchAssociative(
            'SELECT c.*, au.name AS author_name, ob.name AS opened_by_name, rb.name AS resolved_by_name FROM moderation_cases c
               LEFT JOIN users au ON au.id = c.content_author_id LEFT JOIN users ob ON ob.id = c.opened_by LEFT JOIN users rb ON rb.id = c.resolved_by
              WHERE c.id = ?',
            [$id]
        );
        if ($case === false) {
            throw AdminActionException::notFound('Moderation case');
        }
        $case['content'] = $this->content((string)$case['content_type'], (string)$case['content_id']);
        $case['events'] = $this->db->fetchAllAssociative(
            'SELECT e.*, u.name AS actor_name FROM moderation_case_events e LEFT JOIN users u ON u.id = e.actor_user_id WHERE e.case_id = ? ORDER BY e.id ASC',
            [$id]
        );

        return $case;
    }

    public function flag(AdminUser $actor, string $type, string $contentId, string $category, string $reason): array
    {
        if (!in_array($type, self::CONTENT_TYPES, true)) {
            throw AdminActionException::invalid('Content type must be post or comment.', 'content_type');
        }
        if (!in_array($category, self::CATEGORIES, true)) {
            throw AdminActionException::invalid('Choose a category.', 'category');
        }
        $reason = $this->requireNote($reason, 'Describe the problem (at least 5 characters).');

        try {
            return $this->db->transactional(function () use ($actor, $type, $contentId, $category, $reason): array {
                $content = $this->content($type, $contentId, true);
                if ($content === null) {
                    throw AdminActionException::notFound(ucfirst($type));
                }
                $id = 'mod_' . bin2hex(random_bytes(6));
                $now = date('Y-m-d H:i:s');
                $this->db->insert('moderation_cases', [
                    'id' => $id, 'content_type' => $type, 'content_id' => $contentId,
                    'content_author_id' => $content['author_id'], 'content_snapshot' => mb_substr((string)$content['body'], 0, 2000),
                    'source' => 'admin_flag', 'category' => $category, 'reason' => $reason, 'status' => 'open', 'open_marker' => 1,
                    'opened_by' => $actor->id(), 'opened_at' => $now,
                ]);
                $this->event($id, 'opened', $actor, $reason);
                $this->audit->record('moderation.flag', 'moderation_case', $id, ['content_type' => $type, 'content_id' => $contentId, 'category' => $category], $reason);

                return ['case_id' => $id, 'message' => 'Added to the review queue.'];
            });
        } catch (UniqueConstraintViolationException) {
            $open = $this->db->fetchOne("SELECT id FROM moderation_cases WHERE content_type = ? AND content_id = ? AND status = 'open'", [$type, $contentId]);
            throw AdminActionException::conflict('This content already has an open case' . ($open ? " ({$open})" : '') . '.', ['case_id' => $open ?: null]);
        }
    }

    public function hide(AdminUser $actor, string $caseId, string $reason): array
    {
        $reason = $this->requireNote($reason, 'Give a reason for hiding (at least 5 characters).');

        return $this->db->transactional(function () use ($actor, $caseId, $reason): array {
            $case = $this->lockedOpenCase($caseId);
            $table = $case['content_type'] === 'post' ? 'feed_posts' : 'feed_comments';
            $content = $this->content((string)$case['content_type'], (string)$case['content_id'], true);
            if ($content === null) {
                throw AdminActionException::conflict('The content no longer exists; resolve the case instead.');
            }
            if ($content['hidden_at'] !== null) {
                throw AdminActionException::conflict('This content is already hidden.');
            }
            $this->db->update($table, ['hidden_at' => date('Y-m-d H:i:s'), 'hidden_by' => $actor->id(), 'hidden_reason' => mb_substr($reason, 0, 255)], ['id' => $case['content_id']]);
            $this->event($caseId, 'hidden', $actor, $reason);
            $this->audit->record('moderation.hide', 'moderation_case', $caseId, ['content_type' => $case['content_type'], 'content_id' => $case['content_id'], 'hidden' => ['from' => false, 'to' => true]], $reason);
            $this->notifier->bumpSync('feed');

            return ['message' => 'Content hidden from the community feed. It can be restored.'];
        });
    }

    public function restore(AdminUser $actor, string $caseId, string $reason): array
    {
        $reason = $this->requireNote($reason, 'Give a reason for restoring (at least 5 characters).');

        return $this->db->transactional(function () use ($actor, $caseId, $reason): array {
            $case = $this->db->fetchAssociative('SELECT * FROM moderation_cases WHERE id = ? FOR UPDATE', [$caseId]);
            if ($case === false) {
                throw AdminActionException::notFound('Moderation case');
            }
            $table = $case['content_type'] === 'post' ? 'feed_posts' : 'feed_comments';
            $content = $this->content((string)$case['content_type'], (string)$case['content_id'], true);
            if ($content === null || $content['hidden_at'] === null) {
                throw AdminActionException::conflict('This content is not hidden.');
            }
            $this->db->update($table, ['hidden_at' => null, 'hidden_by' => null, 'hidden_reason' => null], ['id' => $case['content_id']]);
            $this->event($caseId, 'restored', $actor, $reason);
            $this->audit->record('moderation.restore', 'moderation_case', $caseId, ['content_type' => $case['content_type'], 'content_id' => $case['content_id'], 'hidden' => ['from' => true, 'to' => false]], $reason);
            $this->notifier->bumpSync('feed');

            return ['message' => 'Content restored to the community feed.'];
        });
    }

    /** $outcome: resolved (action taken / upheld) | dismissed (no violation). */
    public function close(AdminUser $actor, string $caseId, string $outcome, string $note): array
    {
        if (!in_array($outcome, ['resolved', 'dismissed'], true)) {
            throw AdminActionException::invalid('Outcome must be resolved or dismissed.');
        }
        $note = $this->requireNote($note, 'Add a closing note (at least 5 characters).');

        return $this->db->transactional(function () use ($actor, $caseId, $outcome, $note): array {
            $case = $this->lockedOpenCase($caseId);
            $content = $this->content((string)$case['content_type'], (string)$case['content_id']);
            $hidden = $content !== null && $content['hidden_at'] !== null;
            if ($outcome === 'dismissed' && $hidden) {
                throw AdminActionException::conflict('The content is hidden. Restore it before dismissing, or resolve the case instead.');
            }
            $this->db->update('moderation_cases', [
                'status' => $outcome, 'open_marker' => null, 'resolved_by' => $actor->id(), 'resolved_at' => date('Y-m-d H:i:s'),
                'resolution' => $outcome === 'dismissed' ? 'no_violation' : ($hidden ? 'content_hidden' : 'no_action'),
                'resolution_note' => $note,
            ], ['id' => $caseId]);
            $this->event($caseId, $outcome, $actor, $note);
            $this->audit->record('moderation.' . ($outcome === 'resolved' ? 'resolve' : 'dismiss'), 'moderation_case', $caseId, ['status' => ['from' => 'open', 'to' => $outcome], 'content_hidden' => $hidden], $note);

            return ['message' => $outcome === 'resolved' ? 'Case resolved.' : 'Case dismissed.'];
        });
    }

    /** @return array{body:string,author_id:?string,hidden_at:?string,created_at:?string}|null */
    private function content(string $type, string $id, bool $lock = false): ?array
    {
        $sql = $type === 'post'
            ? 'SELECT content AS body, author_id, author_name, hidden_at, hidden_reason, created_at FROM feed_posts WHERE id = ?'
            : 'SELECT comment AS body, user_id AS author_id, author_name, hidden_at, hidden_reason, created_at, post_id FROM feed_comments WHERE id = ?';
        $row = $this->db->fetchAssociative($sql . ($lock ? ' FOR UPDATE' : ''), [$id]);

        return $row === false ? null : $row;
    }

    private function lockedOpenCase(string $caseId): array
    {
        $case = $this->db->fetchAssociative('SELECT * FROM moderation_cases WHERE id = ? FOR UPDATE', [$caseId]);
        if ($case === false) {
            throw AdminActionException::notFound('Moderation case');
        }
        if ($case['status'] !== 'open') {
            throw AdminActionException::conflict("This case is already {$case['status']}.");
        }

        return $case;
    }

    private function event(string $caseId, string $action, AdminUser $actor, ?string $note): void
    {
        $this->db->insert('moderation_case_events', [
            'case_id' => $caseId, 'action' => $action, 'actor_user_id' => $actor->id(), 'note' => $note, 'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function requireNote(string $note, string $message): string
    {
        $note = trim($note);
        if (mb_strlen($note) < 5) {
            throw AdminActionException::invalid($message, 'reason');
        }

        return mb_substr($note, 0, 1000);
    }
}
