<?php

use App\Enums\EstadoIngreso;
use App\Enums\Rol;
use App\Models\Categoria;
use App\Models\IngresoItem;
use App\Models\Referencia;

beforeEach(fn () => sembrarDemo());

/** Borrador de factura del proveedor de la referencia dada; devuelve su id. */
function facturaBorrador(Referencia $referencia, string $numero = 'FEV 9001'): int
{
    return test()->postJson('/api/ingresos', [
        'tipo' => 'factura',
        'proveedor_id' => $referencia->proveedor_id,
        'numero_documento' => $numero,
        'fecha' => '2026-11-21',
    ])->assertCreated()->json('id');
}

function escanearIngreso(int $ingresoId, string $codigo)
{
    return test()->postJson("/api/ingresos/{$ingresoId}/escanear", ['codigo' => $codigo]);
}

/** Datos de una referencia nueva del proveedor dado. */
function referenciaDelProveedor(int $proveedorId, string $codigo, array $cambios = []): array
{
    return [
        'codigo_referencia' => $codigo,
        'proveedor_id' => $proveedorId,
        'categoria_id' => Categoria::where('admite_codigo_universal', false)->value('id') ?? Categoria::value('id'),
        'descripcion' => 'Pieza recibida',
        'precio_venta' => 50000,
        ...$cambios,
    ];
}

it('RF-20: escanear un código existente agrega la línea con cantidad 1 y el último costo confirmado', function () {
    actuarComo(Rol::Logistica);
    $referencia = referencia('RN-4410');
    $ultimoCosto = IngresoItem::query()
        ->whereHas('ingreso', fn ($q) => $q->where('estado', EstadoIngreso::Confirmado))
        ->where('referencia_id', $referencia->id)->latest('id')->value('costo_unitario');
    $id = facturaBorrador($referencia);

    $respuesta = escanearIngreso($id, '  RN-4410 ')->assertCreated();
    $linea = lineaEscaneada($respuesta);

    expect($linea['referencia_id'])->toBe($referencia->id)
        ->and($linea['referencia']['codigo_referencia'])->toBe('RN-4410')
        ->and($linea['cantidad'])->toBe(1)
        ->and($linea['costo_unitario'])->toEqual($ultimoCosto)
        ->and($linea['iva_porcentaje'])->toBe('19.00') // S-22: el de la referencia
        ->and($linea['descuento_porcentaje'])->toBe('0.00')
        ->and($respuesta->json('id'))->toBe($id)
        ->and($respuesta->json('totales.total'))->not->toBeNull()
        ->and($ultimoCosto)->not->toBeNull();
});

it('RF-20: escanear el mismo código otra vez suma 1 a la misma línea', function () {
    actuarComo(Rol::Logistica);
    $id = facturaBorrador(referencia('RN-4410'));

    $linea = escanearIngreso($id, 'RN-4410')->assertCreated()->json('item_escaneado_id');
    $segunda = escanearIngreso($id, 'RN-4410')->assertOk();
    expect($segunda->json('item_escaneado_id'))->toBe($linea)
        ->and(lineaEscaneada($segunda)['cantidad'])->toBe(2);
    expect(lineaEscaneada(escanearIngreso($id, 'RN-4410')->assertOk())['cantidad'])->toBe(3);

    expect(IngresoItem::where('ingreso_id', $id)->count())->toBe(1);
});

it('RF-20: un código inexistente responde 404 con un cuerpo estable y no crea líneas', function () {
    actuarComo(Rol::Logistica);
    $id = facturaBorrador(referencia('RN-4410'));

    escanearIngreso($id, ' NO-EXISTE-77 ')
        ->assertNotFound()
        ->assertJsonPath('motivo', 'referencia_inexistente')
        ->assertJsonPath('codigo', 'NO-EXISTE-77')
        ->assertJsonStructure(['message']);

    expect(IngresoItem::where('ingreso_id', $id)->count())->toBe(0);
});

it('RF-20: la búsqueda es exacta, no por prefijo', function () {
    actuarComo(Rol::Logistica);
    $id = facturaBorrador(referencia('RN-4410'));

    escanearIngreso($id, 'RN-44')->assertNotFound()->assertJsonPath('motivo', 'referencia_inexistente');
});

it('RF-20: solo busca entre las referencias del proveedor del ingreso', function () {
    actuarComo(Rol::Logistica);
    $propia = referencia('RN-4410');
    $ajena = Referencia::where('proveedor_id', '!=', $propia->proveedor_id)->firstOrFail();
    $id = facturaBorrador($propia);

    escanearIngreso($id, $ajena->codigo_referencia)->assertNotFound()->assertJsonPath('motivo', 'referencia_inexistente');
});

