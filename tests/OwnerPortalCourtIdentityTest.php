<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/Domain/Schedule.php';
require_once __DIR__ . '/../src/Web/Service/OwnerPortalView.php';
$reflection = new ReflectionClass(Picklers\Web\Service\OwnerPortalView::class);
$view = $reflection->newInstanceWithoutConstructor();
$method = $reflection->getMethod('courts');
$rows = [['id' => 'a', 'name' => 'Court 1'], ['id' => 'b', 'name' => 'Court 2']];
$check = function (array $courts, array $match, array $expected) use ($method, $view): void {
    $actual = array_column($method->invoke($view, $courts, [$match]), 'has_open_play');
    if ($actual !== $expected) throw new RuntimeException('Court identity mismatch: ' . json_encode([$match, $actual, $expected]));
};
$check($rows, ['court_id' => 'b', 'court_name' => 'Court 1', 'type' => 'Court 1'], [false, true]);
$check([$rows[0]], ['court_id' => 'deleted', 'type' => 'Court 1'], [false]);
$check($rows, ['court_name' => 'Court 2'], [false, true]);
$check($rows, ['type' => 'Court 1'], [true, false]);
$check([$rows[0]], ['type' => 'Open Play'], [true]);
echo "PASS: 5 pure court display identity cases\n";
