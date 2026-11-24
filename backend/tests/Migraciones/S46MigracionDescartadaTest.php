<?php

use App\Enums\Rol;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * S-46 (11): la migración del estado `descartada` sube y baja sin ventas descartadas; con alguna,
 * down() se niega (las ventas nunca se borran y quedarían sin un estado válido).
 */

function migracionS46(): object
{
    return require database_path('migrations/2026_11_25_000100_ventas_descartada.php');
}

function valoresEstadoVenta(): string
{
    return DB::selectOne("SHOW COLUMNS FROM ventas LIKE 'estado'")->Type;
}

it('S-46 (11): la migración baja y vuelve a subir sin ventas descartadas', function () {
    $migracion = migracionS46();

    try {
        $migracion->down();
        expect(Schema::hasColumns('ventas', ['descartada_at']))->toBeFalse()
            ->and(Schema::hasColumn('ventas', 'descartada_por'))->toBeFalse()
            ->and(valoresEstadoVenta())->toBe("enum('abierta','cerrada')");
    } finally {
        if (! Schema::hasColumn('ventas', 'descartada_at')) {
            $migracion->up();
        }
    }

    expect(Schema::hasColumns('ventas', ['descartada_at', 'descartada_por']))->toBeTrue()
        ->and(valoresEstadoVenta())->toBe("enum('abierta','cerrada','descartada')");
});

it('S-46 (11): con ventas descartadas, down() se niega y no cambia nada', function () {
    $usuario = User::factory()->rol(Rol::Mostrador)->create();
    DB::table('ventas')->insert(['usuario_id' => $usuario->id, 'estado' => 'descartada', 'forma_pago' => 'contado', 'descartada_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

    expect(fn () => migracionS46()->down())->toThrow(RuntimeException::class, 'venta(s) descartada(s)');
    expect(Schema::hasColumns('ventas', ['descartada_at', 'descartada_por']))->toBeTrue()
        ->and(valoresEstadoVenta())->toBe("enum('abierta','cerrada','descartada')")
        ->and(DB::table('ventas')->where('estado', 'descartada')->count())->toBe(1);
});
