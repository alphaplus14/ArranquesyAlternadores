<?php

namespace App\Services\FacturaQr;

/** RF-04: lo que se pudo leer del QR de una factura de compra. Solo el CUFE es obligatorio. */
final readonly class DatosFacturaQr
{
    public function __construct(
        public string $cufe,
        public ?string $numero = null,
        public ?string $fecha = null,      // Y-m-d
        public ?string $nitEmisor = null,  // solo dígitos, sin dígito de verificación
        public ?float $subtotal = null,    // ValFac
        public ?float $iva = null,         // ValIva
        public ?float $total = null,       // ValTolFac
    ) {}
}
