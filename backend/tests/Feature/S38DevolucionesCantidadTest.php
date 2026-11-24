<?php

use App\Enums\Rol;
use App\Models\AjusteInventario;
use App\Models\Cliente;
use App\Models\Referencia;
use App\Models\Reverso;
use App\Models\Unidad;
use Illuminate\Support\Facades\DB;

/*
 * RF-07 / RF-21 (S-38; ajusta S-27 y S-34): devoluciones por grupo (venta, referencia, precio) con
 * cantidad y motivo opcional; salidas con cantidad por código de proveedor.
 */

beforeEach(fn () => sembrarDemo());

function devolverGrupo(int $ventaId, Referencia $ref, string|int $precio, int $cantidad, array $extra = [])
{
    return test()->postJson('/api/devoluciones', [
        'venta_id' => $ventaId, 'referencia_id' => $ref->id, 'precio_unitario' => $precio, 'cantidad' => $cantidad,
        'metodo_captura' => 'digitado', ...$extra,
    ]);
}

function saldoS38(Cliente $cliente): string
{
    return test()->getJson("/api/clientes/{$cliente->id}/cartera")->json('resumen.saldo');
}

it('S-38 (a): devolver 2 de 3 unidades de un grupo: un solo reverso de 2 ítems, el saldo baja por los 2 y queda 1', function () {
    $ref = refS38('GRP-1', 3, 80000);
    $cliente = Cliente::factory()->create(['nombre' => 'Taller Grupo']);
    [$ventaId] = venderS38(codigosS38($ref), $cliente);

    actuarComo(Rol::Logistica);
    $this->getJson('/api/devoluciones/consulta?codigo=GRP-1')->assertOk()
        ->assertJsonPath('ventas.0.venta_id', $ventaId)
        ->assertJsonPath('ventas.0.cantidad_disponible', 3)
        ->assertJsonPath('ventas.0.precio_unitario', '80000.00')
        ->assertJsonPath('ventas.0.saldo_actual', '240000.00')
        ->assertJsonPath('ventas.0.cliente.nombre', 'Taller Grupo')
        // En el orden en que se devolverían (menor ítem primero).
        ->assertJsonPath('ventas.0.codigos', codigosS38($ref));

    $r = devolverGrupo($ventaId, $ref, '80000.00', 2)->assertCreated()
        ->assertJsonPath('cantidad', 2)
        ->assertJsonPath('total', '160000.00')
        ->assertJsonPath('saldo_cliente.antes', '240000.00')
        ->assertJsonPath('saldo_cliente.despues', '80000.00');

    $codigos = codigosS38($ref);
    expect($r->json('devueltas'))->toBe([$codigos[0], $codigos[1]])
        ->and($r->json('devueltas_ids'))->toHaveCount(2)
        ->and(Reverso::where('venta_id', $ventaId)->count())->toBe(1)
        ->and(Reverso::where('venta_id', $ventaId)->first()->items()->count())->toBe(2)
        ->and(Unidad::where('referencia_id', $ref->id)->where('estado', 'disponible')->count())->toBe(2)
        ->and($ref->refresh()->existencia)->toBe(2);

    $this->getJson('/api/devoluciones/consulta?codigo=GRP-1')->assertJsonPath('ventas.0.cantidad_disponible', 1);
    actuarComo(Rol::Admin);
    expect(saldoS38($cliente))->toBe('80000.00');
});

it('S-38 (b): cantidad 0, mayor a la disponible, de otra referencia o de una venta abierta: 422 sin reversar nada', function () {
    $ref = refS38('GRP-2', 4, 50000);
    $otra = refS38('GRP-X', 1, 50000);
    [$cerrada] = venderS38(array_slice(codigosS38($ref), 0, 3));
    [$abierta] = venderS38(array_slice(codigosS38($ref), 3, 1), cerrar: false);

    actuarComo(Rol::Logistica);
    devolverGrupo($cerrada, $ref, 50000, 0)->assertUnprocessable()
        ->assertJsonPath('message', 'La cantidad debe estar entre 1 y 3 (las unidades sin devolver de ese grupo).');
    devolverGrupo($cerrada, $ref, 50000, 4)->assertUnprocessable()->assertJsonPath('campo', 'cantidad');
    devolverGrupo($cerrada, $otra, 50000, 1)->assertUnprocessable()
        ->assertJsonPath('message', "La venta #{$cerrada} no tiene unidades de esa referencia a ese precio sin devolver.");
    devolverGrupo($cerrada, $ref, 49000, 1)->assertUnprocessable();
    devolverGrupo($abierta, $ref, 50000, 1)->assertUnprocessable()
        ->assertJsonPath('message', "La venta #{$abierta} está abierta: anule el ítem en la venta.");
    devolverGrupo(999999, $ref, 50000, 1)->assertNotFound();

    expect(Reverso::count())->toBe(0);
});

