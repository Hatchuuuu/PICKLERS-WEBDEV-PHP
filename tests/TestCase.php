<?php
declare(strict_types=1);

namespace Picklers\Tests;

/**
 * Minimal zero-dependency test harness.
 *
 * This project is deliberately dependency-free (see public/index.php — it ships
 * its own autoloader and .env parser). Rather than pull in a vendor/ tree just
 * for assertions, this provides the small slice of PHPUnit's API the suite
 * actually needs. Run everything with:  php tests/run.php
 */
abstract class TestCase {

    private int $passed = 0;
    /** @var array<int,string> */
    private array $failures = [];

    abstract public function run(): void;

    public function name(): string {
        $fq  = static::class;
        $pos = strrpos($fq, chr(92)); // 92 = namespace separator
        return $pos === false ? $fq : substr($fq, $pos + 1);
    }

    // ── Assertions ─────────────────────────────────────────────────────────

    protected function assertSame(mixed $expected, mixed $actual, string $message): void {
        $this->record($expected === $actual, $message, $expected, $actual);
    }

    protected function assertEquals(mixed $expected, mixed $actual, string $message): void {
        $this->record($expected == $actual, $message, $expected, $actual);
    }

    protected function assertTrue(mixed $actual, string $message): void {
        $this->record($actual === true, $message, true, $actual);
    }

    protected function assertFalse(mixed $actual, string $message): void {
        $this->record($actual === false, $message, false, $actual);
    }

    protected function assertNull(mixed $actual, string $message): void {
        $this->record($actual === null, $message, null, $actual);
    }

    protected function assertNotNull(mixed $actual, string $message): void {
        $this->record($actual !== null, $message, 'not null', $actual);
    }

    protected function assertGreaterThan(float|int $floor, float|int $actual, string $message): void {
        $this->record($actual > $floor, $message, "> {$floor}", $actual);
    }

    protected function assertLessThanOrEqual(float|int $ceil, float|int $actual, string $message): void {
        $this->record($actual <= $ceil, $message, "<= {$ceil}", $actual);
    }

    /** Assert that $fn throws. */
    protected function assertThrows(callable $fn, string $message): void {
        try {
            $fn();
            $this->record(false, $message, 'an exception', 'no exception');
        } catch (\Throwable $e) {
            $this->record(true, $message, 'an exception', get_class($e));
        }
    }

    // ── Result plumbing ────────────────────────────────────────────────────

    private function record(bool $ok, string $message, mixed $expected, mixed $actual): void {
        if ($ok) {
            $this->passed++;
            return;
        }
        $this->failures[] = sprintf(
            "%s\n         expected: %s\n         actual:   %s",
            $message,
            $this->stringify($expected),
            $this->stringify($actual)
        );
    }

    private function stringify(mixed $v): string {
        if (is_bool($v)) return $v ? 'true' : 'false';
        if ($v === null) return 'null';
        if (is_array($v)) return json_encode($v, JSON_UNESCAPED_SLASHES);
        return (string)$v;
    }

    public function passedCount(): int { return $this->passed; }
    /** @return array<int,string> */
    public function failureMessages(): array { return $this->failures; }
}
