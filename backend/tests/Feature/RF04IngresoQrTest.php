<?php

use App\Enums\Rol;
use App\Exceptions\RegistroInmutableException;
use App\Models\Ingreso;
use App\Models\MovimientoInventario;
use App\Models\Proveedor;
use App\Models\Unidad;

beforeEach(function () {
    sembrarDemo();
    $this->proveedor = Proveedor::where('nombre', 'Repuestos del Norte')->firstOrFail();
    $this->proveedor->update(['nit' => '900123456-7']);
});

function cufe(string $semilla = 'a'): string
{
    return hash('sha384', $semilla);
}

function qrFactura(string $cufe, array $extra = []): string
{
    $campos = [
        'NumFac' => 'FE-1234',
        'FecFac' => '2026-11-10',
        'NitFac' => '900123456',
        'DocAdq' => '800999888',
        'ValFac' => '60000.00',
        'ValIva' => '11400.00',
        'ValTolFac' => '71400.00',
        ...$extra,
        'CUFE' => $cufe,
    ];

    return collect($campos)->map(fn ($v, $k) => "$k: $v")->implode("\n")
        ."\nQRCode: https://catalogo-vpfe.dian.gov.co/document/searchqr?documentkey=$cufe";
}

it('RF04: escanear el QR crea el borrador factura_qr con el encabezado de la factura', function () {
    actuarComo(Rol::Logistica);

    $this->postJson('/api/ingresos/qr', ['contenido' => qrFactura(cufe())])
        ->assertCreated()
        ->assertJsonPath('tipo', 'factura_qr')
        ->assertJsonPath('estado', 'borrador')
        ->assertJsonPath('cufe', cufe())
        ->assertJsonPath('proveedor_id', $this->proveedor->id)
        ->assertJsonPath('numero_documento', 'FE-1234')
        ->assertJsonPath('fecha', '2026-11-10')
        ->assertJsonPath('qr.total', '71400.00')
        ->assertJsonCount(0, 'items');
});

it('RF04: volver a escanear el QR ubica por CUFE el borrador existente', function () {
    actuarComo(Rol::Logistica);
    $id = $this->postJson('/api/ingresos/qr', ['contenido' => qrFactura(cufe())])->json('id');
    $this->postJson("/api/ingresos/{$id}/items", ['referencia_id' => referencia('SEN-TEMP-01')->id, 'cantidad' => 1, 'costo_unitario' => 1000]);

    $this->postJson('/api/ingresos/qr', ['contenido' => qrFactura(cufe())])
        ->assertOk()
        ->assertJsonPath('id', $id)
        ->assertJsonCount(1, 'items');

    expect(Ingreso::where('cufe', cufe())->count())->toBe(1);
});

it('RF04: un CUFE ya confirmado no se ingresa dos veces', function () {
    actuarComo(Rol::Logistica);
    $id = $this->postJson('/api/ingresos/qr', ['contenido' => qrFactura(cufe(), ['ValTolFac' => '1190.00'])])->json('id');
    $this->postJson("/api/ingresos/{$id}/items", ['referencia_id' => referencia('SEN-TEMP-01')->id, 'cantidad' => 1, 'costo_unitario' => 1000]);
    $this->postJson("/api/ingresos/{$id}/confirmar")->assertOk();
    $unidades = Unidad::count();

    $this->postJson('/api/ingresos/qr', ['contenido' => qrFactura(cufe())])
        ->assertStatus(409)
        ->assertJsonFragment(['message' => 'Esta factura (FE-1234) ya se ingresó el '.Ingreso::find($id)->confirmado_at->format('Y-m-d H:i')." (ingreso #{$id}). Un CUFE no se ingresa dos veces."]);

    expect(Ingreso::where('cufe', cufe())->count())->toBe(1)
        ->and(Unidad::count())->toBe($unidades);
});

it('RF04: si el NIT del QR no corresponde a ningún proveedor, no lo crea y pide al admin', function () {
    actuarComo(Rol::Logistica);
    $proveedores = Proveedor::count();

    $this->postJson('/api/ingresos/qr', ['contenido' => qrFactura(cufe(), ['NitFac' => '811000111'])])
        ->assertUnprocessable()
        ->assertJsonFragment(['message' => 'No hay un proveedor con NIT 811000111. Pida al administrador que lo cree (o le agregue ese NIT) y vuelva a escanear.']);

    expect(Proveedor::count())->toBe($proveedores)
        ->and(Ingreso::where('cufe', cufe())->exists())->toBeFalse();
});

