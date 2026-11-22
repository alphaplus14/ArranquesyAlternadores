<?php

use App\Enums\Rol;
use App\Models\Categoria;
use App\Models\CodigoUniversal;
use App\Models\Proveedor;
use Illuminate\Support\Facades\Schema;

/*
 * S-23 (RF-01 / RF-02): la empresa no maneja marcas. Las referencias de un mismo repuesto se
 * distinguen por código, proveedor y calidad; el universal suma sus referencias y el desglose
 * es por referencia.
 */

beforeEach(fn () => sembrarDemo());

function datosReferencia(array $extra = []): array
{
    return [
        'codigo_referencia' => 'S23-'.uniqid(),
        'proveedor_id' => Proveedor::value('id'),
        'categoria_id' => Categoria::where('admite_codigo_universal', false)->value('id'),
        'descripcion' => 'Pieza de prueba',
        'precio_venta' => 1000,
        ...$extra,
    ];
}

it('S-23: crear una referencia ya no pide marca_id y rechaza que se envíe', function () {
    actuarComo(Rol::Admin);

    $this->postJson('/api/referencias', datosReferencia())->assertCreated()->assertJsonMissingPath('marca_id');
    $this->postJson('/api/referencias', datosReferencia(['marca_id' => 1]))
        ->assertUnprocessable()->assertJsonValidationErrors('marca_id');

    expect(Schema::hasColumn('referencias', 'marca_id'))->toBeFalse()
        ->and(Schema::hasTable('marcas'))->toBeFalse();
});

it('S-23: editar una referencia rechaza marca_id', function () {
    actuarComo(Rol::Admin);

    $this->patchJson('/api/referencias/'.referencia('RN-4410')->id, ['marca_id' => 1])
        ->assertUnprocessable()->assertJsonValidationErrors('marca_id');
});

it('S-23: IM204 con tres referencias de 2 suma 6 y el desglose lista código, proveedor y existencia', function () {
    actuarComo(Rol::Admin);
    $im204 = CodigoUniversal::where('codigo', 'IM204')->first();

    $respuesta = $this->getJson("/api/codigos-universales/{$im204->id}")->assertOk()->assertJsonMissingPath('por_marca');

    expect($respuesta->json('existencia_total'))->toBe(6)
        ->and(collect($respuesta->json('referencias'))->map(fn ($r) => [$r['codigo_referencia'], $r['proveedor']['nombre'], $r['existencia']])->all())
        ->toBe([
            ['AC-77812', 'Autopartes Central', 2],
            ['DE-IM204-A', 'Distribuidora Eléctrica', 2],
            ['RN-4410', 'Repuestos del Norte', 2],
        ]);
});

it('S-23: ZM860 fino, ZZM860 genérico (mismo proveedor) e IML860 (otro) conviven en el universal y suman bien', function () {
    actuarComo(Rol::Admin);
    $reguladores = Categoria::where('nombre', 'Reguladores')->first();
    $im860 = CodigoUniversal::create(['codigo' => 'IM860', 'categoria_id' => $reguladores->id]);
    [$norte, $central] = [Proveedor::where('nombre', 'Repuestos del Norte')->first(), Proveedor::where('nombre', 'Autopartes Central')->first()];

    $crear = fn (string $codigo, Proveedor $proveedor, ?string $calidad) => $this->postJson('/api/referencias', datosReferencia([
        'codigo_referencia' => $codigo,
        'proveedor_id' => $proveedor->id,
        'categoria_id' => $reguladores->id,
        'codigo_universal_id' => $im860->id,
        'calidad' => $calidad,
    ]))->assertCreated();

    $crear('ZM860', $norte, 'fino');
    $crear('ZZM860', $norte, 'generico');
    $crear('IML860', $central, null);

    ingresar(referencia('ZM860'), 3);
    ingresar(referencia('ZZM860'), 1);
    ingresar(referencia('IML860'), 2);

    $respuesta = $this->getJson("/api/codigos-universales/{$im860->id}")->assertOk();

    expect($respuesta->json('existencia_total'))->toBe(6)
        ->and((int) CodigoUniversal::conExistencia()->find($im860->id)->existencia)->toBe(6)
        ->and(collect($respuesta->json('referencias'))->map(fn ($r) => [$r['codigo_referencia'], $r['proveedor']['nombre'], $r['calidad'], $r['existencia']])->all())
        ->toBe([
            ['IML860', 'Autopartes Central', null, 2],
            ['ZM860', 'Repuestos del Norte', 'fino', 3],
            ['ZZM860', 'Repuestos del Norte', 'generico', 1],
        ]);
});

