<?php

use App\Http\Controllers\Api\AbonoController;
use App\Http\Controllers\Api\AjusteController;
use App\Http\Controllers\Api\AuditoriaController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BusquedaController;
use App\Http\Controllers\Api\CarteraController;
use App\Http\Controllers\Api\CategoriaController;
use App\Http\Controllers\Api\ClienteController;
use App\Http\Controllers\Api\CodigoUniversalController;
use App\Http\Controllers\Api\ConfiguracionController;
use App\Http\Controllers\Api\CuentaController;
use App\Http\Controllers\Api\DevolucionController;
use App\Http\Controllers\Api\EtiquetaController;
use App\Http\Controllers\Api\IngresoController;
use App\Http\Controllers\Api\PagoController;
use App\Http\Controllers\Api\PanelController;
use App\Http\Controllers\Api\ProveedorController;
use App\Http\Controllers\Api\ReferenciaController;
use App\Http\Controllers\Api\ReporteController;
use App\Http\Controllers\Api\UsuarioController;
use App\Http\Controllers\Api\VentaController;
use Illuminate\Support\Facades\Route;

/*
 * Regla 12: todo endpoint protegido por rol (middleware "rol"; el admin pasa siempre).
 * No hay rutas DELETE para ventas, venta_items, reversos, movimientos, abonos, pagos ni auditoría (RF-07, RF-11, RF-15, RF-17).
 */

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login')->name('login');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me'])->middleware('rol:admin,logistica,mostrador');

    // S-25: aviso de que un código ya existe con otro proveedor. Antes de /referencias/{referencia}.
    Route::get('/referencias/coincidencias', [ReferenciaController::class, 'coincidencias'])->middleware('rol:admin,logistica');

    // Lectura común a los tres módulos.
    Route::middleware('rol:admin,logistica,mostrador')->group(function () {
        Route::get('/categorias', [CategoriaController::class, 'index']);
        Route::get('/proveedores', [ProveedorController::class, 'index']);
        Route::get('/referencias', [ReferenciaController::class, 'index']);
        Route::get('/referencias/{referencia}', [ReferenciaController::class, 'show']);
        Route::get('/codigos-universales', [CodigoUniversalController::class, 'index']);
        Route::get('/codigos-universales/{codigoUniversal}', [CodigoUniversalController::class, 'show']); // RF-02
        Route::get('/buscar', BusquedaController::class)->middleware('medir:500')->name('buscar'); // RF-12
        Route::get('/clientes', [ClienteController::class, 'index']); // RF-13
    });

    // RF-20: logística crea la referencia que no existe al escanear un ingreso.
    Route::post('/referencias', [ReferenciaController::class, 'store'])->middleware('rol:admin,logistica');
    // S-26: logística también edita, desactiva y reactiva referencias. No hay DELETE (tienen historial).
    // RF-08 / RF-22 / S-28: agotados y stock mínimo son trabajo de reposición de logística y
    // administración; mostrador recibe 403 (ve la existencia en el listado de productos).
    Route::middleware('rol:admin,logistica')->group(function () {
        Route::get('/reportes/agotados', [ReporteController::class, 'agotados']); // RF-08
        Route::get('/reportes/bajo-minimo', [ReporteController::class, 'bajoMinimo'])->middleware('medir:500'); // RF-22
    });

    Route::middleware('rol:admin,logistica')->group(function () {
        Route::match(['put', 'patch'], '/referencias/{referencia}', [ReferenciaController::class, 'update']);
        Route::post('/referencias/{referencia}/desactivar', [ReferenciaController::class, 'desactivar']);
        Route::post('/referencias/{referencia}/reactivar', [ReferenciaController::class, 'reactivar']);
    });
    // S-24: logística también crea proveedores y categorías; editarlos y borrarlos es del admin.
    Route::post('/proveedores', [ProveedorController::class, 'store'])->middleware('rol:admin,logistica');
    Route::post('/categorias', [CategoriaController::class, 'store'])->middleware('rol:admin,logistica');

    // RF-13: el mostrador crea clientes al vender a crédito; editar es solo del admin.
    Route::post('/clientes', [ClienteController::class, 'store'])->middleware('rol:admin,mostrador');

    // Módulo administrador: catálogo (RF-01), usuarios y auditoría (RF-11).
    Route::middleware('rol:admin')->group(function () {
        Route::apiResource('categorias', CategoriaController::class)->except(['index', 'store']);
        Route::apiResource('proveedores', ProveedorController::class)
            ->parameters(['proveedores' => 'proveedor'])->except(['index', 'store']);
        Route::apiResource('codigos-universales', CodigoUniversalController::class)
            ->parameters(['codigos-universales' => 'codigoUniversal'])->except(['index', 'show']);

        Route::get('/usuarios', [UsuarioController::class, 'index']);
        Route::post('/usuarios', [UsuarioController::class, 'store']);
        Route::match(['put', 'patch'], '/usuarios/{usuario}', [UsuarioController::class, 'update']);
        Route::patch('/cuenta', [CuentaController::class, 'update']);

        Route::get('/auditoria', [AuditoriaController::class, 'index']);

        // RF-22: mínimo general del almacén (auditado).
        Route::get('/configuracion/stock-minimo', [ConfiguracionController::class, 'stockMinimo']);
        Route::put('/configuracion/stock-minimo', [ConfiguracionController::class, 'fijarStockMinimo']);
        // S-45 e: días sin movimiento del panel (120 por defecto, 30 a 730).
        Route::get('/configuracion/dias-sin-movimiento', [ConfiguracionController::class, 'diasSinMovimiento']);
        Route::put('/configuracion/dias-sin-movimiento', [ConfiguracionController::class, 'fijarDiasSinMovimiento']);

        // RF-23 / S-45: panel de gestión, solo admin y solo en pantalla (regla 16). Objetivo p95 < 800 ms.
        Route::middleware('medir:800')->prefix('panel')->group(function () {
            Route::get('/resumen', [PanelController::class, 'resumen'])->name('panel.resumen');
            Route::get('/ventas-por-dia', [PanelController::class, 'ventasPorDia'])->name('panel.ventas-por-dia');
            Route::get('/mas-vendidos', [PanelController::class, 'masVendidos'])->name('panel.mas-vendidos');
            Route::get('/reabastecer', [PanelController::class, 'reabastecer'])->name('panel.reabastecer');
            Route::get('/sin-movimiento', [PanelController::class, 'sinMovimiento'])->name('panel.sin-movimiento');
            Route::get('/antiguedad-stock', [PanelController::class, 'antiguedadStock'])->name('panel.antiguedad-stock');
            Route::get('/rotacion', [PanelController::class, 'rotacion'])->name('panel.rotacion');
            Route::get('/control', [PanelController::class, 'control'])->name('panel.control');
        });

        Route::match(['put', 'patch'], '/clientes/{cliente}', [ClienteController::class, 'update']); // RF-13

        // RF-17 / S-14: pagos a proveedores, solo admin.
        Route::get('/proveedores/{proveedor}/saldo', [PagoController::class, 'saldo']);
        Route::post('/proveedores/{proveedor}/pagos', [PagoController::class, 'store']);
        Route::post('/pagos/{pago}/anulaciones', [PagoController::class, 'anular']);

        // RF-18 / S-14: informes en pantalla, solo admin. El estado de cuenta del cliente está en la
        // cartera (S-30: admin y mostrador) y usa el mismo cálculo.
        Route::middleware('medir:500')->group(function () {
            Route::get('/reportes/compras', [ReporteController::class, 'compras']);
            Route::get('/reportes/ventas-credito', [ReporteController::class, 'ventasCredito']);
            Route::get('/reportes/cartera-clientes', [ReporteController::class, 'carteraClientes']);
            Route::get('/reportes/cuentas-por-pagar', [ReporteController::class, 'cuentasPorPagar']);
            Route::get('/reportes/remisiones-pendientes', [ReporteController::class, 'remisionesPendientes']);
            Route::get('/reportes/diferencias-remision', [ReporteController::class, 'diferenciasRemision']);
            Route::get('/proveedores/{proveedor}/estado-cuenta', [ReporteController::class, 'estadoCuentaProveedor']);
        });
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

        // RF-19: vínculo remisión–factura, solo con la factura en borrador.
        Route::get('/ingresos/{ingreso}/remisiones-disponibles', [IngresoController::class, 'remisionesDisponibles']);
        Route::post('/ingresos/{ingreso}/remisiones', [IngresoController::class, 'ligarRemisiones']);
        Route::delete('/ingresos/{ingreso}/remisiones/{remision}', [IngresoController::class, 'desligarRemision']);
        Route::post('/ingresos/{ingreso}/prellenar', [IngresoController::class, 'prellenar']);

        Route::scopeBindings()->group(function () {
            Route::post('/ingresos/{ingreso}/items', [IngresoController::class, 'agregarItem']);
            Route::match(['put', 'patch'], '/ingresos/{ingreso}/items/{item}', [IngresoController::class, 'actualizarItem']);
            // Solo en borrador, antes de que existan unidades.
            Route::delete('/ingresos/{ingreso}/items/{item}', [IngresoController::class, 'quitarItem']);
        });

        Route::get('/etiquetas/tamanos', [EtiquetaController::class, 'tamanos']);
        Route::post('/etiquetas', [EtiquetaController::class, 'generar']);
    });

    // RF-21 (S-27): salidas y reintegros de inventario, y devoluciones de clientes por código de
    // unidad (RF-07). Mostrador 403. Sin DELETE: los ajustes son inmutables.
    // S-44: el historial lo consultan logística y admin; operar (y sus búsquedas auxiliares) es solo
    // de logística: el admin recibe 403. Para que el admin vuelva a operar, `solo_rol:logistica` → `rol:logistica`.
    Route::middleware('rol:admin,logistica')->group(function () {
        Route::get('/ajustes', [AjusteController::class, 'index']);

        Route::middleware('solo_rol:logistica')->group(function () {
            Route::get('/ajustes/unidad', [AjusteController::class, 'unidad'])->middleware('medir:500')->name('ajustes.unidad');
            Route::post('/ajustes/salidas', [AjusteController::class, 'salidas'])->name('ajustes.salidas');
            Route::post('/ajustes/reintegros', [AjusteController::class, 'reintegros'])->name('ajustes.reintegros');
            // S-39: entradas por ajuste de inventario físico y búsqueda de la referencia (sin DELETE ni PUT).
            Route::post('/ajustes/entradas', [AjusteController::class, 'entradas'])->name('ajustes.entradas');
            Route::get('/ajustes/referencia', [AjusteController::class, 'referencia'])->middleware('medir:500')->name('ajustes.referencia');

            Route::get('/devoluciones/consulta', [DevolucionController::class, 'consulta'])->middleware('medir:500')->name('devoluciones.consulta');
            Route::post('/devoluciones', [DevolucionController::class, 'store']);
        });
    });

    // RF-15 / RF-18 (S-30): cartera de clientes. Admin y mostrador; logística 403. Los abonos no
    // tienen rutas para editar ni borrar; anularlos es solo del admin.
    Route::middleware('rol:admin,mostrador')->group(function () {
        Route::get('/cartera', [CarteraController::class, 'index'])->middleware('medir:500')->name('cartera');
        Route::post('/clientes/{cliente}/abonos', [AbonoController::class, 'store']);
        Route::get('/clientes/{cliente}/cartera', [CarteraController::class, 'show']); // RF-18
        Route::get('/clientes/{cliente}/estado-cuenta', [CarteraController::class, 'estadoCuenta']); // RF-18
    });
    Route::post('/abonos/{abono}/anulaciones', [AbonoController::class, 'anular'])->middleware('rol:admin');

    // Módulo de mostrador: venta = descargue (RF-06, RF-12) y reversos (RF-07).
    // S-43: consultar y reversar con motivo (regla 8): mostrador y admin.
    Route::middleware('rol:mostrador')->group(function () {
        Route::get('/ventas', [VentaController::class, 'index']);
        Route::get('/ventas/{venta}', [VentaController::class, 'show']);
        Route::post('/ventas/{venta}/reversos', [VentaController::class, 'reversar']);

        // S-43: solo el mostrador vende (abrir, escanear, cerrar, precios); el admin recibe 403.
        Route::middleware('solo_rol:mostrador')->group(function () {
            Route::post('/ventas', [VentaController::class, 'store']);
            Route::post('/ventas/{venta}/escanear', [VentaController::class, 'escanear'])->middleware('medir:300')->name('ventas.escanear');
            Route::post('/ventas/{venta}/cerrar', [VentaController::class, 'cerrar']);
            // RF-14 / S-18: solo el precio de un ítem de una venta a crédito abierta.
            // S-42: precio de varios ítems (un grupo del modal de cierre). Antes de {item} para no confundirse.
            Route::patch('/ventas/{venta}/items/precio', [VentaController::class, 'cambiarPrecioLote']);
            Route::patch('/ventas/{venta}/items/{item}', [VentaController::class, 'cambiarPrecio'])->scopeBindings();
        });
    });
});