it('RF04: con un QR que solo trae la URL de la DIAN se digitan proveedor, número y fecha', function () {
    actuarComo(Rol::Logistica);
    $url = 'https://catalogo-vpfe.dian.gov.co/document/searchqr?documentkey='.cufe();

    $this->postJson('/api/ingresos/qr', ['contenido' => $url])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['proveedor_id', 'numero_documento', 'fecha']);

    $this->postJson('/api/ingresos/qr', [
        'contenido' => $url, 'proveedor_id' => $this->proveedor->id, 'numero_documento' => 'FE-9', 'fecha' => '2026-11-11',
    ])->assertCreated()->assertJsonPath('cufe', cufe())->assertJsonPath('numero_documento', 'FE-9')->assertJsonPath('qr.total', null);
});

it('RF04: si el total digitado no cuadra con el QR, confirmar exige aceptar la diferencia', function () {
    actuarComo(Rol::Logistica);
    // QR: 60000 + 19 % = 71400. Se digita una sola unidad de 50000 → 59500.
    $id = $this->postJson('/api/ingresos/qr', ['contenido' => qrFactura(cufe())])->json('id');
    $this->postJson("/api/ingresos/{$id}/items", ['referencia_id' => referencia('SEN-TEMP-01')->id, 'cantidad' => 1, 'costo_unitario' => 50000])
        ->assertJsonPath('qr.diferencia', -11900);

    $this->postJson("/api/ingresos/{$id}/confirmar")->assertUnprocessable();
    expect(Ingreso::find($id)->esBorrador())->toBeTrue()
        ->and(referencia('SEN-TEMP-01')->existencia)->toBe(0);

    $this->postJson("/api/ingresos/{$id}/confirmar", ['aceptar_diferencia' => true, 'diferencia_motivo' => 'Faltaron 2 sensores'])
        ->assertOk()->assertJsonPath('estado', 'confirmado');
});

it('RF04: confirmar una factura por QR que cuadra crea las unidades y suma existencia por el mismo camino de la remisión', function () {
    $logistica = actuarComo(Rol::Logistica);
    $sensor = referencia('SEN-TEMP-01');
    $antes = $sensor->existencia;

    $id = $this->postJson('/api/ingresos/qr', ['contenido' => qrFactura(cufe())])->json('id');
    // 3 × 20000 = 60000 + IVA 19 % = 71400, igual al QR.
    $this->postJson("/api/ingresos/{$id}/items", ['referencia_id' => $sensor->id, 'cantidad' => 3, 'costo_unitario' => 20000])
        ->assertJsonPath('qr.diferencia', 0);

    $this->postJson("/api/ingresos/{$id}/confirmar")->assertOk()->assertJsonPath('estado', 'confirmado');

    expect(referencia('SEN-TEMP-01')->existencia)->toBe($antes + 3)
        ->and(MovimientoInventario::where('origen_type', 'ingreso')->where('origen_id', $id)->where('usuario_id', $logistica->id)->count())->toBe(3);
    $this->getJson("/api/ingresos/{$id}/unidades")->assertOk()->assertJsonCount(3);
});

it('RF04: el admin también ingresa por QR y mostrador no puede', function () {
    actuarComo(Rol::Mostrador);
    $this->postJson('/api/ingresos/qr', ['contenido' => qrFactura(cufe())])->assertForbidden();

    actuarComo(Rol::Admin);
    $this->postJson('/api/ingresos/qr', ['contenido' => qrFactura(cufe())])->assertCreated();
});

/** Borrador factura_qr (QR = 71400) con una sola unidad de 50000 digitada → 59500, diferencia −11900. */
function ingresoQrConFaltante(): int
{
    $id = test()->postJson('/api/ingresos/qr', ['contenido' => qrFactura(cufe())])->json('id');
    test()->postJson("/api/ingresos/{$id}/items", ['referencia_id' => referencia('SEN-TEMP-01')->id, 'cantidad' => 1, 'costo_unitario' => 50000])
        ->assertJsonPath('qr.diferencia', -11900);

    return $id;
}

