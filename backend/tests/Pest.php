<?php

use App\Enums\Rol;
use App\Models\Cliente;
use App\Models\Ingreso;
use App\Models\Referencia;
use App\Models\Unidad;
use App\Models\User;
use App\Services\IngresoService;
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
