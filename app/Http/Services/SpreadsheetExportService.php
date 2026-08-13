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
     * @param  array<int, int>  $currencyColumns Zero-based column indexes.
     */
    public function make(string $sheetName, string $title, array $headers, array $rows, array $currencyColumns = []): string
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'selesa-xlsx-');
        if ($tempFile === false) {
            throw new RuntimeException('File ekspor tidak dapat disiapkan.');
        }

        try {
            $zip = new ZipArchive();
            if ($zip->open($tempFile, ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('File Excel tidak dapat dibuat.');
            }

            $zip->addFromString('[Content_Types].xml', $this->contentTypes());
            $zip->addFromString('_rels/.rels', $this->rootRelationships());
            $zip->addFromString('xl/workbook.xml', $this->workbook($sheetName));
            $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRelationships());
            $zip->addFromString('xl/styles.xml', $this->styles());
            $zip->addFromString('xl/worksheets/sheet1.xml', $this->worksheet($title, $headers, $rows, $currencyColumns));
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
            .'</styleSheet>';
    }
}