it('RF04: aceptar la diferencia sin motivo no confirma ni suma existencia', function () {
    actuarComo(Rol::Logistica);
    $id = ingresoQrConFaltante();
    $unidades = Unidad::count();

    foreach ([[], ['diferencia_motivo' => null], ['diferencia_motivo' => '   ']] as $sinMotivo) {
        $this->postJson("/api/ingresos/{$id}/confirmar", ['aceptar_diferencia' => true, ...$sinMotivo])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['diferencia_motivo']);
    }

    expect(Ingreso::find($id)->esBorrador())->toBeTrue()
        ->and(referencia('SEN-TEMP-01')->existencia)->toBe(0)
        ->and(Unidad::count())->toBe($unidades);
});

it('RF04: al aceptar la diferencia queda registrada con cuánto, por qué y quién la aceptó', function () {
    $creador = actuarComo(Rol::Logistica);
    $id = ingresoQrConFaltante();

    // Confirma otra persona de logística: ella es quien acepta la diferencia.
    $confirma = actuarComo(Rol::Logistica);
    $this->postJson("/api/ingresos/{$id}/confirmar", ['aceptar_diferencia' => true, 'diferencia_motivo' => '  Faltaron 2 sensores  '])
        ->assertOk()
        ->assertJsonPath('estado', 'confirmado')
        ->assertJsonPath('qr.diferencia', -11900)
        ->assertJsonPath('qr.diferencia_aceptada', true)
        ->assertJsonPath('qr.diferencia_motivo', 'Faltaron 2 sensores')
        ->assertJsonPath('usuario.id', $creador->id)
        ->assertJsonPath('confirmado_por.id', $confirma->id)
        ->assertJsonPath('confirmado_por.name', $confirma->name);

    $ingreso = Ingreso::find($id);
    expect((float) $ingreso->diferencia_qr)->toBe(-11900.0)
        ->and($ingreso->diferencia_aceptada)->toBeTrue()
        ->and($ingreso->diferencia_motivo)->toBe('Faltaron 2 sensores')
        ->and($ingreso->confirmado_por)->toBe($confirma->id)
        ->and(referencia('SEN-TEMP-01')->existencia)->toBe(1);

    // Lo registrado se sigue leyendo igual después.
    $this->getJson("/api/ingresos/{$id}")->assertOk()
        ->assertJsonPath('qr.diferencia', -11900)
        ->assertJsonPath('qr.diferencia_motivo', 'Faltaron 2 sensores');
});

it('RF04: una diferencia dentro de la tolerancia se registra sin exigir aceptación ni guardar motivo', function () {
    $logistica = actuarComo(Rol::Logistica);
    // QR 71400; 3 × 19999.72 = 59999.16 + IVA 11399.84 = 71399.00 → diferencia −1 (tolerancia $1).
    $id = $this->postJson('/api/ingresos/qr', ['contenido' => qrFactura(cufe())])->json('id');
    $this->postJson("/api/ingresos/{$id}/items", ['referencia_id' => referencia('SEN-TEMP-01')->id, 'cantidad' => 3, 'costo_unitario' => 19999.72])
        ->assertJsonPath('qr.diferencia', -1);

    $this->postJson("/api/ingresos/{$id}/confirmar", ['aceptar_diferencia' => true, 'diferencia_motivo' => 'no aplica'])
        ->assertOk()
        ->assertJsonPath('qr.diferencia', -1)
        ->assertJsonPath('qr.diferencia_aceptada', false)
        ->assertJsonPath('qr.diferencia_motivo', null)
        ->assertJsonPath('confirmado_por.id', $logistica->id);
});

it('RF04: si el QR no trajo total no hay diferencia que registrar', function () {
    actuarComo(Rol::Logistica);
    $id = $this->postJson('/api/ingresos/qr', [
        'contenido' => 'https://catalogo-vpfe.dian.gov.co/document/searchqr?documentkey='.cufe(),
        'proveedor_id' => $this->proveedor->id, 'numero_documento' => 'FE-9', 'fecha' => '2026-11-11',
    ])->json('id');
    $this->postJson("/api/ingresos/{$id}/items", ['referencia_id' => referencia('SEN-TEMP-01')->id, 'cantidad' => 1, 'costo_unitario' => 1000]);

    $this->postJson("/api/ingresos/{$id}/confirmar")->assertOk()
        ->assertJsonPath('qr.diferencia', null)
        ->assertJsonPath('qr.diferencia_aceptada', false);
});

