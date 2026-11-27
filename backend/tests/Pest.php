<?php

use App\Enums\FormaPago;
use App\Enums\MetodoCaptura;
use App\Enums\Rol;
use App\Models\AbonoCliente;
use App\Models\CategoriaCaja;
use App\Models\Cliente;
use App\Models\CuentaPago;
use App\Models\Ingreso;
use App\Models\PagoProveedor;
use App\Models\Proveedor;
use App\Models\Referencia;
use App\Models\Unidad;
use App\Models\User;
use App\Models\Venta;
use App\Services\CajaService;
use App\Services\CarteraService;
use App\Services\IngresoService;
use App\Services\VentaService;
use App\Support\Antiguedad;
use App\Support\RangoFechas;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
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

// S-46: bajar y subir una migración hace DDL (commit implícito en MySQL): fuera de una transacción.
pest()->extend(TestCase::class)
    ->use(DatabaseTruncation::class)
    ->in('Migraciones');

/** Crea un usuario del rol indicado y deja la sesión iniciada con él. */
function actuarComo(Rol $rol): User
{
    $usuario = User::factory()->rol($rol)->create();
    test()->actingAs($usuario);

    return $usuario;
}

/**
 * S-46: abre una venta VACÍA con el servicio para el usuario en sesión (POST /ventas ya no crea
 * ventas vacías: exige el primer escaneo). Sirve a las pruebas que escanean después. Devuelve el id.
 */
function abrirVenta(array $datos = []): int
{
    return app(VentaService::class)->abrir(
        auth()->user(),
        FormaPago::from($datos['forma_pago'] ?? FormaPago::Contado->value),
        $datos['cliente_id'] ?? null,
        $datos['factura_contapyme'] ?? null,
    )->id;
}

/** S-46: payload del primer escaneo de POST /ventas: la primera unidad disponible de la referencia. */
function escaneoInicial(string $codigoReferencia, string $metodo = 'escaneado'): array
{
    return ['codigo_barras' => unidadDe($codigoReferencia)->codigo_barras, 'metodo_captura' => $metodo];
}

/** RF-24 (S-48): la cuenta "Efectivo" que crea la migración. */
function cuentaEfectivo(): CuentaPago
{
    return CuentaPago::efectivo();
}

/** RF-24 (S-48): una cuenta de banco activa (la del seeder o una nueva). */
function cuentaBanco(): CuentaPago
{
    return CuentaPago::firstOrCreate(['nombre' => 'Bancolombia'], ['tipo' => 'banco']);
}

/**
 * RF-24 (S-48): pago exacto de una venta de contado (todo en una cuenta, Efectivo por defecto). Vacío
 * en crédito: la venta a crédito no recibe pagos al cerrar.
 */
function pagosExactos(Venta|int $venta, ?CuentaPago $cuenta = null): array
{
    $venta = $venta instanceof Venta ? $venta : Venta::findOrFail($venta);
    $total = app(VentaService::class)->totales($venta->id)->total;
    if ($venta->esCredito() || bccomp($total, '0', 2) <= 0) {
        return [];
    }

    return [['cuenta_pago_id' => ($cuenta ?? cuentaEfectivo())->id, 'valor' => $total]];
}

