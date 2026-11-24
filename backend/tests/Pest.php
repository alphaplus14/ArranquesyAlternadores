<?php

use App\Enums\FormaPago;
use App\Enums\MetodoCaptura;
use App\Enums\Rol;
use App\Models\Cliente;
use App\Models\Ingreso;
use App\Models\Referencia;
use App\Models\Unidad;
use App\Models\User;
use App\Models\Venta;
use App\Services\IngresoService;
use App\Services\VentaService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/*
 * Los tests corren contra MySQL 8 (base inventario_test, ver phpunit.xml): lockForUpdate,
 * FULLTEXT y los límites por relación necesitan el motor real.
 */
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

// Concurrencia real entre procesos: los datos deben estar confirmados, no dentro de una transacción.
pest()->extend(TestCase::class)
    ->use(DatabaseTruncation::class)
    ->in('Concurrencia');

// RF-12: el índice FULLTEXT de InnoDB no ve filas sin confirmar dentro de una transacción.
pest()->extend(TestCase::class)
    ->use(DatabaseTruncation::class)
    ->in('Busqueda');

/** Crea un usuario del rol indicado y deja la sesión iniciada con él. */
function actuarComo(Rol $rol): User
{
    $usuario = User::factory()->rol($rol)->create();
    test()->actingAs($usuario);

    return $usuario;
}

/** Petición desde la SPA (dominio stateful): pasa por el AuthenticateSession de Sanctum. */
function comoSpa(): array
{
    return ['Referer' => 'http://'.config('sanctum.stateful')[0], 'Accept' => 'application/json'];
}

/** Siembra los ejemplos del documento (IM204 = 2+2+2; IM210 con tres referencias, S-23). */
function sembrarDemo(): void
{
    test()->seed();
}

function referencia(string $codigo): Referencia
{
    return Referencia::where('codigo_referencia', $codigo)->firstOrFail();
}

function unidadDe(string $codigoReferencia): Unidad
{
    return Unidad::whereBelongsTo(referencia($codigoReferencia))->where('estado', 'disponible')->orderBy('id')->firstOrFail();
}

/** RF-05: ingreso por remisión confirmado de una sola línea. */
function ingresar(Referencia $referencia, int $cantidad, ?User $usuario = null): Ingreso
{
    $usuario ??= User::factory()->rol(Rol::Logistica)->create();
    $servicio = app(IngresoService::class);

    $ingreso = $servicio->crearBorrador([
        'proveedor_id' => $referencia->proveedor_id,
        'numero_documento' => 'REM-T'.random_int(1000, 9999),
        'fecha' => now()->toDateString(),
    ], $usuario);
    $servicio->agregarItem($ingreso, ['referencia_id' => $referencia->id, 'cantidad' => $cantidad, 'costo_unitario' => 1000]);

    return $servicio->confirmar($ingreso, $usuario);
}

/**
 * RF-06: vende la primera unidad disponible de la referencia como mostrador (contado o, con
 * $venta, a crédito) y vuelve a la sesión anterior. Devuelve [ventaId, código de la unidad].
 */
function venderComoMostrador(string $codigoReferencia, bool $cerrar = true, array $venta = []): array
{
    $anterior = auth()->user();
    actuarComo(Rol::Mostrador);

    $codigo = unidadDe($codigoReferencia)->codigo_barras;
    $ventaId = test()->postJson('/api/ventas', $venta)->assertCreated()->json('id');
    test()->postJson("/api/ventas/{$ventaId}/escanear", ['codigo_barras' => $codigo, 'metodo_captura' => 'escaneado'])->assertCreated();

    if ($cerrar) {
        test()->postJson("/api/ventas/{$ventaId}/cerrar")->assertOk();
    }

    if ($anterior) {
        test()->actingAs($anterior);
    }

    return [$ventaId, $codigo];
}

/* RF-15 / RF-18: ayudas de cartera (ventas a crédito y abonos). */

/**
 * Venta a crédito con un ítem por precio (cada uno, una unidad disponible distinta), como mostrador.
 * Devuelve [ventaId, ids de los ítems]. Deja la sesión que había.
 *
 * @param  list<int>  $precios
 */
function ventaCredito(Cliente $cliente, array $precios, bool $cerrar = true): array
{
    $anterior = auth()->user();
    actuarComo(Rol::Mostrador);

    $ventaId = test()->postJson('/api/ventas', ['forma_pago' => 'credito', 'cliente_id' => $cliente->id])->assertCreated()->json('id');
    $items = [];
    $unidades = Unidad::where('estado', 'disponible')->orderBy('id')->limit(count($precios))->pluck('codigo_barras');
    foreach ($precios as $i => $precio) {
        $itemId = test()->postJson("/api/ventas/{$ventaId}/escanear", ['codigo_barras' => $unidades[$i], 'metodo_captura' => 'escaneado'])->assertCreated()->json('venta_item_id');
        test()->patchJson("/api/ventas/{$ventaId}/items/{$itemId}", ['precio_unitario' => $precio])->assertOk();
        $items[] = $itemId;
    }
    if ($cerrar) {
        test()->postJson("/api/ventas/{$ventaId}/cerrar")->assertOk();
    }

    if ($anterior) {
        test()->actingAs($anterior);
    }

    return [$ventaId, $items];
}