it('RF05: la remisión registra quién confirmó y no lleva diferencia de QR', function () {
    $logistica = actuarComo(Rol::Logistica);
    $id = $this->postJson('/api/ingresos', ['proveedor_id' => $this->proveedor->id, 'numero_documento' => 'REM-1', 'fecha' => '2026-11-20'])->json('id');
    $this->postJson("/api/ingresos/{$id}/items", ['referencia_id' => referencia('SEN-TEMP-01')->id, 'cantidad' => 1, 'costo_unitario' => 1000]);

    $this->postJson("/api/ingresos/{$id}/confirmar", ['aceptar_diferencia' => true, 'diferencia_motivo' => 'x'])->assertOk()
        ->assertJsonPath('qr', null)
        ->assertJsonPath('confirmado_por.id', $logistica->id);

    $ingreso = Ingreso::find($id);
    expect($ingreso->diferencia_qr)->toBeNull()
        ->and($ingreso->diferencia_aceptada)->toBeFalse()
        ->and($ingreso->diferencia_motivo)->toBeNull();
});

it('RF04: el listado de ingresos filtra los confirmados con diferencia aceptada', function () {
    actuarComo(Rol::Logistica);
    $conDiferencia = ingresoQrConFaltante();
    $this->postJson("/api/ingresos/{$conDiferencia}/confirmar", ['aceptar_diferencia' => true, 'diferencia_motivo' => 'Faltó mercancía'])->assertOk();

    $cuadra = $this->postJson('/api/ingresos/qr', ['contenido' => qrFactura(cufe('b'))])->json('id');
    $this->postJson("/api/ingresos/{$cuadra}/items", ['referencia_id' => referencia('SEN-TEMP-01')->id, 'cantidad' => 3, 'costo_unitario' => 20000]);
    $this->postJson("/api/ingresos/{$cuadra}/confirmar")->assertOk();

    $ids = collect($this->getJson('/api/ingresos?con_diferencia=1')->assertOk()->json('data'))->pluck('id');
    expect($ids->all())->toBe([$conDiferencia]);

    $todos = collect($this->getJson('/api/ingresos')->assertOk()->json('data'))->pluck('id');
    expect($todos)->toContain($conDiferencia)->toContain($cuadra);
});

it('RF04: un ingreso confirmado no se edita: ni por la API ni directo en el modelo', function () {
    actuarComo(Rol::Logistica);
    $id = ingresoQrConFaltante();
    $this->postJson("/api/ingresos/{$id}/confirmar", ['aceptar_diferencia' => true, 'diferencia_motivo' => 'Faltó mercancía'])->assertOk();
    $item = Ingreso::find($id)->items()->first();

    $this->patchJson("/api/ingresos/{$id}", ['numero_documento' => 'OTRO'])->assertStatus(409);
    $this->postJson("/api/ingresos/{$id}/items", ['referencia_id' => referencia('SEN-TEMP-01')->id, 'cantidad' => 5, 'costo_unitario' => 1])->assertStatus(409);
    $this->patchJson("/api/ingresos/{$id}/items/{$item->id}", ['cantidad' => 9, 'referencia_id' => $item->referencia_id, 'costo_unitario' => 1])->assertStatus(409);
    $this->deleteJson("/api/ingresos/{$id}/items/{$item->id}")->assertStatus(409);
    $this->postJson("/api/ingresos/{$id}/confirmar", ['aceptar_diferencia' => true, 'diferencia_motivo' => 'otra vez'])->assertStatus(409);

    expect(fn () => Ingreso::find($id)->update(['numero_documento' => 'cambiado']))->toThrow(RegistroInmutableException::class)
        ->and(fn () => Ingreso::find($id)->delete())->toThrow(RegistroInmutableException::class);

    $ingreso = Ingreso::find($id);
    expect($ingreso->numero_documento)->toBe('FE-1234')
        ->and($ingreso->diferencia_motivo)->toBe('Faltó mercancía')
        ->and($ingreso->items()->count())->toBe(1)
        ->and(referencia('SEN-TEMP-01')->existencia)->toBe(1);
});
