<?php

use App\Enums\Rol;
use App\Models\Auditoria;
use App\Models\CodigoUniversal;
use App\Models\Configuracion;
use App\Models\Unidad;

beforeEach(fn () => sembrarDemo());

/** Fija el mínimo general como admin y vuelve a la sesión anterior. */
function minimoGeneral(?int $valor): void
{
    $anterior = auth()->user();
    actuarComo(Rol::Admin);
    test()->putJson('/api/configuracion/stock-minimo', ['valor' => $valor])->assertOk();
    if ($anterior) {
        test()->actingAs($anterior);
    }
}

/** Códigos del informe Bajo mínimo (con filtros opcionales). */
function codigosBajoMinimo(array $filtros = []): array
{
    return collect(test()->getJson('/api/reportes/bajo-minimo?'.http_build_query($filtros))->assertOk()->json('data'))
        ->pluck('codigo')->all();
}

function filaBajoMinimo(string $codigo): ?array
{
    return collect(test()->getJson('/api/reportes/bajo-minimo')->assertOk()->json('data'))->firstWhere('codigo', $codigo);
}

it('RF-22 (a) / criterio 1: con mínimo general 2, una referencia sin valor propio alerta con existencia 2 y no con 3', function () {
    actuarComo(Rol::Logistica);
    minimoGeneral(2);
    ingresar(referencia('ARR-12V-9D'), 1); // 1 + 1 = 2

    expect(filaBajoMinimo('ARR-12V-9D'))->toMatchArray([
        'tipo' => 'referencia', 'existencia' => 2, 'minimo' => 2, 'origen' => 'general', 'faltante' => 0,
    ]);
    $this->getJson('/api/referencias/'.referencia('ARR-12V-9D')->id)->assertOk()
        ->assertJsonPath('bajo_minimo', true)
        ->assertJsonPath('minimo_efectivo', 2)
        ->assertJsonPath('minimo_origen', 'general')
        ->assertJsonPath('stock_minimo', null);

    ingresar(referencia('ARR-12V-9D'), 1); // 3

    expect(codigosBajoMinimo())->not->toContain('ARR-12V-9D');
    $this->getJson('/api/referencias/'.referencia('ARR-12V-9D')->id)->assertJsonPath('bajo_minimo', false);
});

it('RF-22 (b) / criterio 2: un mínimo personalizado 6 alerta con existencia 5 aunque el general sea 2', function () {
    actuarComo(Rol::Logistica);
    minimoGeneral(2);
    ingresar(referencia('ARR-12V-9D'), 4); // 1 + 4 = 5

    $this->putJson('/api/referencias/'.referencia('ARR-12V-9D')->id, ['stock_minimo' => 6])->assertOk()
        ->assertJsonPath('stock_minimo', 6)
        ->assertJsonPath('minimo_efectivo', 6)
        ->assertJsonPath('minimo_origen', 'personalizado')
        ->assertJsonPath('bajo_minimo', true);

    expect(filaBajoMinimo('ARR-12V-9D'))->toMatchArray(['existencia' => 5, 'minimo' => 6, 'origen' => 'personalizado', 'faltante' => 1]);
});

it('RF-22 (c) / criterio 3: stock_minimo 0 nunca alerta, aunque el general sea 5', function () {
    actuarComo(Rol::Logistica);
    minimoGeneral(5);
    $this->putJson('/api/referencias/'.referencia('ARR-12V-9D')->id, ['stock_minimo' => 0])->assertOk()
        ->assertJsonPath('minimo_efectivo', null)
        ->assertJsonPath('bajo_minimo', false);

    expect(codigosBajoMinimo())->not->toContain('ARR-12V-9D')
        ->and(codigosBajoMinimo())->toContain('SEN-TEMP-01'); // la otra, sin valor propio, sí usa el general
});

it('RF-22 (d) / criterio 4: sin mínimo general ni propio no alerta (tampoco con general 0)', function () {
    actuarComo(Rol::Logistica);

    expect(codigosBajoMinimo())->toBe([]);
    $this->getJson('/api/referencias/'.referencia('SEN-TEMP-01')->id)->assertJsonPath('bajo_minimo', false)->assertJsonPath('minimo_efectivo', null);

    minimoGeneral(0);
    expect(codigosBajoMinimo())->toBe([]);
});

