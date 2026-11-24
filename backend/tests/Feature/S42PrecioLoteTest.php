<?php

use App\Enums\Rol;
use App\Models\Auditoria;
use App\Models\Cliente;
use App\Models\VentaItem;
use Illuminate\Support\Facades\DB;

/*
 * RF-14 / RF-11 (S-42): en el modal de cierre se corrige el precio de un grupo (misma referencia y
 * precio) de una venta a crédito abierta, en una operación, con auditoría por ítem.
 */

beforeEach(fn () => sembrarDemo());

/** Venta abierta con 3 unidades de una referencia; devuelve [ventaId, ids de ítems, mostrador]. */
function grupoS42(bool $credito = true): array
{
    $codigo = 'GRP-PRECIO-'.($credito ? 'C' : 'K').random_int(100, 999);
    $ref = refS38($codigo, 3, 80000);
    $mostrador = actuarComo(Rol::Mostrador);
    $cliente = Cliente::factory()->create();
    $ventaId = abrirVenta($credito ? ['forma_pago' => 'credito', 'cliente_id' => $cliente->id] : []);
    $ids = collect(test()->postJson("/api/ventas/{$ventaId}/escanear", ['codigo_barras' => $codigo, 'metodo_captura' => 'escaneado', 'cantidad' => 3])
        ->assertCreated()->json('items'))->pluck('id')->all();

    return [$ventaId, $ids, $mostrador];
}

it('S-42: cambia el precio de un grupo de 3 en una venta a crédito y audita cada ítem con el valor anterior y el nuevo', function () {
    [$ventaId, $ids, $mostrador] = grupoS42();

    $this->patchJson("/api/ventas/{$ventaId}/items/precio", ['ids' => $ids, 'precio_unitario' => 75000])->assertOk()
        ->assertJsonPath('totales.total', '225000.00');

    expect(VentaItem::whereKey($ids)->pluck('precio_unitario')->unique()->values()->all())->toBe(['75000.00']);
    $audit = Auditoria::where('tabla', 'venta_items')->whereIn('registro_id', $ids)->get();
    expect($audit)->toHaveCount(3)
        ->and($audit->pluck('valor_anterior')->unique()->values()->all())->toBe(['80000.00'])
        ->and($audit->pluck('valor_nuevo')->unique()->values()->all())->toBe(['75000.00'])
        ->and($audit->every(fn ($a) => $a->usuario_id === $mostrador->id && $a->created_at !== null))->toBeTrue();
});

it('S-42: 422 en contado, en venta cerrada, con precio 0 y con ítems ajenos o reversados; nada cambia', function () {
    [$contado, $idsContado] = grupoS42(false);
    $this->patchJson("/api/ventas/{$contado}/items/precio", ['ids' => $idsContado, 'precio_unitario' => 70000])->assertUnprocessable()
        ->assertJsonPath('message', 'Solo en una venta a crédito se puede cambiar el precio.');

    [$ventaId, $ids] = grupoS42();
    $this->patchJson("/api/ventas/{$ventaId}/items/precio", ['ids' => $ids, 'precio_unitario' => 0])->assertUnprocessable()->assertJsonValidationErrors('precio_unitario');
    $this->patchJson("/api/ventas/{$ventaId}/items/precio", ['ids' => [...$ids, $idsContado[0]], 'precio_unitario' => 70000])->assertUnprocessable()
        ->assertJsonPath('message', 'Algún ítem no pertenece a esta venta: no se cambió ningún precio.');

    $this->postJson("/api/ventas/{$ventaId}/reversos", ['tipo' => 'anulacion', 'motivo' => 'Sobraba', 'venta_item_id' => $ids[2]])->assertOk();
    $this->patchJson("/api/ventas/{$ventaId}/items/precio", ['ids' => $ids, 'precio_unitario' => 70000])->assertUnprocessable()
        ->assertJsonPath('message', 'Algún ítem ya fue reversado: no se cambió ningún precio.');

    $this->postJson("/api/ventas/{$ventaId}/cerrar")->assertOk();
    $this->patchJson("/api/ventas/{$ventaId}/items/precio", ['ids' => [$ids[0]], 'precio_unitario' => 70000])->assertUnprocessable()
        ->assertJsonPath('message', 'La venta está cerrada: sus precios quedaron fijos.');

    expect(VentaItem::whereKey([...$ids, ...$idsContado])->pluck('precio_unitario')->unique()->values()->all())->toBe(['80000.00'])
        ->and(Auditoria::where('tabla', 'venta_items')->count())->toBe(0);
});

it('S-42 / S-43: un mostrador ajeno, logística y el admin reciben 403', function () {
    [$ventaId, $ids] = grupoS42();

    actuarComo(Rol::Mostrador);
    $this->patchJson("/api/ventas/{$ventaId}/items/precio", ['ids' => $ids, 'precio_unitario' => 70000])->assertForbidden();
    actuarComo(Rol::Logistica);
    $this->patchJson("/api/ventas/{$ventaId}/items/precio", ['ids' => $ids, 'precio_unitario' => 70000])->assertForbidden();

    actuarComo(Rol::Admin);
    $this->patchJson("/api/ventas/{$ventaId}/items/precio", ['ids' => $ids, 'precio_unitario' => 70000])->assertForbidden();
    expect(DB::table('venta_items')->whereIn('id', $ids)->pluck('precio_unitario')->unique()->values()->all())->toBe(['80000.00']);
});
