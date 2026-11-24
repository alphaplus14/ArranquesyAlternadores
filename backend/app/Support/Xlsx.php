<?php

namespace App\Support;

use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

/**
 * RF-23: libro de Excel (.xlsx) de una sola hoja, sin dependencias: el formato es un ZIP con XML
 * (Office Open XML). Encabezado en negrilla y fijo; los números van como números para que la
 * auxiliar contable pueda sumar y filtrar en Excel; el resto, como texto.
 */
final class Xlsx
{
    /**
     * @param  list<string>  $encabezados
     * @param  iterable<list<string|int|float|null>>  $filas
     */
    public static function descargar(string $archivo, string $hoja, array $encabezados, iterable $filas): BinaryFileResponse
    {
        $ruta = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive;
        if ($zip->open($ruta, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('No se pudo crear el archivo de Excel.');
        }

        $zip->addFromString('[Content_Types].xml', self::tipos());
        $zip->addFromString('_rels/.rels', self::relaciones());
        $zip->addFromString('xl/workbook.xml', self::libro($hoja));
        $zip->addFromString('xl/_rels/workbook.xml.rels', self::relacionesLibro());
        $zip->addFromString('xl/styles.xml', self::estilos());
        $zip->addFromString('xl/worksheets/sheet1.xml', self::hoja($encabezados, $filas));
        $zip->close();

        return response()->download($ruta, $archivo, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend();
    }

    /**
     * @param  list<string>  $encabezados
     * @param  iterable<list<string|int|float|null>>  $filas
     */
    private static function hoja(array $encabezados, iterable $filas): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            .'<sheetData>';

        $xml .= self::fila(1, $encabezados, 1);
        $n = 2;
        foreach ($filas as $fila) {
            $xml .= self::fila($n++, $fila);
        }

        return $xml.'</sheetData></worksheet>';
    }

    /** @param  list<string|int|float|null>  $celdas */
    private static function fila(int $n, array $celdas, int $estilo = 0): string
    {
        $xml = "<row r=\"{$n}\">";
        foreach (array_values($celdas) as $i => $valor) {
            $ref = self::columna($i).$n;
            $s = $estilo ? " s=\"{$estilo}\"" : '';
            if ($valor === null || $valor === '') {
                $xml .= "<c r=\"{$ref}\"{$s}/>";
            } elseif (is_int($valor) || is_float($valor)) {
                $xml .= "<c r=\"{$ref}\"{$s}><v>{$valor}</v></c>";
            } else {
                $texto = htmlspecialchars((string) $valor, ENT_XML1 | ENT_QUOTES, 'UTF-8');
                $xml .= "<c r=\"{$ref}\"{$s} t=\"inlineStr\"><is><t xml:space=\"preserve\">{$texto}</t></is></c>";
            }
        }

        return $xml.'</row>';
    }

    /** 0 → A, 25 → Z, 26 → AA. */
    private static function columna(int $i): string
    {
        $letras = '';
        for ($i++; $i > 0; $i = intdiv($i - 1, 26)) {
            $letras = chr(65 + ($i - 1) % 26).$letras;
        }

        return $letras;
    }

    private static function tipos(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .'</Types>';
    }

    private static function relaciones(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>';
    }

    private static function libro(string $hoja): string
    {
        // Excel no admite en el nombre de la hoja : \ / ? * [ ] ni más de 31 caracteres.
        $nombre = htmlspecialchars(mb_substr(preg_replace('/[:\\\\\/?*\[\]]/', ' ', $hoja), 0, 31), ENT_XML1 | ENT_QUOTES, 'UTF-8');

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            ."<sheets><sheet name=\"{$nombre}\" sheetId=\"1\" r:id=\"rId1\"/></sheets>"
            .'</workbook>';
    }

    private static function relacionesLibro(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            .'</Relationships>';
    }

    private static function estilos(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            .'<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            .'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs>'
            .'</styleSheet>';
    }
}