it('RF-22 (e) / criterio 5: el universal IM204 se evalúa por la suma de sus referencias (2+2+2)', function () {
    actuarComo(Rol::Admin);
    $im204 = CodigoUniversal::where('codigo', 'IM204')->firstOrFail();

    $this->putJson("/api/codigos-universales/{$im204->id}", ['stock_minimo' => 6])->assertOk();

    $fila = filaBajoMinimo('IM204');
    expect($fila)->toMatchArray(['tipo' => 'universal', 'existencia' => 6, 'minimo' => 6, 'origen' => 'personalizado', 'proveedor' => null])
        ->and(collect($fila['referencias'])->pluck('codigo_referencia')->all())->toBe(['AC-77812', 'DE-IM204-A', 'RN-4410'])
        ->and(codigosBajoMinimo())->not->toContain('RN-4410'); // no se evalúa por referencia

    // El listado de Productos marca sus referencias con el mínimo del universal.
    $this->getJson('/api/referencias?codigo_universal_id='.$im204->id)->assertOk()
        ->assertJsonPath('data.0.bajo_minimo', true)
        ->assertJsonPath('data.0.minimo_efectivo', 6)
        ->assertJsonPath('data.0.minimo_por_universal', true);

    ingresar(referencia('RN-4410'), 1); // suma 7
    expect(codigosBajoMinimo())->not->toContain('IM204');
    $this->getJson('/api/referencias?codigo_universal_id='.$im204->id)->assertJsonPath('data.0.bajo_minimo', false);
});

it('RF-22 (e): un universal con mínimo NULL usa el general; una referencia del universal no lleva mínimo propio', function () {
    actuarComo(Rol::Logistica);
    minimoGeneral(6);

    expect(filaBajoMinimo('IM204'))->toMatchArray(['existencia' => 6, 'minimo' => 6, 'origen' => 'general']);

    $this->putJson('/api/referencias/'.referencia('RN-4410')->id, ['stock_minimo' => 3])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('stock_minimo');
});

it('RF-22 (f) / criterio 6: solo el admin cambia el general; logística y admin cambian el personalizado; mostrador 403', function () {
    $ref = referencia('ARR-12V-9D');

    actuarComo(Rol::Logistica);
    $this->getJson('/api/configuracion/stock-minimo')->assertForbidden();
    $this->putJson('/api/configuracion/stock-minimo', ['valor' => 3])->assertForbidden();
    $this->putJson("/api/referencias/{$ref->id}", ['stock_minimo' => 4])->assertOk();
    $this->putJson('/api/codigos-universales/'.CodigoUniversal::value('id'), ['stock_minimo' => 4])->assertForbidden();

    actuarComo(Rol::Mostrador);
    $this->putJson('/api/configuracion/stock-minimo', ['valor' => 3])->assertForbidden();
    $this->putJson("/api/referencias/{$ref->id}", ['stock_minimo' => 9])->assertForbidden();
    expect($ref->refresh()->stock_minimo)->toBe(4);

    actuarComo(Rol::Admin);
    $this->putJson('/api/configuracion/stock-minimo', ['valor' => 3])->assertOk()->assertJsonPath('valor', 3);
    $this->getJson('/api/configuracion/stock-minimo')->assertOk()->assertJsonPath('valor', 3);
    $this->putJson("/api/referencias/{$ref->id}", ['stock_minimo' => 5])->assertOk();
    $this->putJson('/api/configuracion/stock-minimo', ['valor' => null])->assertOk()->assertJsonPath('valor', null);
});

