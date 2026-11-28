<?php

use App\Http\Controllers\Api\AbonoController;
use App\Http\Controllers\Api\AjusteController;
use App\Http\Controllers\Api\AuditoriaController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BusquedaController;
use App\Http\Controllers\Api\CajaController;
use App\Http\Controllers\Api\CarteraController;
use App\Http\Controllers\Api\CategoriaController;
use App\Http\Controllers\Api\ClienteController;
use App\Http\Controllers\Api\CodigoUniversalController;
use App\Http\Controllers\Api\ConfiguracionController;
use App\Http\Controllers\Api\ConsultaContableController;
use App\Http\Controllers\Api\CostoController;
use App\Http\Controllers\Api\CuentaController;
use App\Http\Controllers\Api\CuentaPagoController;
use App\Http\Controllers\Api\DevolucionController;
use App\Http\Controllers\Api\EmpleadoController;
use App\Http\Controllers\Api\EtiquetaController;
use App\Http\Controllers\Api\IngresoController;
use App\Http\Controllers\Api\MovimientoCajaController;
use App\Http\Controllers\Api\MovimientoInventarioController;
use App\Http\Controllers\Api\PagoController;
use App\Http\Controllers\Api\PanelController;
use App\Http\Controllers\Api\PropietarioController;
use App\Http\Controllers\Api\ProveedorController;
use App\Http\Controllers\Api\RecaudoDocumentoController;
use App\Http\Controllers\Api\ReferenciaController;
use App\Http\Controllers\Api\ReporteController;
use App\Http\Controllers\Api\UsuarioController;
use App\Http\Controllers\Api\VentaController;
use Illuminate\Support\Facades\Route;

