<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Response;

/**
 * Exportación a CSV (separador «;» y BOM para Excel en español) y XLSX
 * (Office Open XML mínimo con ZipArchive). Toda celda que empiece por = + - @
 * se neutraliza para evitar inyección de fórmulas.
 */
final class Export
{
    public static function safeCell(mixed $v): string|int|float
    {
        if ($v === null) {
            return '';
        }
        if (is_int($v) || is_float($v)) {
            return $v;
        }
        $s = (string)$v;
        if ($s !== '' && in_array($s[0], ['=', '+', '-', '@', "\t", "\r"], true) && !is_numeric($s)) {
            return "'" . $s;
        }
        return $s;
    }

    /** @param list<string> $headers @param iterable<list<mixed>> $rows */
    public static function csv(string $filename, array $headers, iterable $rows): Response
    {
        return Response::stream(static function () use ($headers, $rows): void {
            $out = fopen('php://output', 'wb');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $headers, ';', '"', '');
            foreach ($rows as $r) {
                fputcsv($out, array_map(static fn ($v) => is_float($v) ? str_replace('.', ',', (string)$v) : self::safeCell($v), $r), ';', '"', '');
            }
            fclose($out);
        }, 'text/csv; charset=UTF-8', $filename);
    }

    /** @param list<string> $headers @param iterable<list<mixed>> $rows */
    public static function xlsx(string $filename, string $sheetName, array $headers, iterable $rows, string $tmpDir): Response
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('La exportación a Excel necesita la extensión zip de PHP. Usa CSV.');
        }
        if (!is_dir($tmpDir)) {
            @mkdir($tmpDir, 0750, true);
        }
        $tmp = tempnam($tmpDir, 'xlsx');
        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::OVERWRITE);
        $sheetName = mb_substr((string)preg_replace('/[\[\]\*\?\/\\\\:]/', ' ', $sheetName), 0, 31) ?: 'Datos';

        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><sheetData>';
        $xml .= self::row(1, $headers, true);
        $i = 2;
        foreach ($rows as $r) {
            $xml .= self::row($i++, $r, false);
        }
        $xml .= '</sheetData><autoFilter ref="A1:' . self::col(count($headers)) . max(1, $i - 1) . '"/></worksheet>';

        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="' . htmlspecialchars($sheetName, ENT_XML1) . '" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
        $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills><borders count="1"><border/></borders><cellStyleXfs count="1"><xf/></cellStyleXfs><cellXfs count="2"><xf fontId="0"/><xf fontId="1" applyFont="1"/></cellXfs></styleSheet>');
        $zip->addFromString('xl/worksheets/sheet1.xml', $xml);
        $zip->close();

        $r = Response::file($tmp, $filename, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        register_shutdown_function(static fn () => @unlink($tmp));
        return $r;
    }

    /** @param list<mixed> $values */
    private static function row(int $n, array $values, bool $bold): string
    {
        $x = '<row r="' . $n . '">';
        foreach (array_values($values) as $i => $v) {
            $ref = self::col($i + 1) . $n;
            $style = $bold ? ' s="1"' : '';
            $v = self::safeCell($v);
            if (is_int($v) || is_float($v)) {
                $x .= '<c r="' . $ref . '"' . $style . '><v>' . $v . '</v></c>';
            } else {
                $clean = (string)preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', $v);
                $x .= '<c r="' . $ref . '" t="inlineStr"' . $style . '><is><t xml:space="preserve">' . htmlspecialchars($clean, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</t></is></c>';
            }
        }
        return $x . '</row>';
    }

    private static function col(int $n): string
    {
        $s = '';
        while ($n > 0) {
            $m = ($n - 1) % 26;
            $s = chr(65 + $m) . $s;
            $n = intdiv($n - 1, 26);
        }
        return $s;
    }
}
