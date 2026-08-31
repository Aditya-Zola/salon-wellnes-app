<?php

namespace App\Http\Services;

use App\Http\Support\FixedPoint;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use SimpleXMLElement;
use ZipArchive;

class ProductSpreadsheetImportService
{
    private const MAX_ROWS = 2000;

    private const MAX_XML_BYTES = 10_000_000;

    /**
     * @return array{imported: int, skipped: int, issues_total: int, issues: array<int, array{row: int, message: string}>}
     */
    public function import(string $path, string $extension, ?int $userId): array
    {
        $rows = $extension === 'csv'
            ? $this->csvRows($path)
            : $this->xlsxRows($path);
        [$headerRow, $headers] = $this->findHeaders($rows);
        $units = $this->unitMap();
        $existingCodes = DB::table('products')
            ->pluck('code')
            ->mapWithKeys(fn (string $code): array => [mb_strtoupper(trim($code)) => true])
            ->all();
        $seenCodes = [];
        $records = [];
        $issues = [];
        $issuesTotal = 0;
        $dataRows = 0;

        foreach (array_slice($rows, $headerRow + 1, null, true) as $index => $row) {
            if ($this->rowIsEmpty($row)) {
                continue;
            }

            $dataRows++;
            if ($dataRows > self::MAX_ROWS) {
                throw ValidationException::withMessages([
                    'file' => ['Maksimal '.self::MAX_ROWS.' baris produk dalam satu file.'],
                ]);
            }

            $rowNumber = $index + 1;
            $code = mb_strtoupper(trim($this->value($row, $headers, 'code')));
            $name = trim($this->value($row, $headers, 'name'));
            $category = trim($this->value($row, $headers, 'category'));
            $unitLabel = trim($this->value($row, $headers, 'unit'));
            $description = trim($this->value($row, $headers, 'description'));
            $rowErrors = [];

            if ($code === '' || preg_match('/^[A-Z0-9_-]{1,30}$/', $code) !== 1) {
                $rowErrors[] = 'Kode produk wajib diisi, maksimal 30 karakter, dan hanya boleh berisi huruf, angka, _ atau -.';
            }
            if ($name === '' || mb_strlen($name) > 150) {
                $rowErrors[] = 'Nama produk wajib diisi dan maksimal 150 karakter.';
            }
            if (mb_strlen($category) > 100) {
                $rowErrors[] = 'Kategori maksimal 100 karakter.';
            }
            if (mb_strlen($description) > 2000) {
                $rowErrors[] = 'Deskripsi maksimal 2.000 karakter.';
            }

            $unitId = $units[mb_strtolower($unitLabel)] ?? null;
            if (! $unitId) {
                $rowErrors[] = "Satuan '{$unitLabel}' tidak terdaftar di sistem.";
            }

            $stock = $this->fixedPointValue($this->value($row, $headers, 'stock'), 'Stok awal', $rowErrors);
            $minimumStock = $this->fixedPointValue($this->value($row, $headers, 'minimum_stock'), 'Stok minimum', $rowErrors);
            $sellingPrice = $this->priceValue($this->value($row, $headers, 'selling_price'), $rowErrors);
            $isActive = $this->activeValue($this->value($row, $headers, 'status'), $rowErrors);

            if ($code !== '' && (isset($existingCodes[$code]) || isset($seenCodes[$code]))) {
                $rowErrors[] = "Kode produk {$code} sudah ada; data lama tidak ditimpa.";
            }

            if ($rowErrors !== []) {
                $issuesTotal++;
                if (count($issues) < 50) {
                    $issues[] = ['row' => $rowNumber, 'message' => implode(' ', $rowErrors)];
                }
                continue;
            }

            $seenCodes[$code] = true;
            $records[] = [
                'code' => $code,
                'name' => $name,
                'category' => $category !== '' ? $category : null,
                'unit_id' => (int) $unitId,
                'stock' => $stock,
                'minimum_stock' => $minimumStock,
                'selling_price' => $sellingPrice,
                'is_active' => $isActive,
                'description' => $description !== '' ? $description : null,
            ];
        }

        if ($dataRows === 0) {
            throw ValidationException::withMessages([
                'file' => ['File belum berisi baris data produk.'],
            ]);
        }

        $now = now();
        DB::transaction(function () use ($records, $userId, $now): void {
            foreach ($records as $record) {
                $id = DB::table('products')->insertGetId([
                    'code' => $record['code'],
                    'name' => $record['name'],
                    'category' => $record['category'],
                    'purchase_unit_id' => $record['unit_id'],
                    'usage_unit_id' => $record['unit_id'],
                    'purchase_to_usage_factor' => FixedPoint::format(FixedPoint::parse('1', FixedPoint::STOCK_SCALE), FixedPoint::STOCK_SCALE),
                    'current_stock' => FixedPoint::format($record['stock'], FixedPoint::STOCK_SCALE),
                    'minimum_stock' => FixedPoint::format($record['minimum_stock'], FixedPoint::STOCK_SCALE),
                    'selling_price' => $record['selling_price'],
                    'is_active' => $record['is_active'],
                    'description' => $record['description'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                if ($record['stock'] > 0) {
                    DB::table('stock_movements')->insert([
                        'product_id' => $id,
                        'unit_id' => $record['unit_id'],
                        'type' => 'in',
                        'quantity' => FixedPoint::format($record['stock'], FixedPoint::STOCK_SCALE),
                        'stock_before' => FixedPoint::format(0, FixedPoint::STOCK_SCALE),
                        'stock_after' => FixedPoint::format($record['stock'], FixedPoint::STOCK_SCALE),
                        'source_type' => 'opening_stock_import',
                        'reference' => 'IMPORT-'.$record['code'],
                        'notes' => 'Stok awal dari import Excel',
                        'occurred_at' => $now,
                        'created_by' => $userId,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }
        }, 3);

        return [
            'imported' => count($records),
            'skipped' => $issuesTotal,
            'issues_total' => $issuesTotal,
            'issues' => $issues,
        ];
    }

    /** @return array<int, array<int, string>> */
    private function csvRows(string $path): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw ValidationException::withMessages(['file' => ['File CSV tidak dapat dibaca.']]);
        }

        try {
            $firstLine = (string) fgets($handle);
            rewind($handle);
            $delimiter = collect([',', ';', "\t"])
                ->sortByDesc(fn (string $candidate): int => count(str_getcsv($firstLine, $candidate, '"', '')))
                ->first();
            $rows = [];
            while (($row = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
                $rows[] = array_map(fn ($value): string => trim((string) $value), $row);
            }

            return $rows;
        } finally {
            fclose($handle);
        }
    }

    /** @return array<int, array<int, string>> */
    private function xlsxRows(string $path): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw ValidationException::withMessages(['file' => ['File Excel tidak valid atau rusak.']]);
        }

        try {
            $sheetPath = $this->firstWorksheetPath($zip);
            $sheetXml = $this->safeZipEntry($zip, $sheetPath, 'lembar kerja Excel');
            $sharedStrings = $this->sharedStrings($zip);
            $sheet = $this->xml($sheetXml, 'lembar kerja Excel');
            $namespace = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
            $sheet->registerXPathNamespace('m', $namespace);
            $xmlRows = $sheet->xpath('//m:sheetData/m:row') ?: [];
            $rows = [];

            foreach ($xmlRows as $xmlRow) {
                $row = [];
                $nextColumn = 0;
                $xmlRow->registerXPathNamespace('m', $namespace);
                foreach ($xmlRow->xpath('./m:c') ?: [] as $cell) {
                    $attributes = $cell->attributes();
                    $reference = (string) ($attributes['r'] ?? '');
                    $column = $reference !== '' ? $this->columnIndex($reference) : $nextColumn;
                    $type = (string) ($attributes['t'] ?? '');
                    $cell->registerXPathNamespace('m', $namespace);
                    $valueNode = $cell->xpath('./m:v')[0] ?? null;
                    $value = (string) ($valueNode ?? '');

                    if ($type === 's') {
                        $value = $sharedStrings[(int) $value] ?? '';
                    } elseif ($type === 'inlineStr') {
                        $value = implode('', array_map(
                            fn (SimpleXMLElement $node): string => (string) $node,
                            $cell->xpath('.//m:is//m:t') ?: [],
                        ));
                    } elseif ($type === 'b') {
                        $value = $value === '1' ? '1' : '0';
                    }

                    $row[$column] = trim($value);
                    $nextColumn = $column + 1;
                }

                if ($row !== []) {
                    $maxColumn = max(array_keys($row));
                    $rows[] = array_map(
                        fn (int $column): string => (string) ($row[$column] ?? ''),
                        range(0, $maxColumn),
                    );
                } else {
                    $rows[] = [];
                }
            }

            return $rows;
        } finally {
            $zip->close();
        }
    }

    private function firstWorksheetPath(ZipArchive $zip): string
    {
        $fallback = 'xl/worksheets/sheet1.xml';
        $workbookXml = $zip->getFromName('xl/workbook.xml');
        $relationshipsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($workbookXml === false || $relationshipsXml === false) {
            return $fallback;
        }

        $workbook = $this->xml($workbookXml, 'struktur workbook');
        $mainNamespace = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $relationshipNamespace = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
        $workbook->registerXPathNamespace('m', $mainNamespace);
        $sheets = $workbook->xpath('//m:sheets/m:sheet') ?: [];
        if ($sheets === []) {
            return $fallback;
        }

        $relationshipId = (string) $sheets[0]->attributes($relationshipNamespace)['id'];
        $relationships = $this->xml($relationshipsXml, 'relasi workbook');
        $relationships->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/package/2006/relationships');
        foreach ($relationships->xpath('//r:Relationship') ?: [] as $relationship) {
            $attributes = $relationship->attributes();
            if ((string) $attributes['Id'] !== $relationshipId) {
                continue;
            }

            $target = str_replace('\\', '/', (string) $attributes['Target']);
            if (str_starts_with($target, '/')) {
                return ltrim($target, '/');
            }

            return $this->normalizeZipPath('xl/'.$target);
        }

        return $fallback;
    }

    /** @return array<int, string> */
    private function sharedStrings(ZipArchive $zip): array
    {
        if ($zip->locateName('xl/sharedStrings.xml') === false) {
            return [];
        }

        $xml = $this->xml($this->safeZipEntry($zip, 'xl/sharedStrings.xml', 'shared strings Excel'), 'shared strings Excel');
        $namespace = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $xml->registerXPathNamespace('m', $namespace);

        return array_map(function (SimpleXMLElement $item) use ($namespace): string {
            $item->registerXPathNamespace('m', $namespace);

            return implode('', array_map(
                fn (SimpleXMLElement $node): string => (string) $node,
                $item->xpath('.//m:t') ?: [],
            ));
        }, $xml->xpath('//m:si') ?: []);
    }

    private function safeZipEntry(ZipArchive $zip, string $name, string $label): string
    {
        $stat = $zip->statName($name);
        if ($stat === false || ($stat['size'] ?? 0) > self::MAX_XML_BYTES) {
            throw ValidationException::withMessages(['file' => ["Ukuran {$label} tidak valid."]]);
        }

        $contents = $zip->getFromName($name);
        if ($contents === false) {
            throw ValidationException::withMessages(['file' => ["{$label} tidak ditemukan."]]);
        }

        return $contents;
    }

    private function xml(string $contents, string $label): SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_string($contents, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
            if ($xml === false) {
                throw ValidationException::withMessages(['file' => ["Struktur {$label} tidak valid."]]);
            }

            return $xml;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function normalizeZipPath(string $path): string
    {
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = $part;
        }

        return implode('/', $parts);
    }

    private function columnIndex(string $reference): int
    {
        preg_match('/^[A-Z]+/i', $reference, $matches);
        $letters = mb_strtoupper($matches[0] ?? 'A');
        $index = 0;
        foreach (str_split($letters) as $letter) {
            $index = ($index * 26) + (ord($letter) - 64);
        }

        return max(0, $index - 1);
    }

    /** @param array<int, array<int, string>> $rows @return array{int, array<string, int>} */
    private function findHeaders(array $rows): array
    {
        $required = ['code', 'name', 'unit', 'stock', 'minimum_stock', 'selling_price'];
        foreach (array_slice($rows, 0, 20, true) as $rowIndex => $row) {
            $headers = [];
            foreach ($row as $column => $value) {
                $header = $this->canonicalHeader($value);
                if ($header !== null && ! isset($headers[$header])) {
                    $headers[$header] = $column;
                }
            }

            if (collect($required)->every(fn (string $header): bool => isset($headers[$header]))) {
                return [(int) $rowIndex, $headers];
            }
        }

        throw ValidationException::withMessages([
            'file' => ['Header Excel tidak sesuai. Gunakan kolom kode produk, nama produk, satuan, stok awal, stok minimum, dan harga jual.'],
        ]);
    }

    private function canonicalHeader(string $value): ?string
    {
        $header = mb_strtolower(trim(str_replace("\xEF\xBB\xBF", '', $value)));
        $header = trim((string) preg_replace('/[^a-z0-9]+/u', '_', $header), '_');

        return match ($header) {
            'kode', 'kode_produk', 'code', 'product_code' => 'code',
            'nama', 'nama_produk', 'name', 'product_name' => 'name',
            'kategori', 'category' => 'category',
            'satuan', 'unit', 'unit_code' => 'unit',
            'stok', 'stok_awal', 'current_stock', 'opening_stock' => 'stock',
            'stok_minimum', 'minimum_stok', 'batas_minimum', 'minimum_stock' => 'minimum_stock',
            'harga', 'harga_jual', 'selling_price' => 'selling_price',
            'status', 'aktif', 'is_active' => 'status',
            'deskripsi', 'description', 'catatan' => 'description',
            default => null,
        };
    }

    /** @return array<string, int> */
    private function unitMap(): array
    {
        $map = [];
        foreach (DB::table('units')->get(['id', 'code', 'name']) as $unit) {
            $map[mb_strtolower(trim($unit->code))] = (int) $unit->id;
            $map[mb_strtolower(trim($unit->name))] = (int) $unit->id;
        }

        return $map;
    }

    /** @param array<int, string> $row @param array<string, int> $headers */
    private function value(array $row, array $headers, string $key): string
    {
        return isset($headers[$key]) ? trim((string) ($row[$headers[$key]] ?? '')) : '';
    }

    /** @param array<int, string> $row */
    private function rowIsEmpty(array $row): bool
    {
        return collect($row)->every(fn ($value): bool => trim((string) $value) === '');
    }

    /** @param array<int, string> $errors */
    private function fixedPointValue(string $value, string $label, array &$errors): ?int
    {
        $normalized = $this->normalizeDecimal($value);
        if ($normalized === null || preg_match('/^\d{1,14}(?:\.\d{1,4})?$/', $normalized) !== 1) {
            $errors[] = "{$label} harus berupa angka positif dengan maksimal 4 angka desimal.";

            return null;
        }

        try {
            return FixedPoint::parse($normalized, FixedPoint::STOCK_SCALE);
        } catch (InvalidArgumentException) {
            $errors[] = "{$label} tidak valid atau terlalu besar.";

            return null;
        }
    }

    /** @param array<int, string> $errors */
    private function priceValue(string $value, array &$errors): ?int
    {
        $normalized = $this->normalizeDecimal((string) preg_replace('/^\s*rp\.?\s*/i', '', $value));
        if ($normalized === null || preg_match('/^\d+(?:\.0+)?$/', $normalized) !== 1) {
            $errors[] = 'Harga jual harus berupa bilangan bulat positif.';

            return null;
        }

        $price = (int) $normalized;
        if ($price > 999999999999) {
            $errors[] = 'Harga jual terlalu besar.';

            return null;
        }

        return $price;
    }

    /** @param array<int, string> $errors */
    private function activeValue(string $value, array &$errors): bool
    {
        $status = mb_strtolower(trim($value));
        if ($status === '' || in_array($status, ['aktif', 'active', '1', 'ya', 'yes'], true)) {
            return true;
        }
        if (in_array($status, ['nonaktif', 'non-aktif', 'inactive', '0', 'tidak', 'no'], true)) {
            return false;
        }

        $errors[] = "Status '{$value}' tidak dikenali; gunakan AKTIF atau NONAKTIF.";

        return true;
    }

    private function normalizeDecimal(string $value): ?string
    {
        $value = preg_replace('/\s+/u', '', trim($value));
        if ($value === null || $value === '') {
            return null;
        }

        if (preg_match('/^\d{1,3}(?:[.,]\d{3})+$/', $value) === 1) {
            return str_replace([',', '.'], '', $value);
        }
        if (str_contains($value, ',') && str_contains($value, '.')) {
            if (strrpos($value, ',') > strrpos($value, '.')) {
                return str_replace(',', '.', str_replace('.', '', $value));
            }

            return str_replace(',', '', $value);
        }

        return str_replace(',', '.', $value);
    }
}
