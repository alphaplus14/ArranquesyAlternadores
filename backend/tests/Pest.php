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