it('S-38 (c): precios distintos de la misma referencia en una venta a crédito son grupos distintos', function () {
    $ref = refS38('GRP-3', 3, 80000);
    $cliente = Cliente::factory()->create();
    [$ventaId] = venderS38(codigosS38($ref), $cliente, true, [80000, 80000, 70000]);

    actuarComo(Rol::Logistica);
    $grupos = collect($this->getJson('/api/devoluciones/consulta?codigo=GRP-3')->assertOk()->json('ventas'));
    expect($grupos)->toHaveCount(2)
        ->and($grupos->pluck('precio_unitario')->all())->toBe(['80000.00', '70000.00'])
        ->and($grupos->pluck('cantidad_disponible')->all())->toBe([2, 1]);

    devolverGrupo($ventaId, $ref, '70000.00', 1)->assertCreated()
        ->assertJsonPath('saldo_cliente.antes', '230000.00')
        ->assertJsonPath('saldo_cliente.despues', '160000.00');
});

it('S-38 (d): sin motivo se guarda "Devolución del cliente"; con motivo y nota, "motivo — nota"', function () {
    $ref = refS38('MOT-1', 2);
    [$ventaId] = venderS38(codigosS38($ref));

    actuarComo(Rol::Logistica);
    devolverGrupo($ventaId, $ref, 80000, 1)->assertCreated()->assertJsonPath('reverso.motivo', 'Devolución del cliente');
    devolverGrupo($ventaId, $ref, 80000, 1, ['motivo' => 'Producto equivocado', 'nota' => 'pidió el de 24 V'])->assertCreated()
        ->assertJsonPath('reverso.motivo', 'Producto equivocado — pidió el de 24 V');

    expect(Reverso::where('venta_id', $ventaId)->orderBy('id')->pluck('motivo')->all())
        ->toBe(['Devolución del cliente', 'Producto equivocado — pidió el de 24 V']);
});

it('S-38 (e): con la etiqueta del local se devuelve esa unidad (cantidad 1), como antes', function () {
    $ref = refS38('ETQ-38', 2);
    [$a] = codigosS38($ref);
    $cliente = Cliente::factory()->create();
    venderS38([$a], $cliente);

    actuarComo(Rol::Logistica);
    $this->getJson("/api/devoluciones/consulta?codigo={$a}")->assertOk()
        ->assertJsonPath('unidad.codigo_barras', $a)
        ->assertJsonPath('saldo_actual', '80000.00');
    $this->postJson('/api/devoluciones', ['codigo' => $a, 'metodo_captura' => 'escaneado'])->assertCreated()
        ->assertJsonPath('cantidad', 1)
        ->assertJsonPath('devueltas', [$a])
        ->assertJsonPath('unidad.estado', 'disponible')
        ->assertJsonPath('reverso.motivo', 'Devolución del cliente')
        ->assertJsonPath('saldo_cliente.despues', '0.00');
});

it('S-38 (i): mostrador sigue sin acceso y sus reversos siguen exigiendo motivo', function () {
    $ref = refS38('ROL-38', 1);
    [$ventaId, [$item]] = venderS38(codigosS38($ref));

    actuarComo(Rol::Mostrador);
    $this->getJson('/api/devoluciones/consulta?codigo=ROL-38')->assertForbidden();
    devolverGrupo($ventaId, $ref, 80000, 1)->assertForbidden();
    $this->getJson('/api/ajustes/unidad?tipo=baja&codigo=ROL-38&cantidad=1')->assertForbidden();
    $this->postJson("/api/ventas/{$ventaId}/reversos", ['tipo' => 'devolucion', 'venta_item_id' => $item])
        ->assertUnprocessable()->assertJsonValidationErrors('motivo');

    expect(DB::table('reversos')->count())->toBe(0);
});