/** Mueve el cierre y el vencimiento de una venta (sin pasar por el modelo: es preparación del test). */
function fecharVenta(int $ventaId, string $cerrada, string $vence): void
{
    DB::table('ventas')->where('id', $ventaId)->update(['cerrada_at' => $cerrada.' 15:00:00', 'vence_el' => $vence]);
}

function abonar(Cliente $cliente, mixed $valor, array $extra = [])
{
    return test()->postJson("/api/clientes/{$cliente->id}/abonos", ['fecha' => now('America/Bogota')->toDateString(), 'valor' => $valor, 'medio' => 'efectivo', ...$extra]);
}

/** RF-14: venta a crédito cerrada con las unidades de RN-4410 indicadas, cada una al precio dado. */
function ventaCreditoCerrada(Cliente $cliente, int $unidades, int $precio): Venta
{
    referencia('RN-4410')->update(['precio_venta' => $precio]);
    $mostrador = User::factory()->rol(Rol::Mostrador)->create();
    $servicio = app(VentaService::class);

    $venta = $servicio->abrir($mostrador, FormaPago::Credito, $cliente->id);
    for ($i = 0; $i < $unidades; $i++) {
        $servicio->escanear($venta, unidadDe('RN-4410')->codigo_barras, MetodoCaptura::Escaneado, $mostrador);
    }

    return $servicio->cerrar($venta);
}

/** RF-16: ingreso de una línea sin IVA (total = cantidad × costo); confirmado salvo que se pida borrador. */
function compra(Referencia $referencia, int $cantidad, int $costo, array $encabezado = [], bool $confirmar = true): Ingreso
{
    $usuario = User::factory()->rol(Rol::Logistica)->create();
    $servicio = app(IngresoService::class);

    $ingreso = $servicio->crearBorrador([
        'proveedor_id' => $referencia->proveedor_id,
        'numero_documento' => 'DOC-'.random_int(10000, 99999),
        'fecha' => now()->toDateString(),
        ...$encabezado,
    ], $usuario);
    $servicio->agregarItem($ingreso, ['referencia_id' => $referencia->id, 'cantidad' => $cantidad, 'costo_unitario' => $costo, 'iva_porcentaje' => 0]);

    return $confirmar ? $servicio->confirmar($ingreso, $usuario) : $ingreso;
}

/** RF-16: encabezado de un ingreso a crédito. */
function aCredito(string $vence = '2026-12-31'): array
{
    return ['forma_pago' => 'credito', 'vence_el' => $vence];
}

/* S-38: ayudas de devoluciones y salidas con cantidad. */

function refS38(string $codigo, int $unidades, int $precio = 80000): Referencia
{
    $ref = Referencia::factory()->create(['codigo_referencia' => $codigo, 'precio_venta' => $precio]);
    if ($unidades > 0) {
        ingresar($ref, $unidades);
    }

    return $ref->refresh();
}

function codigosS38(Referencia $ref): array
{
    return Unidad::where('referencia_id', $ref->id)->orderBy('id')->pluck('codigo_barras')->all();
}

/**
 * Venta (a crédito si hay cliente) con esas etiquetas; $precios cambia el precio de cada ítem mientras
 * está abierta (S-18). Deja la sesión que había. Devuelve [ventaId, ids de ítems].
 */
function venderS38(array $codigos, ?Cliente $cliente = null, bool $cerrar = true, array $precios = []): array
{
    $anterior = auth()->user();
    actuarComo(Rol::Mostrador);
    $ventaId = test()->postJson('/api/ventas', $cliente ? ['forma_pago' => 'credito', 'cliente_id' => $cliente->id] : [])->assertCreated()->json('id');
    $items = [];
    foreach ($codigos as $i => $c) {
        $items[] = $itemId = test()->postJson("/api/ventas/{$ventaId}/escanear", ['codigo_barras' => $c, 'metodo_captura' => 'escaneado'])->assertCreated()->json('venta_item_id');
        if (isset($precios[$i])) {
            test()->patchJson("/api/ventas/{$ventaId}/items/{$itemId}", ['precio_unitario' => $precios[$i]])->assertOk();
        }
    }
    if ($cerrar) {
        test()->postJson("/api/ventas/{$ventaId}/cerrar")->assertOk();
    }
    if ($anterior) {
        test()->actingAs($anterior);
    }

    return [$ventaId, $items];
}
