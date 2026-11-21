<?php

use App\Exceptions\ReglaInventarioException;
use App\Services\FacturaQr\FacturaQrService;

/*
 * RF-04 (S-06): lectura del QR de la factura de compra. Formato provisional del anexo técnico
 * DIAN; cuando llegue una factura real se agrega aquí su QR tal cual.
 */

// El CUFE es un SHA-384 en hexadecimal (96 caracteres).
define('CUFE_QR', hash('sha384', 'factura de prueba'));

it('RF04: lee los pares Clave: valor del QR en varias líneas', function () {
    $qr = "NumFac: FE-1234\nFecFac: 2026-11-10\nHorFac: 10:15:00-05:00\nNitFac: 900123456\nDocAdq: 800999888\n"
        ."ValFac: 1000000.00\nValIva: 190000.00\nValOtroIm: 0.00\nValTolFac: 1190000.00\nCUFE: ".CUFE_QR."\n"
        .'QRCode: https://catalogo-vpfe.dian.gov.co/document/searchqr?documentkey='.CUFE_QR;

    $datos = (new FacturaQrService)->interpretar($qr);

    expect($datos->cufe)->toBe(CUFE_QR)
        ->and($datos->numero)->toBe('FE-1234')
        ->and($datos->fecha)->toBe('2026-11-10')
        ->and($datos->nitEmisor)->toBe('900123456')
        ->and($datos->subtotal)->toBe(1000000.0)
        ->and($datos->iva)->toBe(190000.0)
        ->and($datos->total)->toBe(1190000.0);
});

it('RF04: lee el QR aunque el lector lo entregue en una sola línea', function () {
    $qr = 'NumFac: FE-77 FecFac: 2026-11-12 NitFac: 900123456-7 ValTolFac: 59500.00 CUFE: '.strtoupper(CUFE_QR);

    $datos = (new FacturaQrService)->interpretar($qr);

    expect($datos->cufe)->toBe(CUFE_QR)
        ->and($datos->numero)->toBe('FE-77')
        ->and($datos->nitEmisor)->toBe('900123456')
        ->and($datos->total)->toBe(59500.0);
});

it('RF04: lee el QR pegado sin saltos de línea, con las claves unidas al valor anterior', function () {
    $qr = 'NumFac: FE-1234FecFac: 2026-11-10NitFac: 900123456ValFac: 60000.00ValIva: 11400.00ValTolFac: 71400.00CUFE: '.CUFE_QR
        .'QRCode: https://catalogo-vpfe.dian.gov.co/document/searchqr?documentkey='.CUFE_QR;

    $datos = (new FacturaQrService)->interpretar($qr);

    expect($datos->cufe)->toBe(CUFE_QR)
        ->and($datos->numero)->toBe('FE-1234')
        ->and($datos->fecha)->toBe('2026-11-10')
        ->and($datos->nitEmisor)->toBe('900123456')
        ->and($datos->subtotal)->toBe(60000.0)
        ->and($datos->iva)->toBe(11400.0)
        ->and($datos->total)->toBe(71400.0);
});

it('RF04: con solo la URL de la DIAN toma el CUFE y deja el resto vacío', function () {
    $datos = (new FacturaQrService)->interpretar('https://catalogo-vpfe.dian.gov.co/document/searchqr?documentkey='.CUFE_QR);

    expect($datos->cufe)->toBe(CUFE_QR)
        ->and($datos->numero)->toBeNull()
        ->and($datos->nitEmisor)->toBeNull()
        ->and($datos->total)->toBeNull();
});

it('RF04: acepta montos con separador de miles', function () {
    $datos = (new FacturaQrService)->interpretar('ValTolFac: 1.190.000,50 CUFE: '.CUFE_QR);

    expect($datos->total)->toBe(1190000.5);
});

it('RF04: un QR sin CUFE válido se rechaza con un mensaje claro', function () {
    (new FacturaQrService)->interpretar('NumFac: FE-1 CUFE: no-es-un-cufe');
})->throws(ReglaInventarioException::class, 'CUFE');
