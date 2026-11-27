<?php

use App\Enums\Rol;
use App\Models\Auditoria;
use App\Models\Categoria;
use App\Models\Proveedor;
use App\Models\Referencia;

/*
 * S-25 (RF-02, RF-20): una referencia tiene su codigo_referencia (el del proveedor) y, opcional,
 * su codigo_fabricante (código de barras o QR del producto). Ambos son únicos POR PROVEEDOR; el
 * mismo código en otro proveedor es válido (solo se avisa).
 */

beforeEach(fn () => sembrarDemo());

function proveedorDe(string $nombre): Proveedor
{
    return Proveedor::where('nombre', $nombre)->firstOrFail();
}

function nuevaReferencia(array $datos = []): array
{
    return [
        'proveedor_id' => proveedorDe('Repuestos del Norte')->id,
        'categoria_id' => Categoria::where('admite_codigo_universal', false)->value('id'),
        'descripcion' => 'Automático Peugeot Xsara',
        'precio_venta' => 50000,
        ...$datos,
    ];
}

it('S-25 (a): crear con código de barras y referencia guarda los dos', function () {
    actuarComo(Rol::Logistica);

    $this->postJson('/api/referencias', nuevaReferencia(['codigo_referencia' => 'AUT-XS-01', 'codigo_fabricante' => ' 822077515955 ']))
        ->assertCreated()
        ->assertJsonPath('codigo_referencia', 'AUT-XS-01')
        ->assertJsonPath('codigo_fabricante', '822077515955');
});

it('S-25 (b): con solo el código de barras, la referencia queda igual al código de barras', function () {
    actuarComo(Rol::Logistica);

    $this->postJson('/api/referencias', nuevaReferencia(['codigo_referencia' => '', 'codigo_fabricante' => '822077515955']))
        ->assertCreated()
        ->assertJsonPath('codigo_referencia', '822077515955')
        ->assertJsonPath('codigo_fabricante', '822077515955');
});

it('S-25 (c): sin referencia ni código de barras responde 422', function () {
    actuarComo(Rol::Logistica);

    $this->postJson('/api/referencias', nuevaReferencia(['codigo_referencia' => ' ', 'codigo_fabricante' => '']))
        ->assertUnprocessable()
        ->assertJsonPath('errors.codigo_referencia.0', 'Escriba la referencia del proveedor o el código de barras del producto.');
});

it('S-25 (d): el mismo proveedor no puede repetir referencia ni código de barras', function () {
    actuarComo(Rol::Logistica);
    $this->postJson('/api/referencias', nuevaReferencia(['codigo_referencia' => 'AUT-XS-01', 'codigo_fabricante' => '822077515955']))->assertCreated();

    $this->postJson('/api/referencias', nuevaReferencia(['codigo_referencia' => 'AUT-XS-01']))
        ->assertUnprocessable()->assertJsonPath('errors.codigo_referencia.0', 'Este proveedor ya tiene una referencia con ese código.');
    $this->postJson('/api/referencias', nuevaReferencia(['codigo_referencia' => 'AUT-XS-02', 'codigo_fabricante' => '822077515955']))
        ->assertUnprocessable()->assertJsonPath('errors.codigo_fabricante.0', 'Este proveedor ya tiene otra referencia con ese código de barras.');
});

it('S-25 (e): otro proveedor sí puede repetir el mismo código', function () {
    actuarComo(Rol::Logistica);
    $this->postJson('/api/referencias', nuevaReferencia(['codigo_referencia' => 'AUT-XS-01', 'codigo_fabricante' => '822077515955']))->assertCreated();

    $this->postJson('/api/referencias', nuevaReferencia([
        'proveedor_id' => proveedorDe('Autopartes Central')->id,
        'codigo_referencia' => 'AUT-XS-01',
        'codigo_fabricante' => '822077515955',
    ]))->assertCreated();

    expect(Referencia::where('codigo_fabricante', '822077515955')->count())->toBe(2);
});

