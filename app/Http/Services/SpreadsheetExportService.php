<?php

namespace App\Http\Services;

use RuntimeException;
use ZipArchive;

class SpreadsheetExportService
{
    /**
     * Builds a compact, native .xlsx workbook without requiring a desktop Excel
     * installation or a third-party package on the production server.
     *
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, mixed>>  $rows
     * @param  array<int, int>  $currencyColumns  Zero-based column indexes.
     */
    public function make(string $sheetName, string $title, array $headers, array $rows, array $currencyColumns = []): string
    {
        return $this->archive(
            $sheetName,
            $this->worksheet($title, $headers, $rows, $currencyColumns),
            $this->styles(),
        );
    }

    /**
     * Builds the daily schedule in the same operational layout as the salon's
     * manual BOOKING workbook.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<int, array{name: string, commission: int, overtime: int}>  $staffSummary
     * @param  array<int, array{method: string, amount: int}>  $paymentSummary
     */
    public function makeDailySchedule(
        string $sheetName,
        string $dateLabel,
        array $rows,
        array $staffSummary,
        array $paymentSummary,
    ): string {
        return $this->archive(
            $sheetName,
            $this->dailyScheduleWorksheet($dateLabel, $rows, $staffSummary, $paymentSummary),
            $this->dailyScheduleStyles(),
        );
    }

    /** @param array<int, array<string, mixed>> $rows */
    public function makeStockInOut(array $rows): string
    {
        return $this->archive(
            'REKAP STOK IN-OUT',
            $this->stockInOutWorksheet($rows),
            $this->stockInOutStyles(),
        );
    }

