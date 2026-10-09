<?php
declare(strict_types=1);

namespace Picklers\Admin\Service;

/**
 * Validated list state for one admin panel: search text, allowlisted filters,
 * allowlisted sort, bounded page size and page number.
 *
 * Nothing from the query string reaches SQL unless it is on an allowlist; rejected
 * values are reported back (`invalid`) so the UI can say what was ignored instead
 * of silently showing a different result set.
 */
final class ListQuery
{
    public const PAGE_SIZES = [10, 25, 50, 100];
    public const DEFAULT_PAGE_SIZE = 25;
    public const MAX_SEARCH_LENGTH = 100;

    /**
     * @param array<string,string> $filters
     * @param list<string>         $invalid
     */
    private function __construct(
        public readonly string $q,
        public readonly array $filters,
        public readonly string $sort,
        public readonly string $dir,
        public readonly int $page,
        public readonly int $perPage,
        public readonly array $invalid,
    ) {
    }

    /**
     * @param array<string,mixed>                $query   raw query parameters
     * @param array<string,list<string>|string>  $allowed filter => allowed values, or 'date' / 'text' / 'id'
     * @param list<string>                       $sorts   allowed sort keys
     */
    public static function from(array $query, array $allowed, array $sorts, string $defaultSort, string $defaultDir = 'desc'): self
    {
        $invalid = [];

        $q = trim((string)($query['q'] ?? ''));
        if (mb_strlen($q) > self::MAX_SEARCH_LENGTH) {
            $q = mb_substr($q, 0, self::MAX_SEARCH_LENGTH);
            $invalid[] = 'q';
        }

        $filters = [];
        foreach ($allowed as $name => $rule) {
            $raw = $query[$name] ?? null;
            if ($raw === null || $raw === '' || is_array($raw)) {
                if (is_array($raw)) {
                    $invalid[] = $name;
                }
                continue;
            }
            $value = trim((string)$raw);
            $ok = match (true) {
                is_array($rule) => in_array($value, $rule, true),
                $rule === 'date' => self::isDate($value),
                $rule === 'id' => preg_match('/^[A-Za-z0-9_\-]{1,64}$/', $value) === 1,
                $rule === 'text' => mb_strlen($value) <= self::MAX_SEARCH_LENGTH,
                default => false,
            };
            if ($ok) {
                $filters[$name] = $value;
            } else {
                $invalid[] = $name;
            }
        }

        $sort = (string)($query['sort'] ?? $defaultSort);
        if (!in_array($sort, $sorts, true)) {
            if (isset($query['sort'])) {
                $invalid[] = 'sort';
            }
            $sort = $defaultSort;
        }
        $dir = strtolower((string)($query['dir'] ?? $defaultDir));
        if (!in_array($dir, ['asc', 'desc'], true)) {
            $invalid[] = 'dir';
            $dir = $defaultDir;
        }

        $perPage = (int)($query['per_page'] ?? self::DEFAULT_PAGE_SIZE);
        if (!in_array($perPage, self::PAGE_SIZES, true)) {
            if (isset($query['per_page'])) {
                $invalid[] = 'per_page';
            }
            $perPage = self::DEFAULT_PAGE_SIZE;
        }

        $pageRaw = $query['page'] ?? 1;
        $page = filter_var($pageRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100000]]);
        if ($page === false) {
            $invalid[] = 'page';
            $page = 1;
        }

        return new self($q, $filters, $sort, $dir, (int)$page, $perPage, array_values(array_unique($invalid)));
    }

    public static function isDate(string $value): bool
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $d !== false && $d->format('Y-m-d') === $value;
    }

    public function filter(string $name): ?string
    {
        return $this->filters[$name] ?? null;
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }

    /** Same query on another page (used when the requested page is past the end). */
    public function withPage(int $page): self
    {
        return new self($this->q, $this->filters, $this->sort, $this->dir, max(1, $page), $this->perPage, $this->invalid);
    }

    /** Same filters, no pagination bound — for exports, which are capped separately. */
    public function unpaged(int $cap): self
    {
        return new self($this->q, $this->filters, $this->sort, $this->dir, 1, $cap, $this->invalid);
    }

    /** @return array<string,string|int> query parameters reproducing this state */
    public function params(array $override = []): array
    {
        $params = array_filter(
            ['q' => $this->q] + $this->filters + ['sort' => $this->sort, 'dir' => $this->dir, 'per_page' => $this->perPage, 'page' => $this->page],
            static fn($v) => $v !== '' && $v !== null
        );
        foreach ($override as $k => $v) {
            if ($v === null) {
                unset($params[$k]);
            } else {
                $params[$k] = $v;
            }
        }

        return $params;
    }

    /** SQL LIKE pattern for the search text, with wildcards escaped. */
    public function likePattern(): string
    {
        return '%' . addcslashes($this->q, '%_\\') . '%';
    }
}
