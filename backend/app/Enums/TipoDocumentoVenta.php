<?php

namespace App\Enums;

/** RF-14 (S-73): documento con que se registró una venta a crédito de solo valor. */
enum TipoDocumentoVenta: string
{
    case Factura = 'factura';
    case Remision = 'remision';

    public function nombre(): string
    {
        return $this === self::Factura ? 'Factura' : 'Remisión';
    }

    /** "Factura N.º 123", "Remisión N.º 45" o "Factura sin número". */
    public function descripcion(?string $numero): string
    {
        return $numero !== null && $numero !== ''
            ? "{$this->nombre()} N.º {$numero}"
            : "{$this->nombre()} sin número";
    }
}