    private function archive(string $sheetName, string $worksheet, string $styles): string
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'selesa-xlsx-');
        if ($tempFile === false) {
            throw new RuntimeException('File ekspor tidak dapat disiapkan.');
        }

        try {
            $zip = new ZipArchive;
            if ($zip->open($tempFile, ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('File Excel tidak dapat dibuat.');
            }

            $zip->addFromString('[Content_Types].xml', $this->contentTypes());
            $zip->addFromString('_rels/.rels', $this->rootRelationships());
            $zip->addFromString('xl/workbook.xml', $this->workbook($sheetName));
            $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRelationships());
            $zip->addFromString('xl/styles.xml', $styles);
            $zip->addFromString('xl/worksheets/sheet1.xml', $worksheet);
            $zip->close();

            $contents = file_get_contents($tempFile);
            if ($contents === false) {
                throw new RuntimeException('File Excel tidak dapat dibaca.');
            }

            return $contents;
        } finally {
            if (is_file($tempFile)) {
                @unlink($tempFile);
            }
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<int, array{name: string, commission: int, overtime: int}>  $staffSummary
     * @param  array<int, array{method: string, amount: int}>  $paymentSummary
     */
    private function dailyScheduleWorksheet(
        string $dateLabel,
        array $rows,
        array $staffSummary,
        array $paymentSummary,
    ): string {
        $cells = [];
        $heights = [1 => 15.6, 2 => 15.6, 3 => 15, 4 => 15.75];
        $merges = [];
        $put = function (int $row, string $cell) use (&$cells): void {
            $cells[$row][] = $cell;
        };

        $put(1, $this->cell('A1', 'BOOKING', 1));
        $put(2, $this->cell('A2', $dateLabel, 1));

        $headers = ['NAMA', 'TREATMENT', 'MULAI', 'SELESAI', 'READY', 'THERAPIST', 'PAYMENT', 'NOMINAL', 'NOMINAL SATUAN', 'KOMISI SATUAN'];
        foreach ($headers as $index => $header) {
            $column = $this->columnName($index + 1);
            $put(3, $this->cell($column.'3', $header, 2));
            $put(4, $this->cell($column.'4', '', 2));
            $merges[] = $column.'3:'.$column.'4';
        }

        foreach (['L' => 'NAMA', 'M' => 'KOMISI', 'N' => 'LEMBUR', 'O' => 'TOTAL'] as $column => $header) {
            $put(4, $this->cell($column.'4', $header, 6));
        }

        $currentReservation = null;
        $reservationStart = null;
        $reservationSpans = [];
        foreach (array_values($rows) as $index => $row) {
            $rowNumber = $index + 5;
            $reservationId = (int) ($row['reservation_id'] ?? $index + 1);
            $firstForReservation = $currentReservation !== $reservationId;
            if ($firstForReservation) {
                if ($reservationStart !== null) {
                    $reservationSpans[] = [$reservationStart, $rowNumber - 1];
                }
                $currentReservation = $reservationId;
                $reservationStart = $rowNumber;
            }

            $put($rowNumber, $this->cell('A'.$rowNumber, $firstForReservation ? ($row['customer_name'] ?? '-') : '', 3));
            $put($rowNumber, $this->cell('B'.$rowNumber, $row['treatment_name'] ?? '-', 3));
            $put($rowNumber, $this->timeCell('C'.$rowNumber, $row['start_time'] ?? null));
            $put($rowNumber, $this->timeCell('D'.$rowNumber, $row['end_time'] ?? null));
            $put($rowNumber, $this->timeCell('E'.$rowNumber, $row['ready_time'] ?? null));
            $put($rowNumber, $this->cell('F'.$rowNumber, $row['therapists'] ?? '-', 3));
            $put($rowNumber, $this->cell('G'.$rowNumber, $firstForReservation ? ($row['payment'] ?? '-') : '', 3));
            $put($rowNumber, $this->cell('H'.$rowNumber, $firstForReservation ? (int) ($row['reservation_total'] ?? 0) : '', 5));
            $put($rowNumber, $this->cell('I'.$rowNumber, (int) ($row['unit_price'] ?? 0), 5));
            $put($rowNumber, $this->cell('J'.$rowNumber, (int) ($row['commission_amount'] ?? 0), 5));
            $heights[$rowNumber] = 30;
        }
        if ($reservationStart !== null) {
            $reservationSpans[] = [$reservationStart, count($rows) + 4];
        }
        foreach ($reservationSpans as [$start, $end]) {
            if ($end > $start) {
                foreach (['A', 'G', 'H'] as $column) {
                    $merges[] = $column.$start.':'.$column.$end;
                }
            }
        }

        if ($rows === []) {
            for ($column = 1; $column <= 10; $column++) {
                $put(5, $this->cell($this->columnName($column).'5', $column === 1 ? 'BELUM ADA JADWAL' : '', 3));
            }
            $merges[] = 'A5:J5';
            $heights[5] = 30;
        } else {
            $mainTotalRow = count($rows) + 5;
            $put($mainTotalRow, $this->cell('H'.$mainTotalRow, 'TOTAL', 10));
            $put($mainTotalRow, $this->cell('I'.$mainTotalRow, array_sum(array_column($rows, 'unit_price')), 9));
            $put($mainTotalRow, $this->cell('J'.$mainTotalRow, array_sum(array_column($rows, 'commission_amount')), 9));
            $heights[$mainTotalRow] = 20;
        }

        foreach (array_values($staffSummary) as $index => $summary) {
            $rowNumber = $index + 5;
            $commission = (int) ($summary['commission'] ?? 0);
            $overtime = (int) ($summary['overtime'] ?? 0);
            $put($rowNumber, $this->cell('L'.$rowNumber, $summary['name'] ?? '-', 7));
            $put($rowNumber, $this->cell('M'.$rowNumber, $commission, 8));
            $put($rowNumber, $this->cell('N'.$rowNumber, $overtime, 8));
            $put($rowNumber, $this->cell('O'.$rowNumber, $commission + $overtime, 8));
        }
        $staffTotalRow = count($staffSummary) + 6;
        $put($staffTotalRow, $this->cell('L'.$staffTotalRow, 'TOTAL', 10));
        $put($staffTotalRow, $this->cell('M'.$staffTotalRow, array_sum(array_column($staffSummary, 'commission')), 9));
        $put($staffTotalRow, $this->cell('N'.$staffTotalRow, array_sum(array_column($staffSummary, 'overtime')), 9));
        $put($staffTotalRow, $this->cell('O'.$staffTotalRow, array_sum(array_map(
            fn (array $summary): int => (int) ($summary['commission'] ?? 0) + (int) ($summary['overtime'] ?? 0),
            $staffSummary,
        )), 9));

        $paymentStartRow = max(15, $staffTotalRow + 2);
        foreach (array_values($paymentSummary) as $index => $summary) {
            $rowNumber = $paymentStartRow + $index;
            $put($rowNumber, $this->cell('L'.$rowNumber, $summary['method'] ?? '-', 7));
            $put($rowNumber, $this->cell('M'.$rowNumber, (int) ($summary['amount'] ?? 0), 8));
        }
        $paymentTotalRow = $paymentStartRow + count($paymentSummary) + 1;
        $put($paymentTotalRow, $this->cell('L'.$paymentTotalRow, 'TOTAL PEMBAYARAN', 10));
        $put($paymentTotalRow, $this->cell('M'.$paymentTotalRow, array_sum(array_column($paymentSummary, 'amount')), 9));

        $lastRow = max(
            $rows === [] ? 5 : count($rows) + 5,
            $staffTotalRow,
            $paymentTotalRow,
        );
        $xmlRows = [];
        for ($row = 1; $row <= $lastRow; $row++) {
            $height = $heights[$row] ?? 20;
            $xmlRows[] = '<row r="'.$row.'" ht="'.$height.'" customHeight="1">'.implode('', $cells[$row] ?? []).'</row>';
        }
        $widths = [21.89, 26.44, 12, 12.33, 12.55, 15.11, 22, 15.55, 16, 11.89, 19.89, 18.44, 12.33, 10.55, 9.44];
        $columns = [];
        foreach ($widths as $index => $width) {
            $number = $index + 1;
            $columns[] = '<col min="'.$number.'" max="'.$number.'" width="'.$width.'" customWidth="1"/>';
        }
        $mergeXml = '<mergeCells count="'.count($merges).'">'
            .implode('', array_map(fn (string $range): string => '<mergeCell ref="'.$range.'"/>', $merges))
            .'</mergeCells>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<dimension ref="A1:O'.$lastRow.'"/>'
            .'<sheetViews><sheetView workbookViewId="0" zoomScale="68"/></sheetViews>'
            .'<sheetFormatPr defaultRowHeight="15.6"/>'
            .'<cols>'.implode('', $columns).'</cols>'
            .'<sheetData>'.implode('', $xmlRows).'</sheetData>'
            .$mergeXml
            .'<pageMargins left="0.7" right="0.7" top="0.75" bottom="0.75" header="0.3" footer="0.3"/>'
            .'</worksheet>';
    }

    private function timeCell(string $reference, mixed $value): string
    {
        if (preg_match('/(\d{2}):(\d{2})/', (string) $value, $matches) !== 1) {
            return $this->cell($reference, '-', 3);
        }

        $minutes = ((int) $matches[1] * 60) + (int) $matches[2];

        return '<c r="'.$reference.'" s="4"><v>'.($minutes / 1440).'</v></c>';
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function stockInOutWorksheet(array $rows): string
    {
        $headerLabels = [
            'A' => 'NO',
            'B' => 'PRODUK',
            'C' => 'TGL STOK MASUK',
            'D' => 'JML PROD. MASUK',
            'F' => 'BERAT GROSS PROD. MASUK',
            'H' => 'DOSIS PER CUST',
            'J' => 'JML STOK DOSIS PER CUST',
            'L' => 'TGL STOK KELUAR',
            'M' => 'JAM STOK KELUAR',
            'N' => 'STOK PROD. KELUAR PER CUST',
            'R' => 'SISA STOK PROD',
            'V' => 'CUSTOMER',
            'W' => 'TERAPIS',
        ];
        $merges = [
            'A1:A2', 'B1:B2', 'C1:C2', 'D1:E2', 'F1:G2', 'H1:I2', 'J1:K2',
            'L1:L2', 'M1:M2', 'N1:Q2', 'R1:U2', 'V1:V2', 'W1:W2',
        ];
        $xmlRows = [];
        for ($row = 1; $row <= 2; $row++) {
            $headerCells = [];
            for ($column = 1; $column <= 23; $column++) {
                $name = $this->columnName($column);
                $headerCells[] = $this->cell($name.$row, $row === 1 ? ($headerLabels[$name] ?? '') : '', 1);
            }
            $height = $row === 1 ? 43.2 : 15;
            $xmlRows[] = '<row r="'.$row.'" ht="'.$height.'" customHeight="1">'.implode('', $headerCells).'</row>';
        }

        foreach (array_values($rows) as $index => $row) {
            $rowNumber = $index + 3;
            $values = [
                ['value' => (int) ($row['number'] ?? $index + 1), 'style' => 3],
                ['value' => $row['product'] ?? '-', 'style' => 2],
                ['date' => $row['incoming_date'] ?? null],
                ['number' => $row['incoming_quantity'] ?? null],
                ['value' => $row['purchase_unit'] ?? '', 'style' => 3],
                ['number' => $row['gross_quantity'] ?? null],
                ['value' => $row['gross_unit'] ?? '', 'style' => 3],
                ['number' => $row['dose'] ?? null],
                ['value' => $row['dose_unit'] ?? '', 'style' => 3],
                ['number' => $row['capacity'] ?? null],
                ['value' => ($row['capacity'] ?? null) !== null ? 'PAX' : '', 'style' => 3],
                ['date' => $row['outgoing_date'] ?? null],
                ['time' => $row['outgoing_time'] ?? null],
                ['number' => $row['customers_served'] ?? null],
                ['value' => ($row['customers_served'] ?? null) !== null ? 'PAX' : '', 'style' => 3],
                ['number' => $row['outgoing_quantity'] ?? null],
                ['value' => $row['outgoing_unit'] ?? '', 'style' => 3],
                ['number' => $row['remaining_capacity'] ?? null],
                ['value' => ($row['remaining_capacity'] ?? null) !== null ? 'PAX' : '', 'style' => 3],
                ['number' => $row['stock_after'] ?? null],
                ['value' => $row['stock_unit'] ?? '', 'style' => 3],
                ['value' => $row['customer'] ?? '', 'style' => 3],
                ['value' => $row['therapists'] ?? '', 'style' => 3],
            ];
            $bodyCells = [];
            foreach ($values as $columnIndex => $definition) {
                $reference = $this->columnName($columnIndex + 1).$rowNumber;
                if (array_key_exists('date', $definition)) {
                    $bodyCells[] = $this->stockDateCell($reference, $definition['date']);
                } elseif (array_key_exists('time', $definition)) {
                    $bodyCells[] = $this->stockTimeCell($reference, $definition['time']);
                } elseif (array_key_exists('number', $definition)) {
                    $bodyCells[] = $definition['number'] === null
                        ? $this->cell($reference, '', 3)
                        : $this->cell($reference, (float) $definition['number'], 4);
                } else {
                    $bodyCells[] = $this->cell($reference, $definition['value'], $definition['style']);
                }
            }
            $xmlRows[] = '<row r="'.$rowNumber.'" ht="20" customHeight="1">'.implode('', $bodyCells).'</row>';
        }

        if ($rows === []) {
            $emptyCells = [];
            for ($column = 1; $column <= 23; $column++) {
                $emptyCells[] = $this->cell($this->columnName($column).'3', $column === 2 ? 'BELUM ADA PERGERAKAN STOK' : '', $column === 2 ? 2 : 3);
            }
            $xmlRows[] = '<row r="3" ht="24" customHeight="1">'.implode('', $emptyCells).'</row>';
        }

        $widths = [5, 23.89, 8.89, 5.89, 5.78, 8.11, 3.89, 8.22, 5, 7.78, 5.66, 9.33, 13, 6.66, 5.33, 13, 13, 7.89, 4.55, 7.78, 4.22, 10.78, 13];
        $columns = [];
        foreach ($widths as $index => $width) {
            $number = $index + 1;
            $columns[] = '<col min="'.$number.'" max="'.$number.'" width="'.$width.'" customWidth="1"/>';
        }
        $lastRow = max(3, count($rows) + 2);
        $mergeXml = '<mergeCells count="'.count($merges).'">'
            .implode('', array_map(fn (string $range): string => '<mergeCell ref="'.$range.'"/>', $merges))
            .'</mergeCells>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<dimension ref="A1:W'.$lastRow.'"/>'
            .'<sheetViews><sheetView workbookViewId="0"/></sheetViews>'
            .'<sheetFormatPr defaultRowHeight="15"/>'
            .'<cols>'.implode('', $columns).'</cols>'
            .'<sheetData>'.implode('', $xmlRows).'</sheetData>'
            .$mergeXml
            .'<pageMargins left="0.7" right="0.7" top="0.75" bottom="0.75" header="0.3" footer="0.3"/>'
            .'</worksheet>';
    }

    private function stockDateCell(string $reference, mixed $value): string
    {
        if (! $value) {
            return $this->cell($reference, '', 3);
        }

        try {
            $date = new \DateTimeImmutable((string) $value);
            $origin = new \DateTimeImmutable('1899-12-30');
            $serial = (int) $origin->diff($date)->format('%r%a');

            return '<c r="'.$reference.'" s="5"><v>'.$serial.'</v></c>';
        } catch (\Throwable) {
            return $this->cell($reference, (string) $value, 3);
        }
    }

    private function stockTimeCell(string $reference, mixed $value): string
    {
        if (preg_match('/(\d{2}):(\d{2})/', (string) $value, $matches) !== 1) {
            return $this->cell($reference, '', 3);
        }

        $minutes = ((int) $matches[1] * 60) + (int) $matches[2];

        return '<c r="'.$reference.'" s="6"><v>'.($minutes / 1440).'</v></c>';
    }

    /** @param array<int, string> $headers @param array<int, array<int, mixed>> $rows @param array<int, int> $currencyColumns */
    private function worksheet(string $title, array $headers, array $rows, array $currencyColumns): string
    {
        $columnCount = max(1, count($headers));
        $lastColumn = $this->columnName($columnCount);
        $lastRow = 4 + count($rows);
        $xmlRows = [];

        $xmlRows[] = '<row r="1" ht="27" customHeight="1">'.$this->cell('A1', $title, 1).'</row>';
        $xmlRows[] = '<row r="2" ht="18" customHeight="1">'.$this->cell('A2', 'Diekspor dari Selesa Salon · '.now()->translatedFormat('d F Y H:i'), 2).'</row>';
        $xmlRows[] = '<row r="3" ht="8" customHeight="1"/>';
        $xmlRows[] = '<row r="4" ht="28" customHeight="1">'.$this->rowCells(4, $headers, 3).'</row>';

        foreach ($rows as $index => $row) {
            $rowNumber = $index + 5;
            $cells = [];
            foreach (array_values($row) as $column => $value) {
                $style = in_array($column, $currencyColumns, true) ? 4 : 0;
                $cells[] = $this->cell($this->columnName($column + 1).$rowNumber, $value, $style);
            }
            $xmlRows[] = '<row r="'.$rowNumber.'" ht="20" customHeight="1">'.implode('', $cells).'</row>';
        }

        $widths = [];
        foreach ($headers as $index => $header) {
            $maxLength = mb_strlen($header);
            foreach ($rows as $row) {
                $maxLength = max($maxLength, mb_strlen((string) ($row[$index] ?? '')));
            }
            $width = min(36, max(11, $maxLength + 2));
            $number = $index + 1;
            $widths[] = '<col min="'.$number.'" max="'.$number.'" width="'.$width.'" customWidth="1"/>';
        }

        $filter = $lastRow >= 5 ? '<autoFilter ref="A4:'.$lastColumn.$lastRow.'"/>' : '';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<sheetViews><sheetView workbookViewId="0"><pane ySplit="4" topLeftCell="A5" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            .'<sheetFormatPr defaultRowHeight="15"/>'
            .'<cols>'.implode('', $widths).'</cols>'
            .'<sheetData>'.implode('', $xmlRows).'</sheetData>'
            .$filter
            // Open XML requires autoFilter to be written before mergeCells.
            // Reversing those elements makes Excel repair the downloaded file.
            .'<mergeCells count="2"><mergeCell ref="A1:'.$lastColumn.'1"/><mergeCell ref="A2:'.$lastColumn.'2"/></mergeCells>'
            .'<pageMargins left="0.3" right="0.3" top="0.5" bottom="0.5" header="0.2" footer="0.2"/>'
            .'</worksheet>';
    }

    /** @param array<int, mixed> $values */
    private function rowCells(int $row, array $values, int $style): string
    {
        $cells = [];
        foreach (array_values($values) as $index => $value) {
            $cells[] = $this->cell($this->columnName($index + 1).$row, $value, $style);
        }

        return implode('', $cells);
    }

    private function cell(string $reference, mixed $value, int $style): string
    {
        if (is_int($value) || is_float($value)) {
            return '<c r="'.$reference.'" s="'.$style.'"><v>'.$value.'</v></c>';
        }

        $text = htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        return '<c r="'.$reference.'" s="'.$style.'" t="inlineStr"><is><t xml:space="preserve">'.$text.'</t></is></c>';
    }

    private function columnName(int $number): string
    {
        $name = '';
        while ($number > 0) {
            $number--;
            $name = chr(65 + ($number % 26)).$name;
            $number = intdiv($number, 26);
        }

        return $name;
    }

    private function contentTypes(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .'</Types>';
    }

    private function rootRelationships(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>';
    }

    private function workbook(string $sheetName): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets><sheet name="'.htmlspecialchars(mb_substr($sheetName, 0, 31), ENT_XML1 | ENT_QUOTES, 'UTF-8').'" sheetId="1" r:id="rId1"/></sheets>'
            .'</workbook>';
    }

    private function workbookRelationships(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            .'</Relationships>';
    }

    private function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<numFmts count="1"><numFmt numFmtId="164" formatCode="&quot;Rp&quot; #,##0"/></numFmts>'
            .'<fonts count="3">'
            .'<font><sz val="10"/><color rgb="FF25233A"/><name val="Calibri"/></font>'
            .'<font><b/><sz val="14"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
            .'<font><sz val="10"/><color rgb="FF737082"/><name val="Calibri"/></font>'
            .'</fonts>'
            .'<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF6746D6"/><bgColor indexed="64"/></patternFill></fill></fills>'
            .'<borders count="1"><border><left/><right/><top/><bottom/></border></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="5">'
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"><alignment vertical="center"/></xf>'
            .'<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFill="1"><alignment horizontal="center" vertical="center"/></xf>'
            .'<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0"><alignment horizontal="center" vertical="center"/></xf>'
            .'<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFill="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
            .'<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"><alignment vertical="center"/></xf>'
            .'</cellXfs>'
            .'<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            .'</styleSheet>';
    }

    private function dailyScheduleStyles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<numFmts count="2">'
            .'<numFmt numFmtId="164" formatCode="#,##0;[Red]-#,##0;-"/>'
            .'<numFmt numFmtId="165" formatCode="h:mm"/>'
            .'</numFmts>'
            .'<fonts count="5">'
            .'<font><sz val="12"/><color rgb="FF000000"/><name val="Arial"/></font>'
            .'<font><b/><i/><sz val="11"/><color rgb="FF000000"/><name val="Calibri"/></font>'
            .'<font><b/><sz val="14"/><color rgb="FF000000"/><name val="Calibri"/></font>'
            .'<font><sz val="12"/><color rgb="FF000000"/><name val="Calibri"/></font>'
            .'<font><b/><sz val="11"/><color rgb="FFFF0000"/><name val="Calibri"/></font>'
            .'</fonts>'
            .'<fills count="4">'
            .'<fill><patternFill patternType="none"/></fill>'
            .'<fill><patternFill patternType="gray125"/></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FFFFFFFF"/><bgColor indexed="64"/></patternFill></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FFFFFF00"/><bgColor indexed="64"/></patternFill></fill>'
            .'</fills>'
            .'<borders count="2">'
            .'<border><left/><right/><top/><bottom/><diagonal/></border>'
            .'<border>'
            .'<left style="thin"><color rgb="FF000000"/></left>'
            .'<right style="thin"><color rgb="FF000000"/></right>'
            .'<top style="thin"><color rgb="FF000000"/></top>'
            .'<bottom style="thin"><color rgb="FF000000"/></bottom>'
            .'<diagonal/>'
            .'</border>'
            .'</borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="11">'
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"><alignment vertical="center"/></xf>'
            .'<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0"><alignment vertical="center"/></xf>'
            .'<xf numFmtId="0" fontId="2" fillId="0" borderId="1" xfId="0" applyBorder="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
            .'<xf numFmtId="0" fontId="0" fillId="2" borderId="1" xfId="0" applyFill="1" applyBorder="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
            .'<xf numFmtId="165" fontId="0" fillId="2" borderId="1" xfId="0" applyNumberFormat="1" applyFill="1" applyBorder="1"><alignment horizontal="center" vertical="center"/></xf>'
            .'<xf numFmtId="164" fontId="0" fillId="2" borderId="1" xfId="0" applyNumberFormat="1" applyFill="1" applyBorder="1"><alignment horizontal="center" vertical="center"/></xf>'
            .'<xf numFmtId="0" fontId="3" fillId="0" borderId="1" xfId="0" applyBorder="1"><alignment horizontal="center" vertical="center"/></xf>'
            .'<xf numFmtId="0" fontId="3" fillId="2" borderId="1" xfId="0" applyFill="1" applyBorder="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
            .'<xf numFmtId="164" fontId="3" fillId="2" borderId="1" xfId="0" applyNumberFormat="1" applyFill="1" applyBorder="1"><alignment vertical="center"/></xf>'
            .'<xf numFmtId="164" fontId="4" fillId="3" borderId="1" xfId="0" applyNumberFormat="1" applyFill="1" applyBorder="1"><alignment vertical="center"/></xf>'
            .'<xf numFmtId="0" fontId="4" fillId="3" borderId="1" xfId="0" applyFill="1" applyBorder="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
            .'</cellXfs>'
            .'<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            .'</styleSheet>';
    }

    private function stockInOutStyles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<numFmts count="3">'
            .'<numFmt numFmtId="164" formatCode="mm-dd-yy"/>'
            .'<numFmt numFmtId="165" formatCode="hh.mm"/>'
            .'<numFmt numFmtId="166" formatCode="0.####"/>'
            .'</numFmts>'
            .'<fonts count="3">'
            .'<font><sz val="11"/><color rgb="FF000000"/><name val="Calibri"/></font>'
            .'<font><b/><sz val="11"/><color rgb="FF000000"/><name val="Calibri"/></font>'
            .'<font><sz val="9"/><color rgb="FF000000"/><name val="Calibri"/></font>'
            .'</fonts>'
            .'<fills count="2">'
            .'<fill><patternFill patternType="none"/></fill>'
            .'<fill><patternFill patternType="gray125"/></fill>'
            .'</fills>'
            .'<borders count="2">'
            .'<border><left/><right/><top/><bottom/><diagonal/></border>'
            .'<border>'
            .'<left style="thin"><color rgb="FF000000"/></left>'
            .'<right style="thin"><color rgb="FF000000"/></right>'
            .'<top style="thin"><color rgb="FF000000"/></top>'
            .'<bottom style="thin"><color rgb="FF000000"/></bottom>'
            .'<diagonal/>'
            .'</border>'
            .'</borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="7">'
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"><alignment vertical="center"/></xf>'
            .'<xf numFmtId="0" fontId="1" fillId="0" borderId="1" xfId="0" applyBorder="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1"><alignment vertical="center" wrapText="1"/></xf>'
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
            .'<xf numFmtId="166" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1"><alignment horizontal="center" vertical="center"/></xf>'
            .'<xf numFmtId="164" fontId="2" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1"><alignment horizontal="center" vertical="center"/></xf>'
            .'<xf numFmtId="165" fontId="2" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1"><alignment horizontal="center" vertical="center"/></xf>'
            .'</cellXfs>'
            .'<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            .'</styleSheet>';
    }
}