/** POST /ventas/{id}/cerrar con el pago exacto si es de contado (S-48). */
function cerrarVenta(int $ventaId, ?array $pagos = null)
{
    $venta = Venta::find($ventaId);
    $pagos ??= $venta && $venta->estaAbierta() ? pagosExactos($venta) : [];

    return test()->postJson("/api/ventas/{$ventaId}/cerrar", $pagos === [] ? [] : ['pagos' => $pagos]);
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
    $GLOBALS['__remision_t'] ??= 1000; // contador: random_int chocaba (mismo proveedor y número) y hacía inestable la suite
    $usuario ??= User::factory()->rol(Rol::Logistica)->create();
    $servicio = app(IngresoService::class);

    $ingreso = $servicio->crearBorrador([
        'proveedor_id' => $referencia->proveedor_id,
        'numero_documento' => 'REM-T'.(++$GLOBALS['__remision_t']),
        'fecha' => RangoFechas::hoy(),
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
    $ventaId = abrirVenta($venta);
    test()->postJson("/api/ventas/{$ventaId}/escanear", ['codigo_barras' => $codigo, 'metodo_captura' => 'escaneado'])->assertCreated();

    if ($cerrar) {
        cerrarVenta($ventaId)->assertOk();
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

    $ventaId = abrirVenta(['forma_pago' => 'credito', 'cliente_id' => $cliente->id]);
    $items = [];
    $unidades = Unidad::where('estado', 'disponible')->orderBy('id')->limit(count($precios))->pluck('codigo_barras');
    foreach ($precios as $i => $precio) {
        $itemId = test()->postJson("/api/ventas/{$ventaId}/escanear", ['codigo_barras' => $unidades[$i], 'metodo_captura' => 'escaneado'])->assertCreated()->json('venta_item_id');
        test()->patchJson("/api/ventas/{$ventaId}/items/{$itemId}", ['precio_unitario' => $precio])->assertOk();
        $items[] = $itemId;
    }
    if ($cerrar) {
        cerrarVenta($ventaId)->assertOk();
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

/**
 * RF-15: respuesta HTTP del abono (cesar/cartera). S-79: todo abono va a una venta; si el test no
 * envía `venta_id` (ni null a propósito), se dirige a la venta con saldo que vence primero.
 */
function abonar(Cliente $cliente, mixed $valor, array $extra = [])
{
    if (! array_key_exists('venta_id', $extra)) {
        $extra['venta_id'] = documentoConSaldo(app(CarteraService::class)->saldoCliente($cliente));
    }

    return test()->postJson("/api/clientes/{$cliente->id}/abonos", ['fecha' => now('America/Bogota')->toDateString(), 'valor' => $valor, 'cuenta_pago_id' => cuentaEfectivo()->id, ...$extra]);
}

/**
 * RF-17: respuesta HTTP del pago a proveedor. S-79: todo pago va a una factura; sin `ingreso_id` en
 * el test, se dirige a la que vence primero.
 */
function pagar(int $proveedorId, mixed $valor, array $extra = [])
{
    if (! array_key_exists('ingreso_id', $extra)) {
        $extra['ingreso_id'] = documentoConSaldo(app(CarteraService::class)->saldoProveedor(Proveedor::findOrFail($proveedorId)));
    }

    return test()->postJson("/api/proveedores/{$proveedorId}/pagos", ['valor' => $valor, 'cuenta_pago_id' => cuentaEfectivo()->id, ...$extra]);
}

/** Id del documento con saldo que vence primero (Antiguedad los ordena así), o null. */
function documentoConSaldo(Antiguedad $saldo): ?int
{
    return $saldo->documentos[0]['id'] ?? null;
}

/**
 * Abono antiguo sin venta (anterior a S-79), escrito directo como quedó en la base. La API ya no
 * los crea; sirve para probar que se siguen leyendo, sumando (FIFO de lectura) y anulando.
 */
function abonoHistorico(Cliente $cliente, int|string $valor, ?string $fecha = null, ?CuentaPago $cuenta = null): AbonoCliente
{
    $cuenta ??= cuentaEfectivo();

    return AbonoCliente::create([
        'cliente_id' => $cliente->id, 'venta_id' => null, 'fecha' => $fecha ?? RangoFechas::hoy(), 'valor' => $valor,
        'medio' => $cuenta->tipo->medio(), 'cuenta_pago_id' => $cuenta->id, 'usuario_id' => User::factory()->rol(Rol::Mostrador)->create()->id,
    ]);
}

/** Pago antiguo sin factura (anterior a S-79, FIFO de lectura), escrito directo como quedó en la base. */
function pagoHistorico(int $proveedorId, int|string $valor, ?string $fecha = null, ?CuentaPago $cuenta = null): PagoProveedor
{
    $cuenta ??= cuentaEfectivo();

    return PagoProveedor::create([
        'proveedor_id' => $proveedorId, 'ingreso_id' => null, 'fecha' => $fecha ?? RangoFechas::hoy(), 'valor' => $valor,
        'medio' => $cuenta->tipo->medio(), 'cuenta_pago_id' => $cuenta->id, 'usuario_id' => User::factory()->rol(Rol::Contable)->create()->id,
    ]);
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

    return $servicio->cerrar($venta, pagosExactos($venta));
}

/** RF-16: ingreso de una línea sin IVA (total = cantidad × costo); confirmado salvo que se pida borrador. */
function compra(Referencia $referencia, int $cantidad, int $costo, array $encabezado = [], bool $confirmar = true): Ingreso
{
    $usuario = User::factory()->rol(Rol::Logistica)->create();
    $servicio = app(IngresoService::class);

    $ingreso = $servicio->crearBorrador([
        'proveedor_id' => $referencia->proveedor_id,
        'numero_documento' => 'DOC-'.random_int(10000, 99999),
        'fecha' => RangoFechas::hoy(),
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
    $ventaId = abrirVenta($cliente ? ['forma_pago' => 'credito', 'cliente_id' => $cliente->id] : []);
    $items = [];
    foreach ($codigos as $i => $c) {
        $items[] = $itemId = test()->postJson("/api/ventas/{$ventaId}/escanear", ['codigo_barras' => $c, 'metodo_captura' => 'escaneado'])->assertCreated()->json('venta_item_id');
        if (isset($precios[$i])) {
            test()->patchJson("/api/ventas/{$ventaId}/items/{$itemId}", ['precio_unitario' => $precios[$i]])->assertOk();
        }
    }
    if ($cerrar) {
        cerrarVenta($ventaId)->assertOk();
    }
    if ($anterior) {
        test()->actingAs($anterior);
    }

    return [$ventaId, $items];
}

/* RF-26: ayudas de caja. */

function categoriaCaja(string $nombre): CategoriaCaja
{
    return CategoriaCaja::where('nombre', $nombre)->firstOrFail();
}

/** POST /caja/movimientos con la categoría por nombre (Efectivo por defecto). */
function movimientoCaja(string $categoria, $valor, ?CuentaPago $cuenta = null, array $extra = [])
{
    return test()->postJson('/api/caja/movimientos', [
        'categoria_caja_id' => categoriaCaja($categoria)->id,
        'concepto' => 'Concepto de prueba',
        'valor' => $valor,
        'cuenta_pago_id' => ($cuenta ?? cuentaEfectivo())->id,
        ...$extra,
    ]);
}

/**
 * RF-20: la línea que acaba de escanearse, dentro del ingreso completo que devuelve el POST.
 *
 * @return array<string, mixed>
 */
function lineaEscaneada(TestResponse $respuesta): array
{
    $id = $respuesta->json('item_escaneado_id');
    $linea = collect($respuesta->json('items'))->firstWhere('id', $id);
    expect($linea)->not->toBeNull();

    return $linea;
}

/** RF-25 (S-49): registra la apertura de caja de ese día (como un mostrador), sin tocar la sesión. */
function abrirCaja(string $fecha, int|string $valor = 0): void
{
    // S-84: con motivo, por si difiere de la apertura que arrastra el último cierre.
    app(CajaService::class)->fijarApertura($fecha, (string) $valor, 'Apertura de prueba', User::factory()->rol(Rol::Mostrador)->create());
}
