<?php

namespace App\Enums;

/**
 * S-31: con qué código se vendió la unidad. 'etiqueta' = la etiqueta del local (RF-03, la unidad
 * exacta); 'referencia' = el código del proveedor o del fabricante (S-25) y el sistema eligió la
 * unidad. Independiente de MetodoCaptura (escaneado | digitado).
 */
enum OrigenCodigo: string
{
    case Etiqueta = 'etiqueta';
    case Referencia = 'referencia';
}
