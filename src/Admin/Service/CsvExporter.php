<?php
declare(strict_types=1);

namespace Picklers\Admin\Service;

use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CSV downloads. Each export applies the same validated filters as the screen,
 * is capped at MAX_ROWS (and says so when truncated), never includes password
 * hashes, tokens or document file names, and neutralises spreadsheet formulas:
 * any cell starting with = + - @ tab or carriage return is prefixed with an
 * apostrophe so Excel/Sheets treat it as text.
 */
final class CsvExporter
{
    public const MAX_ROWS = 10000;

    /**
     * @param list<string>                     $headers
     * @param iterable<list<scalar|null>>      $rows
     */
    public function response(string $basename, array $headers, iterable $rows, bool $truncated = false): StreamedResponse
    {
        $response = new StreamedResponse(static function () use ($headers, $rows, $truncated): void {
            $out = fopen('php://output', 'wb');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so spreadsheet apps read ₱ and names correctly
            fputcsv($out, array_map([self::class, 'cell'], $headers), ',', '"', '');
            foreach ($rows as $row) {
                fputcsv($out, array_map([self::class, 'cell'], $row), ',', '"', '');
            }
            if ($truncated) {
                fputcsv($out, ['(export truncated at ' . self::MAX_ROWS . ' rows — narrow the filters)'], ',', '"', '');
            }
            fclose($out);
        });
        $filename = $basename . '-' . date('Ymd-His') . '.csv';
        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $filename));
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }

    public static function cell(mixed $value): string
    {
        $s = match (true) {
            $value === null => '',
            is_bool($value) => $value ? 'yes' : 'no',
            default => (string)$value,
        };
        if ($s !== '' && in_array($s[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            $s = "'" . $s;
        }

        return $s;
    }
}
