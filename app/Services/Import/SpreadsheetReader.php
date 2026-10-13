<?php
declare(strict_types=1);

namespace App\Services\Import;

/** Minimal CSV / XLSX reader (no libraries): returns rows as arrays of strings, first row = header. */
final class SpreadsheetReader
{
    private const MAX_ROWS = 5000;

    /** @return array<int,array<int,string>> @throws \RuntimeException */
    public static function read(string $path, string $originalName): array
    {
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (filesize($path) > 6 * 1024 * 1024) {
            throw new \RuntimeException(__('The file is too large (max 6 MB).'));
        }
        return match ($ext) {
            'csv', 'txt' => self::csv($path),
            'xlsx' => self::xlsx($path),
            default => throw new \RuntimeException(__('Unsupported file. Upload an .xlsx or .csv file.')),
        };
    }

    /** @return array<int,array<int,string>> */
    private static function csv(string $path): array
    {
        $raw = (string) file_get_contents($path);
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw;
        if (!mb_check_encoding($raw, 'UTF-8')) {
            $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1256');   // Excel "CSV" saved in Arabic Windows encoding
        }
        $first = strtok($raw, "\n") ?: '';
        $delim = ',';
        foreach ([';', "\t"] as $d) {
            if (substr_count($first, $d) > substr_count($first, $delim)) {
                $delim = $d;
            }
        }
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, $raw);
        rewind($fh);
        $rows = [];
        while (($r = fgetcsv($fh, 0, $delim, '"', '\\')) !== false && count($rows) <= self::MAX_ROWS) {
            if ($r === [null] || !array_filter($r, static fn ($v) => trim((string) $v) !== '')) {
                continue;
            }
            $rows[] = array_map(static fn ($v) => trim((string) $v), $r);
        }
        fclose($fh);
        return $rows;
    }

    /** @return array<int,array<int,string>> */
    private static function xlsx(string $path): array
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException(__('Excel files are not supported on this server, upload a CSV file.'));
        }
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            throw new \RuntimeException(__('The Excel file is damaged.'));
        }
        $shared = [];
        if (($xml = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
            $sx = @simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET);
            foreach ($sx->si ?? [] as $si) {
                $t = '';
                if (isset($si->t)) {
                    $t = (string) $si->t;
                } else {
                    foreach ($si->r ?? [] as $run) {
                        $t .= (string) $run->t;
                    }
                }
                $shared[] = $t;
            }
        }
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        if ($sheet === false) {
            $sheet = $zip->getFromIndex(0) ?: '';
            foreach (range(0, $zip->numFiles - 1) as $i) {
                if (preg_match('#^xl/worksheets/sheet\d+\.xml$#', (string) $zip->getNameIndex($i))) {
                    $sheet = (string) $zip->getFromIndex($i);
                    break;
                }
            }
        }
        $zip->close();
        $sx = @simplexml_load_string($sheet, 'SimpleXMLElement', LIBXML_NONET);
        if (!$sx || !isset($sx->sheetData)) {
            throw new \RuntimeException(__('Could not read the first sheet of the file.'));
        }
        $rows = [];
        foreach ($sx->sheetData->row as $row) {
            $cells = [];
            foreach ($row->c as $c) {
                $col = self::colIndex((string) $c['r']);
                $type = (string) $c['t'];
                $val = match ($type) {
                    's' => $shared[(int) $c->v] ?? '',
                    'inlineStr' => (string) ($c->is->t ?? ''),
                    default => (string) $c->v,
                };
                $cells[$col] = trim($val);
            }
            if (!$cells || !array_filter($cells, static fn ($v) => $v !== '')) {
                continue;
            }
            $max = max(array_keys($cells));
            $line = [];
            for ($i = 0; $i <= $max; $i++) {
                $line[] = $cells[$i] ?? '';
            }
            $rows[] = $line;
            if (count($rows) > self::MAX_ROWS) {
                break;
            }
        }
        return $rows;
    }

    private static function colIndex(string $ref): int
    {
        preg_match('/^([A-Z]+)/', strtoupper($ref), $m);
        $n = 0;
        foreach (str_split($m[1] ?? 'A') as $ch) {
            $n = $n * 26 + (ord($ch) - 64);
        }
        return $n - 1;
    }
}