it('RF-22 (g) / criterio 7: cada cambio de mínimo queda en la auditoría con anterior, nuevo, usuario y fecha', function () {
    $admin = actuarComo(Rol::Admin);
    $this->putJson('/api/configuracion/stock-minimo', ['valor' => 2])->assertOk();
    $this->putJson('/api/configuracion/stock-minimo', ['valor' => 4])->assertOk();

    $config = Configuracion::where('clave', 'stock_minimo_general')->firstOrFail();
    $this->assertDatabaseHas('auditoria', ['tabla' => 'configuraciones', 'registro_id' => $config->id, 'campo' => 'valor', 'valor_anterior' => null, 'valor_nuevo' => '2', 'usuario_id' => $admin->id]);
    $this->assertDatabaseHas('auditoria', ['tabla' => 'configuraciones', 'registro_id' => $config->id, 'campo' => 'valor', 'valor_anterior' => '2', 'valor_nuevo' => '4', 'usuario_id' => $admin->id]);

    $im204 = CodigoUniversal::where('codigo', 'IM204')->firstOrFail();
    $this->putJson("/api/codigos-universales/{$im204->id}", ['stock_minimo' => 8])->assertOk();
    $this->assertDatabaseHas('auditoria', ['tabla' => 'codigos_universales', 'registro_id' => $im204->id, 'campo' => 'stock_minimo', 'valor_nuevo' => '8', 'usuario_id' => $admin->id]);

    $logistica = actuarComo(Rol::Logistica);
    $ref = referencia('ARR-12V-9D');
    $this->putJson("/api/referencias/{$ref->id}", ['stock_minimo' => 6])->assertOk();
    $this->putJson("/api/referencias/{$ref->id}", ['stock_minimo' => null])->assertOk();
    $this->assertDatabaseHas('auditoria', ['tabla' => 'referencias', 'registro_id' => $ref->id, 'campo' => 'stock_minimo', 'valor_anterior' => null, 'valor_nuevo' => '6', 'usuario_id' => $logistica->id]);
    $this->assertDatabaseHas('auditoria', ['tabla' => 'referencias', 'registro_id' => $ref->id, 'campo' => 'stock_minimo', 'valor_anterior' => '6', 'valor_nuevo' => null, 'usuario_id' => $logistica->id]);

    expect(Auditoria::where('campo', 'stock_minimo')->whereNull('created_at')->count())->toBe(0);
});

it('RF-22 (h) / criterio 8: el informe filtra por proveedor y categoría y excluye referencias desactivadas', function () {
    actuarComo(Rol::Logistica);
    minimoGeneral(2);

    $distribuidora = referencia('ARR-12V-9D')->proveedor_id; // ARR-12V-9D y SEN-TEMP-01
    $norte = referencia('RN-4410')->proveedor_id;              // IM210 (sus tres referencias) e IM204

    expect(codigosBajoMinimo(['proveedor_id' => $distribuidora]))->toEqualCanonicalizing(['ARR-12V-9D', 'SEN-TEMP-01'])
        ->and(codigosBajoMinimo(['proveedor_id' => $norte]))->not->toContain('ARR-12V-9D')
        ->and(codigosBajoMinimo(['categoria_id' => referencia('SEN-TEMP-01')->categoria_id]))->toBe(['SEN-TEMP-01']);

    // S-26: una referencia desactivada no se evalúa.
    $this->postJson('/api/referencias/'.referencia('SEN-TEMP-01')->id.'/desactivar')->assertOk();
    expect(codigosBajoMinimo())->not->toContain('SEN-TEMP-01');

    // Mayor faltante primero: SEN-TEMP-01 desactivada; IM210 (1+1+1 = 3 > 2) no alerta.
    $faltantes = collect($this->getJson('/api/reportes/bajo-minimo')->json('data'))->pluck('faltante')->all();
    expect($faltantes)->toBe(collect($faltantes)->sortDesc()->values()->all());
});

it('RF-22: un universal con una referencia desactivada suma solo las activas', function () {
    actuarComo(Rol::Admin);
    $im204 = CodigoUniversal::where('codigo', 'IM204')->firstOrFail();
    $this->putJson("/api/codigos-universales/{$im204->id}", ['stock_minimo' => 4])->assertOk();
    expect(codigosBajoMinimo())->not->toContain('IM204'); // 6 > 4

    // Se retiran las dos unidades de AC-77812 (RF-21) y se desactiva: la suma baja a 4.
    $codigos = Unidad::whereBelongsTo(referencia('AC-77812'))->pluck('codigo_barras')
        ->map(fn ($c) => ['codigo' => $c, 'metodo_captura' => 'digitado'])->all();
    $this->postJson('/api/ajustes/salidas', ['motivo' => 'perdida', 'codigos' => $codigos])->assertCreated();
    $this->postJson('/api/referencias/'.referencia('AC-77812')->id.'/desactivar')->assertOk();

    $fila = filaBajoMinimo('IM204');
    expect($fila['existencia'])->toBe(4)->and(collect($fila['referencias'])->pluck('codigo_referencia')->all())->toBe(['DE-IM204-A', 'RN-4410']);
});

