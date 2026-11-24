<?php

namespace App\Services;

use App\Models\Unidad;
use Barryvdh\DomPDF\Facade\Pdf;
use Picqer\Barcode\Renderers\PngRenderer;
use Picqer\Barcode\Types\TypeCode128;

/**
 * RF-03: PDF con una etiqueta por unidad, del tamaño elegido (config/etiquetas.php, supuesto S-05).
 * Una página por etiqueta: la impresora de etiquetas avanza una etiqueta por página.
 */
class EtiquetaService
{
    private const PUNTOS_POR_MM = 72 / 25.4;

    /** @param list<int> $unidadIds */
    public function generar(array $unidadIds, string $tamano): string
    {
        $medidas = config("etiquetas.tamanos.{$tamano}");

        $unidades = Unidad::query()
            ->whereIn('id', $unidadIds)
            ->with('referencia:id,codigo_referencia,descripcion,proveedor_id,codigo_universal_id', 'referencia.proveedor:id,nombre', 'referencia.codigoUniversal:id,codigo')
            ->orderBy('id')
            ->get(['id', 'referencia_id', 'codigo_barras']);

        $tipo = new TypeCode128;
        $renderer = (new PngRenderer)->useGd();

        $etiquetas = $unidades->map(fn (Unidad $u) => [
            'codigo' => $u->codigo_barras,
            'barras' => base64_encode($renderer->render($tipo->getBarcode($u->codigo_barras), 600, 160)),
            'referencia' => $u->referencia->codigo_referencia,
            'universal' => $u->referencia->codigoUniversal?->codigo,
            'proveedor' => $u->referencia->proveedor->nombre, // S-23: en lugar de la marca
            'descripcion' => $u->referencia->descripcion,
        ]);

        // S-31: la primera impresión marca la unidad como etiquetada (las reimpresiones no la
        // cambian). Al vender por código del proveedor se prefieren las unidades sin etiqueta.
        Unidad::query()->whereIn('id', $unidades->pluck('id'))->whereNull('etiqueta_impresa_at')
            ->update(['etiqueta_impresa_at' => now()]);

        return Pdf::loadView('etiquetas.pdf', [
            'etiquetas' => $etiquetas,
            'tamano' => $tamano,
            'medidas' => $medidas,
        ])
            ->setPaper([0, 0, $medidas['ancho'] * self::PUNTOS_POR_MM, $medidas['alto'] * self::PUNTOS_POR_MM])
            ->output();
    }
}
