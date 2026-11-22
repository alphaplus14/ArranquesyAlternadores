<?php

use App\Enums\Rol;
use App\Models\Categoria;
use App\Models\Proveedor;
use App\Models\Unidad;
use App\Models\Venta;
use App\Models\VentaItem;
use Illuminate\Support\Facades\DB;

/*
 * RF-14 / S-15 (21-nov-2026): todos los productos se venden con IVA. El IVA de venta ya no se
 * pregunta al crear una referencia: queda en 19 %. Las referencias existentes conservan el suyo.
 */

beforeEach(fn () => sembrarDemo());

it('RF-14: crear una referencia sin enviar el IVA de venta la deja en 19 %', function () {
    actuarComo(Rol::Logistica);

    $id = $this->postJson('/api/referencias', [
        'codigo_referencia' => 'IVA-'.uniqid(),
        'proveedor_id' => Proveedor::value('id'),
        'categoria_id' => Categoria::where('admite_codigo_universal', false)->value('id'),
        'descripcion' => 'Pieza sin IVA enviado',
        'precio_venta' => 1000,
    ])->assertCreated()->json('id');

    expect(DB::table('referencias')->where('id', $id)->value('iva_venta_porcentaje'))->toBe('19.00');
});

it('RF-14: editar una referencia sin enviar el IVA de venta no lo cambia', function () {
    actuarComo(Rol::Admin);
    $referencia = referencia('ARR-12V-9D');
    DB::table('referencias')->where('id', $referencia->id)->update(['iva_venta_porcentaje' => 0]);

    $this->patchJson("/api/referencias/{$referencia->id}", ['descripcion' => 'Arranque 12V 9 dientes (editado)', 'precio_venta' => 430000])
        ->assertOk();

    expect($referencia->fresh()->iva_venta_porcentaje)->toBe('0.00');
});

it('RF-14: una referencia existente con IVA 0 se sigue vendiendo con IVA 0', function () {
    $referencia = referencia('ARR-12V-9D');
    DB::table('referencias')->where('id', $referencia->id)->update(['iva_venta_porcentaje' => 0]);
    $unidad = Unidad::whereBelongsTo($referencia)->where('estado', 'disponible')->firstOrFail();
    $mostrador = actuarComo(Rol::Mostrador);
    $venta = Venta::create(['usuario_id' => $mostrador->id]);

    $this->postJson("/api/ventas/{$venta->id}/escanear", ['codigo_barras' => $unidad->codigo_barras, 'metodo_captura' => 'escaneado'])
        ->assertCreated();

    expect(VentaItem::where('unidad_id', $unidad->id)->value('iva_porcentaje'))->toBe('0.00');
});
