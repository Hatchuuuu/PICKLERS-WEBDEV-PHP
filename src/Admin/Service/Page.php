<?php
declare(strict_types=1);

namespace Picklers\Admin\Service;

/**
 * One page of a server-side list with an exact total for the active filters.
 *
 * @template T
 */
final class Page
{
    /**
     * @param list<T> $items
     * @param array<string,mixed> $summary  optional totals computed over the WHOLE filtered set
     */
    public function __construct(
        public readonly array $items,
        public readonly int $total,
        public readonly ListQuery $query,
        public readonly array $summary = [],
    ) {
    }

    public function pages(): int
    {
        return max(1, (int)ceil($this->total / max(1, $this->query->perPage)));
    }

    public function from(): int
    {
        return $this->total === 0 ? 0 : $this->query->offset() + 1;
    }

    public function to(): int
    {
        return min($this->total, $this->query->offset() + count($this->items));
    }

    public function isEmpty(): bool
    {
        return $this->total === 0;
    }

    public function hasFilters(): bool
    {
        return $this->query->q !== '' || $this->query->filters !== [];
    }
}