it('S-23: escaneo, venta, reverso e ingreso siguen funcionando sin marca', function () {
    // Ingreso por escaneo (RF-20) y confirmación (RF-05).
    actuarComo(Rol::Logistica);
    $referencia = referencia('F00M144136');
    $ingresoId = $this->postJson('/api/ingresos', [
        'tipo' => 'remision', 'proveedor_id' => $referencia->proveedor_id, 'numero_documento' => 'S23-1', 'fecha' => now()->toDateString(),
    ])->assertCreated()->json('id');
    $this->postJson("/api/ingresos/{$ingresoId}/escanear", ['codigo' => 'F00M144136'])->assertSuccessful();
    $itemId = $this->getJson("/api/ingresos/{$ingresoId}")->json('items.0.id');
    $this->patchJson("/api/ingresos/{$ingresoId}/items/{$itemId}", ['costo_unitario' => 50000])->assertOk();
    $this->postJson("/api/ingresos/{$ingresoId}/confirmar")->assertOk();
    $unidades = $this->getJson("/api/ingresos/{$ingresoId}/unidades")->assertOk()
        ->assertJsonPath('0.referencia.proveedor.nombre', 'Repuestos del Norte')
        ->assertJsonMissingPath('0.referencia.marca');
    expect(referencia('F00M144136')->existencia)->toBe(2);

    // Venta por escaneo (RF-06): la respuesta trae proveedor y calidad en lugar de la marca.
    actuarComo(Rol::Mostrador);
    $ventaId = $this->postJson('/api/ventas')->assertCreated()->json('id');
    $this->postJson("/api/ventas/{$ventaId}/escanear", ['codigo_barras' => $unidades->json('0.codigo_barras'), 'metodo_captura' => 'escaneado'])
        ->assertCreated()
        ->assertJsonPath('referencia.proveedor', 'Repuestos del Norte')
        ->assertJsonPath('referencia.calidad', 'fino')
        ->assertJsonPath('referencia.existencia', 1)
        ->assertJsonMissingPath('referencia.marca');
    $this->getJson("/api/ventas/{$ventaId}")->assertOk()
        ->assertJsonPath('items.0.unidad.referencia.proveedor.nombre', 'Repuestos del Norte');
    $this->postJson("/api/ventas/{$ventaId}/cerrar")->assertOk();

    // Reverso (RF-07): la unidad vuelve al inventario.
    $this->postJson("/api/ventas/{$ventaId}/reversos", ['tipo' => 'devolucion', 'motivo' => 'Prueba S-23'])->assertOk();
    expect(referencia('F00M144136')->existencia)->toBe(2);

    // Búsqueda (RF-12): por código, sin marca.
    $this->getJson('/api/buscar?q=F00M')->assertOk()
        ->assertJsonPath('referencias.0.codigo_referencia', 'F00M144136')
        ->assertJsonPath('referencias.0.calidad', 'fino')
        ->assertJsonMissingPath('referencias.0.marca');
});

it('S-23: los endpoints de marcas ya no existen', function (string $metodo, string $ruta) {
    actuarComo(Rol::Admin);

    $this->json($metodo, $ruta, ['nombre' => 'X'])->assertNotFound();
})->with([
    ['GET', '/api/marcas'],
    ['POST', '/api/marcas'],
    ['GET', '/api/marcas/1'],
    ['PUT', '/api/marcas/1'],
    ['DELETE', '/api/marcas/1'],
]);
