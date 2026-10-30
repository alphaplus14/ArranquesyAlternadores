<?php

use App\Enums\Rol;
use App\Models\CodigoUniversal;
use App\Models\Unidad;
use App\Models\Venta;

beforeEach(fn () => sembrarDemo());

it('RF-03: confirmar un ingreso crea una unidad por cantidad con código del local único', function () {
    $antes = Unidad::count();

    $ingreso = ingresar(referencia('SEN-TEMP-01'), 5);

    $codigos = Unidad::whereIn('ingreso_item_id', $ingreso->items()->select('id'))->pluck('codigo_barras');
    expect(Unidad::count())->toBe($antes + 5)
        ->and($codigos)->toHaveCount(5)
        ->and($codigos->unique())->toHaveCount(5)
        ->and($codigos->every(fn ($c) => preg_match('/^A8\d{6}$/', $c) === 1))->toBeTrue()
        ->and(referencia('SEN-TEMP-01')->existencia)->toBe(5);
});

it('RF-03: los códigos de barras son consecutivos entre ingresos y nunca se repiten', function () {
    ingresar(referencia('SEN-TEMP-01'), 2);
    ingresar(referencia('SEN-TEMP-01'), 2);

    expect(Unidad::count())->toBe(Unidad::distinct()->count('codigo_barras'));
});

it('RF-03: escanear la unidad Bosch la deja en 0 y el total baja de 3 a 2, sin tocar las otras marcas', function () {
    $mostrador = actuarComo(Rol::Mostrador);
    $im210 = CodigoUniversal::where('codigo', 'IM210')->first();
    $bosch = unidadDe('F00M144136');
    $venta = Venta::create(['usuario_id' => $mostrador->id]);

    expect((int) CodigoUniversal::conExistencia()->find($im210->id)->existencia)->toBe(3);

    $this->postJson("/api/ventas/{$venta->id}/escanear", [
        'codigo_barras' => $bosch->codigo_barras,
        'metodo_captura' => 'escaneado',
    ])->assertCreated();

    $porMarca = collect($this->getJson("/api/codigos-universales/{$im210->id}")->json('por_marca'))->pluck('existencia', 'marca');

    expect($porMarca->all())->toEqual(['Bosch' => 0, 'Transpo' => 1, 'Marca Ejemplo' => 1])
        ->and((int) CodigoUniversal::conExistencia()->find($im210->id)->existencia)->toBe(2);
});

it('RF-03: genera el PDF de etiquetas en los tres tamaños', function (string $tamano) {
    actuarComo(Rol::Logistica);
    $ids = Unidad::limit(3)->pluck('id')->all();

    $respuesta = $this->post('/api/etiquetas', ['unidad_ids' => $ids, 'tamano' => $tamano], ['Accept' => 'application/json']);

    $respuesta->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect(substr($respuesta->getContent(), 0, 5))->toBe('%PDF-');
})->with(['grande', 'pequeno', 'mas_pequeno']);

it('RF-03: rechaza un tamaño de etiqueta que no existe', function () {
    actuarComo(Rol::Logistica);

    $this->postJson('/api/etiquetas', ['unidad_ids' => [Unidad::first()->id], 'tamano' => 'gigante'])
        ->assertUnprocessable();
});
