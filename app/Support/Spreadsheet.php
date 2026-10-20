<?php

namespace App\Support;

use DateTimeInterface;
use Generator;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Streaming XLSX/CSV reading and writing (OpenSpout), so large files are
 * processed row by row instead of being loaded into memory.
 */
final class Spreadsheet
{
    /**
     * Yields rows of the first sheet as arrays of strings ('' for empty).
     *
     * @return Generator<int, list<string>> keyed by 1-based row number
     */
    public static function rows(string $path, string $extension): Generator
    {
        $reader = strtolower($extension) === 'csv' ? new CsvReader : new XlsxReader;
        $reader->open($path);

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                $number = 0;
                foreach ($sheet->getRowIterator() as $row) {
                    $number++;
                    yield $number => array_map([self::class, 'cellToString'], $row->toArray());
                }
                break; // first sheet only
            }
        } finally {
            $reader->close();
        }
    }

    /**
     * Writes rows to a temporary file and returns a download response that
     * deletes the file after sending.
     *
     * @param  list<string>  $headers
     * @param  iterable<array<int, mixed>>  $rows
     */
    public static function download(string $filename, array $headers, iterable $rows, string $format = 'xlsx'): BinaryFileResponse
    {
        $format = $format === 'csv' ? 'csv' : 'xlsx';
        $path = tempnam(sys_get_temp_dir(), 'erp-export-');
        $writer = $format === 'csv' ? new CsvWriter : new XlsxWriter;
        $writer->openToFile($path);

        $writer->addRow(Row::fromValues($headers, (new Style)->setFontBold()));
        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues(array_map([self::class, 'safeCell'], array_values($row))));
        }
        $writer->close();

        return response()->download($path, "{$filename}.{$format}", [
            'Content-Type' => $format === 'csv' ? 'text/csv; charset=UTF-8' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend();
    }

    private static function cellToString(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            $value instanceof DateTimeInterface => $value->format('Y-m-d'),
            is_bool($value) => $value ? '1' : '0',
            is_float($value) => self::floatToString($value),
            default => trim((string) $value),
        };
    }

    private static function floatToString(float $value): string
    {
        if (floor($value) === $value && abs($value) < 1e15) {
            return (string) (int) $value;
        }

        return rtrim(rtrim(sprintf('%.12F', $value), '0'), '.');
    }

    /** Prevents spreadsheet formula injection in exported text. */
    private static function safeCell(mixed $value): mixed
    {
        if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }
        $value = (string) $value;

        return preg_match('/^[=+\-@\t\r]/', $value) && ! preg_match('/^-?\d+(\.\d+)?$/', $value) ? "'".$value : $value;
    }
}
