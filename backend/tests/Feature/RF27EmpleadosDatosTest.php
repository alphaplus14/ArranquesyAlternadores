<?php

use App\Enums\FormaAbonoEmpleado;
use App\Enums\Rol;
use App\Enums\TipoMovimientoEmpleado;
use App\Exceptions\RegistroInmutableException;
use App\Models\Auditoria;
use App\Models\CategoriaCaja;
use App\Models\Empleado;
use App\Models\MovimientoCaja;
use App\Models\MovimientoEmpleado;
use App\Models\MovimientoEmpleadoAnulacion;
use Database\Seeders\CajaSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

/*
 * RF-27 (S-55), parte 1: datos de la cuenta de empleados. Las reglas de servicio (cuenta Efectivo en
 * préstamos, cuenta obligatoria en abonos por caja, no desactivar con saldo) van con el servicio.
 */

function empMovimientoDirecto(Empleado $empleado, array $datos = []): MovimientoEmpleado
{
    return MovimientoEmpleado::create([
        'empleado_id' => $empleado->id,
        'tipo' => 'prestamo',
        'fecha' => '2026-11-24',
        'valor' => 50000,
        'cuenta_pago_id' => cuentaEfectivo()->id,
        'usuario_id' => auth()->id(),
        ...$datos,
    ]);
}

it('RF-27: crea las tablas con los índices pedidos', function () {
    expect(Schema::hasTable('empleados'))->toBeTrue()
        ->and(Schema::hasTable('movimientos_empleado'))->toBeTrue()
        ->and(Schema::hasTable('movimiento_empleado_anulaciones'))->toBeTrue();

    $indices = collect(Schema::getIndexes('movimientos_empleado'))->pluck('columns')->map(fn ($c) => implode(',', $c));
    expect($indices)->toContain('empleado_id,fecha')->toContain('fecha,tipo');
    expect(Schema::hasColumn('empleados', 'user_id'))->toBeFalse();
});

it('RF-27: nombre y documento del empleado son únicos; documento opcional', function () {
    Empleado::create(['nombre' => 'Jhon']);
    Empleado::create(['nombre' => 'Lamus']);

    expect(fn () => Empleado::create(['nombre' => 'Jhon']))->toThrow(QueryException::class);

    Empleado::create(['nombre' => 'Juan José', 'documento' => '1001']);
    expect(fn () => Empleado::create(['nombre' => 'Otro', 'documento' => '1001']))->toThrow(QueryException::class);
});

it('RF-27 / RF-11: el empleado se audita al crearlo y al editarlo', function () {
    $admin = actuarComo(Rol::Admin);
    $empleado = Empleado::create(['nombre' => 'Jhonnier']);
    $empleado->update(['activo' => false]);

    $filas = Auditoria::where('tabla', 'empleados')->where('registro_id', $empleado->id)->orderBy('id')->get();
    expect($filas->pluck('campo')->all())->toBe(['*', 'activo'])
        ->and($filas[1]->valor_anterior)->toBe('1')
        ->and($filas[1]->valor_nuevo)->toBe('0')
        ->and($filas[1]->usuario_id)->toBe($admin->id);
});

it('RF-27 / regla 8: préstamos, abonos y anulaciones no se editan ni se borran', function () {
    actuarComo(Rol::Admin);
    $mov = empMovimientoDirecto(Empleado::create(['nombre' => 'Lili']));
    $anulacion = MovimientoEmpleadoAnulacion::create(['movimiento_id' => $mov->id, 'motivo' => 'Valor equivocado', 'usuario_id' => auth()->id()]);

    expect(fn () => $mov->update(['valor' => 1]))->toThrow(RegistroInmutableException::class)
        ->and(fn () => $mov->delete())->toThrow(RegistroInmutableException::class)
        ->and(fn () => $anulacion->update(['motivo' => 'x']))->toThrow(RegistroInmutableException::class)
        ->and(fn () => $anulacion->delete())->toThrow(RegistroInmutableException::class);
});

