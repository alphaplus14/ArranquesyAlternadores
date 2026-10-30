<?php

use App\Enums\Rol;
use App\Models\Ingreso;
use App\Models\Referencia;
use App\Models\Unidad;
use App\Models\User;
use App\Services\IngresoService;
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

/** Crea un usuario del rol indicado y deja la sesión iniciada con él. */
function actuarComo(Rol $rol): User
{
    $usuario = User::factory()->rol($rol)->create();
    test()->actingAs($usuario);

    return $usuario;
}

/** Siembra los ejemplos del documento (IM204 = 2+2+2, regulador IM210 en tres marcas). */
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