it('RF-20: solo funciona en borrador', function () {
    $logistica = actuarComo(Rol::Logistica);
    $confirmado = ingresar(referencia('RN-4410'), 1, $logistica);

    escanearIngreso($confirmado->id, 'RN-4410')->assertStatus(409);
    expect((int) IngresoItem::where('ingreso_id', $confirmado->id)->sum('cantidad'))->toBe(1);
});

it('RF-20: mostrador no escanea ingresos (403)', function () {
    actuarComo(Rol::Logistica);
    $id = facturaBorrador(referencia('RN-4410'));

    actuarComo(Rol::Mostrador);
    escanearIngreso($id, 'RN-4410')->assertForbidden();
});

it('RF-20: logística crea la referencia que falta y el escaneo siguiente agrega la línea con costo 0', function () {
    actuarComo(Rol::Logistica);
    $referencia = referencia('RN-4410');
    $id = facturaBorrador($referencia);
    escanearIngreso($id, 'NUEVA-001')->assertNotFound();

    $this->postJson('/api/referencias', referenciaDelProveedor($referencia->proveedor_id, 'NUEVA-001'))->assertCreated();

    $creada = lineaEscaneada(escanearIngreso($id, 'NUEVA-001')->assertCreated());
    expect($creada['cantidad'])->toBe(1)->and($creada['costo_unitario'])->toBe('0.00');
});

it('RF-20 / S-26: logística crea y edita una referencia, pero nadie la borra', function () {
    actuarComo(Rol::Logistica);
    $proveedor = referencia('RN-4410')->proveedor_id;

    $nueva = $this->postJson('/api/referencias', referenciaDelProveedor($proveedor, 'NUEVA-002'))->assertCreated()->json('id');

    $this->patchJson("/api/referencias/{$nueva}", ['descripcion' => 'Otra'])->assertOk()->assertJsonPath('descripcion', 'OTRA'); // S-85
    $this->deleteJson("/api/referencias/{$nueva}")->assertMethodNotAllowed();
});

it('RF-20: crear una referencia con un código que ya tiene ese proveedor responde 422', function () {
    actuarComo(Rol::Logistica);
    $existente = referencia('RN-4410');

    $this->postJson('/api/referencias', referenciaDelProveedor($existente->proveedor_id, 'RN-4410'))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('codigo_referencia');
});

it('RF-20: la referencia que crea logística también exige precio de venta mayor que 0', function () {
    actuarComo(Rol::Logistica);

    $this->postJson('/api/referencias', referenciaDelProveedor(referencia('RN-4410')->proveedor_id, 'NUEVA-003', ['precio_venta' => 0]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('precio_venta');
});

it('RF-20: confirmar un ingreso con una línea sin costo responde 422 y no crea unidades', function () {
    actuarComo(Rol::Logistica);
    $referencia = referencia('RN-4410');
    $id = facturaBorrador($referencia);
    $linea = escanearIngreso($id, 'RN-4410')->json('item_escaneado_id');
    $this->patchJson("/api/ingresos/{$id}/items/{$linea}", ['costo_unitario' => 0])->assertOk();
    $existencia = $referencia->fresh()->existencia;

    $this->postJson("/api/ingresos/{$id}/confirmar")
        ->assertStatus(422)
        ->assertJsonPath('message', fn (string $m) => str_contains($m, 'RN-4410'));

    expect($referencia->fresh()->existencia)->toBe($existencia)
        ->and($this->getJson("/api/ingresos/{$id}")->json('estado'))->toBe('borrador');
});

it('RF-20: con costo, el ingreso escaneado se confirma y crea las unidades', function () {
    actuarComo(Rol::Logistica);
    $referencia = referencia('RN-4410');
    $id = facturaBorrador($referencia);
    escanearIngreso($id, 'RN-4410');
    $linea = escanearIngreso($id, 'RN-4410')->json('item_escaneado_id');
    $this->patchJson("/api/ingresos/{$id}/items/{$linea}", ['costo_unitario' => 41000])->assertOk();
    $existencia = $referencia->fresh()->existencia;

    $this->postJson("/api/ingresos/{$id}/confirmar")->assertOk()->assertJsonPath('estado', 'confirmado');
    expect($referencia->fresh()->existencia)->toBe($existencia + 2);
    $this->getJson("/api/ingresos/{$id}/unidades")->assertOk()->assertJsonCount(2);
});