it('RF-27: un movimiento se anula una sola vez y deja de estar vigente', function () {
    actuarComo(Rol::Admin);
    $empleado = Empleado::create(['nombre' => 'Don Jhon']);
    $anulado = empMovimientoDirecto($empleado);
    $vigente = empMovimientoDirecto($empleado, ['valor' => 20000]);
    MovimientoEmpleadoAnulacion::create(['movimiento_id' => $anulado->id, 'motivo' => 'Duplicado', 'usuario_id' => auth()->id()]);

    expect(fn () => MovimientoEmpleadoAnulacion::create(['movimiento_id' => $anulado->id, 'motivo' => 'Otra vez', 'usuario_id' => auth()->id()]))
        ->toThrow(QueryException::class);
    expect($empleado->movimientos()->vigentes()->pluck('id')->all())->toBe([$vigente->id]);
});

it('RF-27: el valor es mayor que 0', function () {
    actuarComo(Rol::Admin);
    $empleado = Empleado::create(['nombre' => 'Practicante']);

    expect(fn () => empMovimientoDirecto($empleado, ['valor' => 0]))->toThrow(QueryException::class);
});

it('RF-27: la forma solo existe en abonos (caja o nómina) y es obligatoria en ellos', function () {
    actuarComo(Rol::Admin);
    $empleado = Empleado::create(['nombre' => 'Evelin']);

    expect(fn () => empMovimientoDirecto($empleado, ['forma' => 'caja']))->toThrow(QueryException::class);
    expect(fn () => empMovimientoDirecto($empleado, ['tipo' => 'abono']))->toThrow(QueryException::class);

    $nomina = empMovimientoDirecto($empleado, ['tipo' => 'abono', 'forma' => 'nomina', 'cuenta_pago_id' => null])->fresh();
    expect($nomina->tipo)->toBe(TipoMovimientoEmpleado::Abono)
        ->and($nomina->forma)->toBe(FormaAbonoEmpleado::Nomina)
        ->and($nomina->apertura)->toBeFalse()
        ->and($nomina->cuenta_pago_id)->toBeNull();
});

it('RF-27 (S-55): un empleado con movimientos no se puede borrar en la base (fk restrict)', function () {
    actuarComo(Rol::Admin);
    $empleado = Empleado::create(['nombre' => 'Dahianna']);
    empMovimientoDirecto($empleado);

    expect(fn () => $empleado->delete())->toThrow(QueryException::class);
});

it('RF-27 (S-55): la migración desactiva la categoría de caja PRESTAMO, con auditoría, sin tocar sus movimientos', function () {
    $admin = actuarComo(Rol::Admin);
    $categoria = CategoriaCaja::create(['nombre' => 'PRESTAMO', 'grupo' => 'vario']);
    $antiguo = MovimientoCaja::create([
        'fecha' => '2026-11-20', 'categoria_caja_id' => $categoria->id, 'grupo' => 'vario', 'concepto' => 'Préstamo a Jhon',
        'valor' => 30000, 'cuenta_pago_id' => cuentaEfectivo()->id, 'usuario_id' => $admin->id,
    ]);

    (require database_path('migrations/2026_11_27_000200_desactivar_categoria_caja_prestamo.php'))->up();

    expect($categoria->fresh()->activa)->toBeFalse()
        ->and($antiguo->fresh()->categoria_caja_id)->toBe($categoria->id);
    $fila = Auditoria::where('tabla', 'categorias_caja')->where('registro_id', $categoria->id)->where('campo', 'activa')->sole();
    expect($fila->valor_anterior)->toBe('1')->and($fila->valor_nuevo)->toBe('0');
});

it('RF-27 (S-55): el seeder de caja ya no crea la categoría PRESTAMO', function () {
    $this->seed(CajaSeeder::class);

    expect(CategoriaCaja::where('nombre', 'PRESTAMO')->exists())->toBeFalse();
});