/*
 * Regla 14: todo endpoint protegido por rol. Middleware "rol": el admin pasa siempre; "solo_rol"
 * (S-43, S-44): sin el pase del admin (vender es solo del mostrador; operar ajustes y devoluciones,
 * solo de logística). El admin consulta todo y tiene su panel de gestión (RF-23, S-45).
 * No hay rutas DELETE para ventas, venta_items, reversos, movimientos, abonos, pagos, auditoría ni caja
 * (cuentas, categorías, movimientos, cierres) (RF-07, RF-11, RF-15, RF-17, RF-24 a RF-26).
 */

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login')->name('login');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me'])->middleware('rol:admin,logistica,mostrador,contable');
    // S-70: preferencias de pantalla; el mostrador no las tiene (403).
    Route::patch('/me/preferencias', [AuthController::class, 'preferencias'])->middleware(['rol:admin,logistica,contable', 'throttle:30,1']);

    // S-25: aviso de que un código ya existe con otro proveedor. Antes de /referencias/{referencia}.
    Route::get('/referencias/coincidencias', [ReferenciaController::class, 'coincidencias'])->middleware('rol:admin,logistica');

    // Listas de proveedores y clientes: también las usa el módulo contable (RF-29).
    Route::middleware('rol:admin,logistica,mostrador,contable')->group(function () {
        Route::get('/proveedores', [ProveedorController::class, 'index']);
        Route::get('/clientes', [ClienteController::class, 'index'])->middleware('medir:800'); // RF-13; S-72 búsqueda rápida
    });

    // Lectura común a los tres módulos de operación.
    Route::middleware('rol:admin,logistica,mostrador')->group(function () {
        Route::get('/categorias', [CategoriaController::class, 'index']);
        Route::get('/referencias', [ReferenciaController::class, 'index']);
        // S-82: Inventario con los universales integrados (antes /referencias en la pantalla).
        Route::get('/inventario', [ReferenciaController::class, 'inventario'])->middleware('medir:800');
        Route::get('/referencias/{referencia}', [ReferenciaController::class, 'show']);
        Route::get('/codigos-universales', [CodigoUniversalController::class, 'index']);
        Route::get('/codigos-universales/{codigoUniversal}', [CodigoUniversalController::class, 'show']); // RF-02
        Route::get('/buscar', BusquedaController::class)->middleware('medir:500')->name('buscar'); // RF-12
    });

    // RF-20: logística crea la referencia que no existe al escanear un ingreso.
    Route::post('/referencias', [ReferenciaController::class, 'store'])->middleware('rol:admin,logistica');
    // S-26: logística también edita, desactiva y reactiva referencias. No hay DELETE (tienen historial).
    // RF-08 / RF-22 / S-28: agotados y stock mínimo son trabajo de reposición de logística y
    // administración; mostrador recibe 403 (ve la existencia en el listado de productos).
    // S-71: historial de costos de compra (admin y logística; permiso en el enum Rol).
    Route::get('/costos', [CostoController::class, 'index'])->middleware(['can:ver-historial-costos', 'medir:800']);
    Route::middleware('rol:admin,logistica')->group(function () {
        Route::get('/reportes/agotados', [ReporteController::class, 'agotados'])->middleware('medir:500'); // RF-08 / S-47
        Route::get('/reportes/bajo-minimo', [ReporteController::class, 'bajoMinimo'])->middleware('medir:500'); // RF-22
    });

    Route::middleware('rol:admin,logistica')->group(function () {
        // S-59: logística crea y edita códigos universales (borrar sigue siendo del admin).
        Route::post('/codigos-universales', [CodigoUniversalController::class, 'store']);
        Route::match(['put', 'patch'], '/codigos-universales/{codigoUniversal}', [CodigoUniversalController::class, 'update']);
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
        // S-59: crear y editar universales también es de logística (grupo de abajo); borrar, solo admin.
        Route::delete('/codigos-universales/{codigoUniversal}', [CodigoUniversalController::class, 'destroy']);

        // RF-28: correcciones de ingresos confirmados, solo consulta. Antes de /ingresos/{ingreso}.
        Route::get('/ingresos/correcciones', [IngresoController::class, 'correcciones'])->name('ingresos.correcciones.lista');

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
        // RF-25 (S-51): meta de venta diaria.
        Route::get('/configuracion/meta-venta', [ConfiguracionController::class, 'metaVenta']);
        Route::put('/configuracion/meta-venta', [ConfiguracionController::class, 'fijarMetaVenta']);

        // RF-24 (S-48): cuentas de pago; solo el admin las crea, edita y desactiva (sin DELETE).
        Route::post('/cuentas-pago', [CuentaPagoController::class, 'store']);
        Route::match(['put', 'patch'], '/cuentas-pago/{cuenta}', [CuentaPagoController::class, 'update']);
        // S-88: dueños de billetera. El listado está con GET /cuentas-pago (mostrador y contable).
        Route::post('/propietarios', [PropietarioController::class, 'store']);
        Route::match(['put', 'patch'], '/propietarios/{propietario}', [PropietarioController::class, 'update']);

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

        Route::post('/abonos/{abono}/anulaciones', [AbonoController::class, 'anular']); // RF-15

        // RF-17 / S-27: anular un pago sigue siendo solo del admin.
        Route::post('/pagos/{pago}/anulaciones', [PagoController::class, 'anular']);
    });

    // Módulo contable (RF-29): pagos a proveedores (RF-17), informes (RF-18) y vencimientos (RF-30),
    // solo en pantalla. El admin también entra.
    Route::middleware('rol:contable')->group(function () {
        Route::get('/proveedores/{proveedor}/saldo', [PagoController::class, 'saldo']);
        Route::post('/proveedores/{proveedor}/pagos', [PagoController::class, 'store']);

        Route::middleware('medir:500')->group(function () {
            Route::get('/vencimientos', [ReporteController::class, 'vencimientos']);
            Route::get('/reportes/compras', [ReporteController::class, 'compras']);
            Route::get('/reportes/ventas-credito', [ReporteController::class, 'ventasCredito']);
            Route::get('/reportes/cartera-clientes', [ReporteController::class, 'carteraClientes']);
            Route::get('/reportes/cuentas-por-pagar', [ReporteController::class, 'cuentasPorPagar']);
            Route::get('/reportes/remisiones-pendientes', [ReporteController::class, 'remisionesPendientes']);
            Route::get('/reportes/diferencias-remision', [ReporteController::class, 'diferenciasRemision']);
            // S-89: el del cliente tenía la misma URI que el de Cartera (admin y mostrador), que la tapaba
            // y dejaba al contable en 403; va bajo /reportes con el formato del de proveedores.
            Route::get('/reportes/clientes/{cliente}/estado-cuenta', [ReporteController::class, 'estadoCuentaCliente']);
            Route::get('/proveedores/{proveedor}/estado-cuenta', [ReporteController::class, 'estadoCuentaProveedor']);
        });

        // S-89: consultas de solo lectura (historial de pagos y abonos; saldo del cliente).
        Route::middleware('medir:800')->group(function () {
            Route::get('/proveedores/{proveedor}/pagos', [ConsultaContableController::class, 'pagosProveedor']);
            Route::get('/clientes/{cliente}/abonos', [ConsultaContableController::class, 'abonosCliente']);
            Route::get('/clientes/{cliente}/saldo', [ConsultaContableController::class, 'saldoCliente']);
            Route::get('/reportes/cartera-recuperada', [ConsultaContableController::class, 'carteraRecuperada']); // S-97
        });
    });

    // Módulo de logística: ingreso de remisiones y facturas a mano (RF-05, RF-04, RF-09, RF-10) y etiquetas (RF-03).
    Route::middleware('rol:logistica')->group(function () {
        Route::get('/ingresos', [IngresoController::class, 'index'])->middleware('medir:800'); // S-91
        Route::get('/ingresos/meses', [IngresoController::class, 'meses'])->middleware('medir:800'); // S-91, antes de /ingresos/{ingreso}
        Route::post('/ingresos', [IngresoController::class, 'store']);
        Route::get('/ingresos/{ingreso}', [IngresoController::class, 'show']);
        Route::match(['put', 'patch'], '/ingresos/{ingreso}', [IngresoController::class, 'update']);
        Route::get('/ingresos/{ingreso}/unidades', [IngresoController::class, 'unidades']);
        Route::post('/ingresos/{ingreso}/confirmar', [IngresoController::class, 'confirmar']);
        Route::post('/ingresos/{ingreso}/escanear', [IngresoController::class, 'escanear'])->middleware('medir:500')->name('ingresos.escanear'); // RF-20
        // RF-28 (S-58): única vía para corregir un ingreso confirmado, con vista previa sin escritura.
        Route::post('/ingresos/{ingreso}/correcciones/vista-previa', [IngresoController::class, 'vistaPreviaCorreccion'])->name('ingresos.correcciones.vista-previa');
        Route::post('/ingresos/{ingreso}/correcciones', [IngresoController::class, 'corregir'])->name('ingresos.correcciones');

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
        // S-87: historial de movimientos por producto (solo lectura; el admin solo consulta).
        Route::get('/movimientos-inventario', [MovimientoInventarioController::class, 'index'])->middleware('medir:500')->name('movimientos-inventario.index');

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
            // S-46: la venta nace con su primer escaneo (exige el payload de la lectura).
            Route::post('/ventas', [VentaController::class, 'store'])->middleware('medir:300')->name('ventas.abrir');
            // RF-14 cambiado (S-73): venta a crédito de solo valor (sin ítems, ya cerrada).
            Route::post('/ventas/credito', [VentaController::class, 'registrarCredito'])->name('ventas.credito');
            Route::post('/ventas/{venta}/escanear', [VentaController::class, 'escanear'])->middleware('medir:300')->name('ventas.escanear');
            Route::post('/ventas/{venta}/cerrar', [VentaController::class, 'cerrar']);
            // RF-14 / S-18: solo el precio de un ítem de una venta a crédito abierta.
            // S-42: precio de varios ítems (un grupo del modal de cierre). Antes de {item} para no confundirse.
            Route::patch('/ventas/{venta}/items/precio', [VentaController::class, 'cambiarPrecioLote']);
            Route::patch('/ventas/{venta}/items/{item}', [VentaController::class, 'cambiarPrecio'])->scopeBindings();
        });
    });

    // RF-24 (S-48): cuentas de pago activas. Mostrador (ventas, abonos), admin y contable (pagos a
    // proveedores, RF-29). Logística 403.
    Route::get('/cuentas-pago', [CuentaPagoController::class, 'index'])->middleware('rol:mostrador,contable');
    Route::get('/propietarios', [PropietarioController::class, 'index'])->middleware('rol:mostrador,contable'); // S-88

    // RF-24 a RF-26 (S-48 a S-54): cuentas de pago y caja diaria. Mostrador y admin; logística 403.
    // Registrar movimientos, anularlos y cerrar el día: solo mostrador. El admin consulta, reabre un
    // cierre (su única escritura en caja), ve el cuadre mensual y gestiona las categorías.
    Route::middleware('rol:mostrador')->group(function () {

        Route::middleware('medir:800')->prefix('caja')->group(function () {
            Route::get('/movimientos', [MovimientoCajaController::class, 'index'])->name('caja.movimientos');
            Route::get('/categorias', [MovimientoCajaController::class, 'categorias']);
            Route::get('/dias/{fecha}', [CajaController::class, 'dia'])->name('caja.dia');

            Route::middleware('solo_rol:mostrador')->group(function () {
                Route::post('/movimientos', [MovimientoCajaController::class, 'store']);
                Route::post('/movimientos/{movimiento}/anulaciones', [MovimientoCajaController::class, 'anular']);
                // S-76: recaudo de documentos de Contapyme (no son ventas a crédito del sistema).
                Route::post('/dias/{fecha}/recaudos', [RecaudoDocumentoController::class, 'store'])->where('fecha', '\d{4}-\d{2}-\d{2}');
                Route::post('/recaudos/{recaudo}/anular', [RecaudoDocumentoController::class, 'anular']);
                Route::post('/cierres', [CajaController::class, 'cerrar']);
                // S-49: apertura diaria de la caja (crear o corregir mientras el día no esté cerrado).
                Route::put('/dias/{fecha}/apertura', [CajaController::class, 'fijarApertura'])->where('fecha', '\d{4}-\d{2}-\d{2}');
            });

            Route::middleware('solo_rol:admin')->group(function () {
                Route::post('/cierres/{cierre}/reaperturas', [CajaController::class, 'reabrir']);
                Route::get('/meses/{mes}', [CajaController::class, 'mes'])->name('caja.mes');
                Route::post('/categorias', [MovimientoCajaController::class, 'crearCategoria']);
                Route::match(['put', 'patch'], '/categorias/{categoria}', [MovimientoCajaController::class, 'actualizarCategoria']);
            });
        });
    });

    // S-76: consulta de recaudos de documentos para la contadora (admin pasa). Solo en pantalla.
    Route::get('/recaudos-documento', [RecaudoDocumentoController::class, 'index'])->middleware(['rol:contable', 'medir:800']);

    // RF-27 (S-55 a S-57): cuenta de empleados, independiente de la cartera de clientes. Logística 403.
    // Mostrador: elige empleado (sin saldos) y registra préstamos y abonos. Admin: crea y edita
    // empleados (apertura al crear), anula movimientos y consulta saldos. Sin rutas DELETE.
    Route::middleware(['rol:mostrador', 'medir:800'])->prefix('empleados')->group(function () {
        Route::get('/', [EmpleadoController::class, 'index'])->name('empleados.index');

        Route::middleware('solo_rol:mostrador')->group(function () {
            Route::post('/{empleado}/prestamos', [EmpleadoController::class, 'prestamo'])->name('empleados.prestamos');
            Route::post('/{empleado}/abonos', [EmpleadoController::class, 'abono'])->name('empleados.abonos');
        });

        Route::middleware('solo_rol:admin')->group(function () {
            Route::get('/resumen', [EmpleadoController::class, 'resumen'])->name('empleados.resumen');
            Route::post('/', [EmpleadoController::class, 'store'])->name('empleados.crear');
            Route::match(['put', 'patch'], '/{empleado}', [EmpleadoController::class, 'update'])->name('empleados.editar');
            Route::get('/{empleado}/estado-cuenta', [EmpleadoController::class, 'estadoCuenta'])->name('empleados.estado-cuenta');
            Route::post('/movimientos/{movimiento}/anulaciones', [EmpleadoController::class, 'anular'])->name('empleados.anular');
        });
    });
});