it('RF-22 (i): valores inválidos responden 422', function (mixed $valor) {
    actuarComo(Rol::Admin);
    $ref = referencia('ARR-12V-9D');

    $this->putJson('/api/configuracion/stock-minimo', ['valor' => $valor])->assertUnprocessable()->assertJsonValidationErrors('valor');
    $this->putJson("/api/referencias/{$ref->id}", ['stock_minimo' => $valor])->assertUnprocessable()->assertJsonValidationErrors('stock_minimo');
    $this->putJson('/api/codigos-universales/'.CodigoUniversal::value('id'), ['stock_minimo' => $valor])->assertUnprocessable()->assertJsonValidationErrors('stock_minimo');

    expect($ref->refresh()->stock_minimo)->toBeNull()
        ->and(Configuracion::where('clave', 'stock_minimo_general')->value('valor'))->toBeNull();
})->with([-1, 2.5, 10000, 'abc']);

it('RF-22: el general exige el campo valor (null para quitarlo)', function () {
    actuarComo(Rol::Admin);
    $this->putJson('/api/configuracion/stock-minimo', [])->assertUnprocessable()->assertJsonValidationErrors('valor');
});

it('RF-22 / S-28: Bajo mínimo es de admin y logística; mostrador recibe 403 (Agotados sigue para los tres)', function () {
    foreach ([Rol::Admin, Rol::Logistica] as $rol) {
        actuarComo($rol);
        $this->getJson('/api/reportes/bajo-minimo')->assertOk();
    }

    actuarComo(Rol::Mostrador);
    $this->getJson('/api/reportes/bajo-minimo')->assertForbidden();

    foreach ([Rol::Admin, Rol::Logistica, Rol::Mostrador] as $rol) {
        actuarComo($rol);
        $this->getJson('/api/reportes/agotados?proveedor_id='.referencia('SEN-TEMP-01')->proveedor_id)->assertOk();
    }
});

it('RF-22 / S-28: el listado de productos no envía el stock mínimo a mostrador', function () {
    minimoGeneral(5);
    $campos = ['stock_minimo', 'minimo_efectivo', 'minimo_origen', 'minimo_por_universal', 'bajo_minimo'];

    actuarComo(Rol::Mostrador);
    $fila = $this->getJson('/api/referencias?por_pagina=100')->assertOk()->json('data.0');
    expect(array_intersect($campos, array_keys($fila)))->toBe([]);
    $universal = collect($this->getJson('/api/referencias?por_pagina=100')->json('data'))->firstWhere('codigo_universal', '!=', null);
    expect($universal['codigo_universal'])->not->toHaveKey('stock_minimo');

    actuarComo(Rol::Logistica);
    $fila = $this->getJson('/api/referencias?por_pagina=100')->assertOk()->json('data.0');
    expect(array_keys($fila))->toContain(...$campos);
});

it('RF-08 / RF-22: Agotados acepta el filtro de categoría', function () {
    actuarComo(Rol::Logistica);
    $prov = referencia('SEN-TEMP-01')->proveedor_id;

    $this->getJson("/api/reportes/agotados?proveedor_id={$prov}&categoria_id=".referencia('SEN-TEMP-01')->categoria_id)
        ->assertOk()->assertJsonPath('data.0.codigo_referencia', 'SEN-TEMP-01');
    $this->getJson("/api/reportes/agotados?proveedor_id={$prov}&categoria_id=".referencia('ARR-12V-9D')->categoria_id)
        ->assertOk()->assertJsonCount(0, 'data');
});
