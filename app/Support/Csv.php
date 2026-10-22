<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CSV import/export helpers (Excel opens and saves CSV directly). Exports
 * add a UTF-8 BOM and neutralise spreadsheet formulas.
 */
final class Csv
{
    /**
     * @param  list<string>  $headers
     * @param  iterable<array<int, mixed>>  $rows
     */
    public static function download(string $filename, array $headers, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $headers);
            foreach ($rows as $row) {
                fputcsv($out, array_map(fn ($v) => is_string($v) && preg_match('/^[=+\-@]/', $v) ? "'".$v : $v, array_values($row)));
            }
            fclose($out);
        }, $filename.'-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Reads an uploaded CSV into rows keyed by the expected column keys.
     *
     * @param  array<string, list<string>>  $columns  key => accepted header names (lower-case)
     * @param  list<string>  $required  keys that must be present as columns
     * @return list<array{row: int, values: array<string, string>}>
     */
    public static function read(UploadedFile $file, array $columns, array $required, int $maxRows = 5000): array
    {
        $handle = fopen($file->getRealPath(), 'r');
        if ($handle === false) {
            throw ValidationException::withMessages(['file' => 'The file could not be read.']);
        }

        try {
            $header = fgetcsv($handle);
            if (! $header) {
                throw ValidationException::withMessages(['file' => 'The file is empty. The first row must contain the column headings.']);
            }
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
            $positions = [];
            foreach ($header as $index => $name) {
                $name = mb_strtolower(trim((string) $name));
                foreach ($columns as $key => $aliases) {
                    if (in_array($name, $aliases, true) && ! isset($positions[$key])) {
                        $positions[$key] = $index;
                    }
                }
            }
            $missing = array_diff($required, array_keys($positions));
            if ($missing) {
                $labels = array_map(fn ($k) => $columns[$k][0], $missing);
                throw ValidationException::withMessages(['file' => 'Missing required column(s): '.implode(', ', $labels).'. Download the template for the expected headings.']);
            }

            $rows = [];
            $number = 1;
            while (($cells = fgetcsv($handle)) !== false) {
                $number++;
                if (! array_filter($cells, fn ($c) => trim((string) $c) !== '')) {
                    continue;
                }
                $values = [];
                foreach ($columns as $key => $aliases) {
                    $values[$key] = isset($positions[$key]) ? trim((string) ($cells[$positions[$key]] ?? '')) : '';
                }
                $rows[] = ['row' => $number, 'values' => $values];
                if (count($rows) > $maxRows) {
                    throw ValidationException::withMessages(['file' => "The file has more than {$maxRows} rows. Split it into smaller files."]);
                }
            }
        } finally {
            fclose($handle);
        }

        if (! $rows) {
            throw ValidationException::withMessages(['file' => 'The file contains no data rows.']);
        }

        return $rows;
    }
}
