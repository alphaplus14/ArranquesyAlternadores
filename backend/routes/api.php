<?php

use App\Http\Controllers\Api\AuditoriaController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BusquedaController;
use App\Http\Controllers\Api\CategoriaController;
use App\Http\Controllers\Api\ClienteController;
use App\Http\Controllers\Api\CodigoUniversalController;
use App\Http\Controllers\Api\CuentaController;
use App\Http\Controllers\Api\EtiquetaController;
use App\Http\Controllers\Api\IngresoController;
use App\Http\Controllers\Api\MarcaController;
use App\Http\Controllers\Api\ProveedorController;
use App\Http\Controllers\Api\ReferenciaController;
use App\Http\Controllers\Api\ReporteController;
use App\Http\Controllers\Api\UsuarioController;
use App\Http\Controllers\Api\VentaController;
use Illuminate\Support\Facades\Route;

/*
 * Regla 12: todo endpoint protegido por rol (middleware "rol"; el admin pasa siempre).
 * No hay rutas DELETE para ventas, venta_items, reversos, movimientos ni auditoría (RF-07, RF-11).
 */

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login')->name('login');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me'])->middleware('rol:admin,logistica,mostrador');

    // Lectura común a los tres módulos.
    Route::middleware('rol:admin,logistica,mostrador')->group(function () {
        Route::get('/categorias', [CategoriaController::class, 'index']);
        Route::get('/marcas', [MarcaController::class, 'index']);
        Route::get('/proveedores', [ProveedorController::class, 'index']);
        Route::get('/referencias', [ReferenciaController::class, 'index']);
        Route::get('/referencias/{referencia}', [ReferenciaController::class, 'show']);
        Route::get('/codigos-universales', [CodigoUniversalController::class, 'index']);
        Route::get('/codigos-universales/{codigoUniversal}', [CodigoUniversalController::class, 'show']); // RF-02
        Route::get('/buscar', BusquedaController::class)->middleware('medir:500')->name('buscar'); // RF-12
        Route::get('/reportes/agotados', [ReporteController::class, 'agotados']); // RF-08
        Route::get('/clientes', [ClienteController::class, 'index']); // RF-13
    });

    // RF-20: logística crea la referencia que no existe al escanear un ingreso; editar y desactivar son del admin.
    Route::post('/referencias', [ReferenciaController::class, 'store'])->middleware('rol:admin,logistica');

    // RF-13: el mostrador crea clientes al vender a crédito; editar es solo del admin.
    Route::post('/clientes', [ClienteController::class, 'store'])->middleware('rol:admin,mostrador');

    // Módulo administrador: catálogo (RF-01), usuarios y auditoría (RF-11).
    Route::middleware('rol:admin')->group(function () {
        Route::apiResource('categorias', CategoriaController::class)->except(['index']);
        Route::apiResource('marcas', MarcaController::class)->except(['index']);
        Route::apiResource('proveedores', ProveedorController::class)
            ->parameters(['proveedores' => 'proveedor'])->except(['index']);
        Route::apiResource('codigos-universales', CodigoUniversalController::class)
            ->parameters(['codigos-universales' => 'codigoUniversal'])->except(['index', 'show']);
        Route::apiResource('referencias', ReferenciaController::class)->except(['index', 'show', 'store']);

        Route::get('/usuarios', [UsuarioController::class, 'index']);
        Route::post('/usuarios', [UsuarioController::class, 'store']);
        Route::match(['put', 'patch'], '/usuarios/{usuario}', [UsuarioController::class, 'update']);
        Route::patch('/cuenta', [CuentaController::class, 'update']);

        Route::get('/auditoria', [AuditoriaController::class, 'index']);

        Route::match(['put', 'patch'], '/clientes/{cliente}', [ClienteController::class, 'update']); // RF-13
    });

    // Módulo de logística: ingreso de remisiones y facturas a mano (RF-05, RF-04, RF-09, RF-10) y etiquetas (RF-03).
    Route::middleware('rol:logistica')->group(function () {
        Route::get('/ingresos', [IngresoController::class, 'index']);
        Route::post('/ingresos', [IngresoController::class, 'store']);
        Route::get('/ingresos/{ingreso}', [IngresoController::class, 'show']);
        Route::match(['put', 'patch'], '/ingresos/{ingreso}', [IngresoController::class, 'update']);
        Route::get('/ingresos/{ingreso}/unidades', [IngresoController::class, 'unidades']);
        Route::post('/ingresos/{ingreso}/confirmar', [IngresoController::class, 'confirmar']);
        Route::post('/ingresos/{ingreso}/escanear', [IngresoController::class, 'escanear'])->middleware('medir:500')->name('ingresos.escanear'); // RF-20

        Route::scopeBindings()->group(function () {
            Route::post('/ingresos/{ingreso}/items', [IngresoController::class, 'agregarItem']);
            Route::match(['put', 'patch'], '/ingresos/{ingreso}/items/{item}', [IngresoController::class, 'actualizarItem']);
            // Solo en borrador, antes de que existan unidades.
            Route::delete('/ingresos/{ingreso}/items/{item}', [IngresoController::class, 'quitarItem']);
        });

        Route::get('/etiquetas/tamanos', [EtiquetaController::class, 'tamanos']);
        Route::post('/etiquetas', [EtiquetaController::class, 'generar']);
    });

    // Módulo de mostrador: venta = descargue (RF-06, RF-12) y reversos (RF-07).
    Route::middleware('rol:mostrador')->group(function () {
        Route::get('/ventas', [VentaController::class, 'index']);
        Route::post('/ventas', [VentaController::class, 'store']);
        Route::get('/ventas/{venta}', [VentaController::class, 'show']);
        Route::post('/ventas/{venta}/escanear', [VentaController::class, 'escanear'])->middleware('medir:300')->name('ventas.escanear');
        Route::post('/ventas/{venta}/cerrar', [VentaController::class, 'cerrar']);
        Route::post('/ventas/{venta}/reversos', [VentaController::class, 'reversar']);
        // RF-14 / S-18: solo el precio de un ítem de una venta a crédito abierta.
        Route::patch('/ventas/{venta}/items/{item}', [VentaController::class, 'cambiarPrecio'])->scopeBindings();
    });
});
