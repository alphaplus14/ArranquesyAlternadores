<?php

use App\Enums\Rol;
use App\Models\Ingreso;
use App\Models\Proveedor;

beforeEach(fn () => sembrarDemo());

function encabezado(array $cambios = []): array
{
    return [
        'tipo' => 'factura',
        'proveedor_id' => Proveedor::orderBy('id')->value('id'),
        'numero_documento' => 'FEV 1545',
        'fecha' => '2026-11-21',
        ...$cambios,
    ];
}

it('RF-04: una factura de compra se crea a mano, sin QR, con proveedor, número y fecha', function () {
    actuarComo(Rol::Logistica);

    $this->postJson('/api/ingresos', encabezado())
        ->assertCreated()
        ->assertJsonPath('tipo', 'factura')
        ->assertJsonPath('estado', 'borrador')
        ->assertJsonPath('numero_documento', 'FEV 1545')
        ->assertJsonPath('fecha', '2026-11-21')
        ->assertJsonMissingPath('qr')
        ->assertJsonMissingPath('cufe');
});

it('RF-04: el tipo es obligatorio y solo admite remisión o factura', function () {
    actuarComo(Rol::Logistica);

    $this->postJson('/api/ingresos', encabezado(['tipo' => null]))->assertJsonValidationErrors('tipo');
    $this->postJson('/api/ingresos', encabezado(['tipo' => 'factura_qr']))->assertJsonValidationErrors('tipo');
});

it('RF-04: el número se guarda recortado y compactado', function () {
    actuarComo(Rol::Logistica);

    $this->postJson('/api/ingresos', encabezado(['numero_documento' => "  FEV \t  1545  "]))
        ->assertCreated()
        ->assertJsonPath('numero_documento', 'FEV 1545');
});

it('RF-04: repetir proveedor, tipo y número de un borrador lo reabre sin crear otro ingreso', function () {
    actuarComo(Rol::Logistica);
    $id = $this->postJson('/api/ingresos', encabezado())->assertCreated()->json('id');
    $antes = Ingreso::count();

    $this->postJson('/api/ingresos', encabezado(['numero_documento' => ' FEV  1545 ']))
        ->assertOk()
        ->assertJsonPath('id', $id);

    expect(Ingreso::count())->toBe($antes);
});

it('RF-04: el mismo número con otro tipo u otro proveedor es otro documento', function () {
    actuarComo(Rol::Logistica);
    $antes = Ingreso::count();
    $this->postJson('/api/ingresos', encabezado())->assertCreated();

    $this->postJson('/api/ingresos', encabezado(['tipo' => 'remision']))->assertCreated();
    $this->postJson('/api/ingresos', encabezado(['proveedor_id' => Proveedor::orderByDesc('id')->value('id')]))->assertCreated();

    expect(Ingreso::count())->toBe($antes + 3);
});

it('RF-04: un documento ya confirmado se rechaza indicando el ingreso existente', function () {
    $logistica = actuarComo(Rol::Logistica);
    $referencia = referencia('RN-4410');
    $ingreso = ingresar($referencia, 1, $logistica);
    $antes = Ingreso::count();

    $this->postJson('/api/ingresos', [
        'tipo' => 'remision',
        'proveedor_id' => $ingreso->proveedor_id,
        'numero_documento' => $ingreso->numero_documento,
        'fecha' => '2026-11-21',
    ])
        ->assertStatus(422)
        ->assertJsonPath('motivo', 'documento_confirmado')
        ->assertJsonPath('ingreso_id', $ingreso->id)
        ->assertJsonPath('message', fn (string $m) => str_contains($m, "ingreso #{$ingreso->id}"));

    expect(Ingreso::count())->toBe($antes);
});

it('RF-04: cambiar el número de un borrador a uno que ya existe responde 422', function () {
    actuarComo(Rol::Logistica);
    $this->postJson('/api/ingresos', encabezado())->assertCreated();
    $otro = $this->postJson('/api/ingresos', encabezado(['numero_documento' => 'FEV 1546']))->json('id');

    $this->patchJson("/api/ingresos/{$otro}", ['numero_documento' => 'FEV  1545'])->assertStatus(422);
    expect(Ingreso::find($otro)->numero_documento)->toBe('FEV 1546');
});
