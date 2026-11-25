<?php

use App\Enums\Rol;
use App\Models\Categoria;
use App\Models\MovimientoInventario;
use App\Models\Proveedor;
use App\Models\VentaItem;
use Illuminate\Support\Facades\DB;

beforeEach(fn () => sembrarDemo());

/** Datos válidos de una referencia nueva, salvo el precio. */
function referenciaNueva(mixed $precio): array
{
    return [
        'codigo_referencia' => 'PRECIO-'.uniqid(),
        'proveedor_id' => Proveedor::value('id'),
        'categoria_id' => Categoria::where('admite_codigo_universal', false)->value('id') ?? Categoria::value('id'),
        'descripcion' => 'Pieza de prueba',
        'precio_venta' => $precio,
    ];
}

it('RF-14: crear una referencia con precio 0 o negativo responde 422', function (mixed $precio) {
    actuarComo(Rol::Admin);

    $this->postJson('/api/referencias', referenciaNueva($precio))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('precio_venta');
})->with([0, '0.00', -1000]);

it('RF-14: crear una referencia con precio mayor que 0 sigue funcionando', function () {
    actuarComo(Rol::Admin);

    $this->postJson('/api/referencias', referenciaNueva('0.01'))->assertCreated();
});

it('RF-14: editar una referencia a precio 0 o negativo responde 422 y no cambia el precio', function (mixed $precio) {
    actuarComo(Rol::Admin);
    $referencia = referencia('RN-4410');

    $this->patchJson("/api/referencias/{$referencia->id}", ['precio_venta' => $precio])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('precio_venta');

    expect($referencia->fresh()->precio_venta)->toBe('85000.00');
})->with([0, -1]);

it('RF-14: escanear una unidad de una referencia con precio 0 responde 422 y la unidad sigue disponible', function () {
    actuarComo(Rol::Mostrador);
    // Una referencia antigua que quedó en 0 antes de la regla: se fuerza sin pasar por la API.
    DB::table('referencias')->where('codigo_referencia', 'RN-4410')->update(['precio_venta' => 0]);
    $unidad = unidadDe('RN-4410');
    $existencia = referencia('RN-4410')->existencia;
    $movimientos = MovimientoInventario::count();
    $id = abrirVenta();

    $this->postJson("/api/ventas/{$id}/escanear", ['codigo_barras' => $unidad->codigo_barras, 'metodo_captura' => 'escaneado'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'La referencia RN-4410 no tiene precio de venta; pídale al administrador que se lo asigne.');

    expect($unidad->fresh()->estado->value)->toBe('disponible')
        ->and(referencia('RN-4410')->existencia)->toBe($existencia)
        ->and(VentaItem::count())->toBe(0)
        ->and(MovimientoInventario::count())->toBe($movimientos);
});