it('S-25 (f): escanear en un ingreso por código de barras o por referencia encuentra la misma; un código ambiguo da 422', function () {
    actuarComo(Rol::Logistica);
    $norte = proveedorDe('Repuestos del Norte');
    $id = $this->postJson('/api/referencias', nuevaReferencia(['codigo_referencia' => 'AUT-XS-01', 'codigo_fabricante' => '822077515955']))->json('id');
    $ingreso = $this->postJson('/api/ingresos', ['tipo' => 'remision', 'proveedor_id' => $norte->id, 'numero_documento' => 'S25-1', 'fecha' => now()->toDateString()])->json('id');

    $porBarras = $this->postJson("/api/ingresos/{$ingreso}/escanear", ['codigo' => '822077515955'])->assertCreated();
    expect(lineaEscaneada($porBarras)['referencia_id'])->toBe($id);
    $porReferencia = $this->postJson("/api/ingresos/{$ingreso}/escanear", ['codigo' => 'AUT-XS-01'])->assertOk();
    expect(lineaEscaneada($porReferencia)['referencia_id'])->toBe($id)
        ->and(lineaEscaneada($porReferencia)['cantidad'])->toBe(2);

    // Otra referencia del mismo proveedor cuya referencia es el código de barras de la primera.
    $otra = $this->postJson('/api/referencias', nuevaReferencia(['codigo_referencia' => '822077515955', 'descripcion' => 'Otra']))->assertCreated()->json('id');

    $this->postJson("/api/ingresos/{$ingreso}/escanear", ['codigo' => '822077515955'])
        ->assertUnprocessable()
        ->assertJsonPath('motivo', 'codigo_ambiguo')
        ->assertJsonCount(2, 'opciones');

    // Elegir una de las opciones resuelve el escaneo.
    $elegida = $this->postJson("/api/ingresos/{$ingreso}/escanear", ['codigo' => '822077515955', 'referencia_id' => $otra])->assertCreated();
    expect(lineaEscaneada($elegida)['referencia_id'])->toBe($otra);
});

it('S-25 (g): GET /buscar devuelve las referencias de ambos proveedores para un código compartido', function () {
    actuarComo(Rol::Logistica);
    $this->postJson('/api/referencias', nuevaReferencia(['codigo_referencia' => 'AUT-XS-01', 'codigo_fabricante' => '822077515955']))->assertCreated();
    $this->postJson('/api/referencias', nuevaReferencia(['proveedor_id' => proveedorDe('Autopartes Central')->id, 'codigo_referencia' => 'AC-9', 'codigo_fabricante' => '822077515955']))->assertCreated();

    $proveedores = collect($this->getJson('/api/buscar?q=822077515955')->assertOk()->json('referencias'))->pluck('proveedor.nombre')->sort()->values()->all();
    expect($proveedores)->toBe(['Autopartes Central', 'Repuestos del Norte']);

    // El filtro q del listado también encuentra por código de barras.
    $this->getJson('/api/referencias?q=822077515955')->assertOk()->assertJsonPath('meta.total', 2);
});

it('S-25 (h): coincidencias devuelve las de otros proveedores y no la propia', function () {
    actuarComo(Rol::Logistica);
    $norte = proveedorDe('Repuestos del Norte');
    $this->postJson('/api/referencias', nuevaReferencia(['codigo_referencia' => 'AUT-XS-01', 'codigo_fabricante' => '822077515955']))->assertCreated();
    $this->postJson('/api/referencias', nuevaReferencia(['proveedor_id' => proveedorDe('Autopartes Central')->id, 'codigo_referencia' => '822077515955']))->assertCreated();

    $respuesta = $this->getJson("/api/referencias/coincidencias?codigo=822077515955&proveedor_id={$norte->id}")->assertOk();

    expect($respuesta->json())->toHaveCount(1)
        ->and($respuesta->json('0.proveedor.nombre'))->toBe('Autopartes Central')
        ->and($respuesta->json('0'))->toHaveKeys(['id', 'codigo_referencia', 'descripcion', 'proveedor', 'calidad', 'existencia']);

    actuarComo(Rol::Mostrador);
    $this->getJson('/api/referencias/coincidencias?codigo=822077515955')->assertForbidden();
});

it('S-25 (i): cambiar el código de barras queda en la auditoría', function () {
    $admin = actuarComo(Rol::Admin);
    $referencia = referencia('RN-4410');

    $this->patchJson("/api/referencias/{$referencia->id}", ['codigo_fabricante' => '7701234567890'])->assertOk()->assertJsonPath('codigo_fabricante', '7701234567890');

    expect(Auditoria::where(['tabla' => 'referencias', 'registro_id' => $referencia->id, 'campo' => 'codigo_fabricante'])->first())
        ->valor_anterior->toBeNull()
        ->valor_nuevo->toBe('7701234567890')
        ->usuario_id->toBe($admin->id)
        ->and($referencia->fresh()->codigo_referencia)->toBe('RN-4410');
});
