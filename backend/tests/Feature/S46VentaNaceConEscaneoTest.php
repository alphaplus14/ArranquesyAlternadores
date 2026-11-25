<?php

use App\Enums\EstadoVenta;
use App\Enums\FormaPago;
use App\Enums\MetodoCaptura;
use App\Enums\Rol;
use App\Enums\TipoReverso;
use App\Exceptions\RegistroInmutableException;
use App\Exceptions\ReglaInventarioException;
use App\Models\Auditoria;
use App\Models\Cliente;
use App\Models\Proveedor;
use App\Models\Referencia;
use App\Models\Reverso;
use App\Models\User;
use App\Models\Venta;
use App\Models\VentaItem;
use App\Services\ReversoService;
use App\Services\VentaService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;

/*
 * RF-06 / RF-07 / RF-11 / S-40 / S-43 / S-46: la venta nace con su primer escaneo (ningún camino
 * HTTP crea una venta vacía) y las abiertas sin ítems vigentes se descartan solas, sin borrarse.
 */

beforeEach(fn () => sembrarDemo());

function servicioVentas(): VentaService
{
    return app(VentaService::class);
}

/** Venta abierta vacía (con el servicio) creada hace $dias días. */
function ventaVaciaS46(User $usuario, int $dias = 1, FormaPago $forma = FormaPago::Contado, ?int $clienteId = null): Venta
{
    $venta = servicioVentas()->abrir($usuario, $forma, $clienteId);
    if ($dias > 0) {
        DB::table('ventas')->where('id', $venta->id)->update(['created_at' => now()->subDays($dias)]);
    }

    return $venta->refresh();
}

/** Venta abierta con un ítem de la referencia (vigente salvo que se anule). */
function ventaConItemS46(User $usuario, string $codigoRef = 'RN-4410', int $dias = 1, bool $anular = false, ?Cliente $cliente = null): Venta
{
    $venta = ventaVaciaS46($usuario, $dias, $cliente ? FormaPago::Credito : FormaPago::Contado, $cliente?->id);
    servicioVentas()->escanear($venta, unidadDe($codigoRef)->codigo_barras, MetodoCaptura::Escaneado, $usuario);
    if ($anular) {
        app(ReversoService::class)->reversar($venta, TipoReverso::Anulacion, 'Prueba S-46', null, $usuario);
    }

    return $venta->refresh();
}

it('S-46 (1): POST /ventas sin el payload del escaneo se rechaza (422): no hay camino HTTP a una venta vacía', function () {
    actuarComo(Rol::Mostrador);

    $this->postJson('/api/ventas')->assertUnprocessable()->assertJsonValidationErrors(['codigo_barras', 'metodo_captura']);
    $this->postJson('/api/ventas', ['forma_pago' => 'contado'])->assertUnprocessable();
    $this->postJson('/api/ventas', ['forma_pago' => 'credito', 'cliente_id' => Cliente::factory()->create()->id])->assertUnprocessable();
    $this->postJson('/api/ventas', ['codigo_barras' => '', 'metodo_captura' => 'escaneado'])->assertUnprocessable();

    expect(Venta::count())->toBe(0);
});

it('S-46 (2): POST /ventas con un código válido crea la venta y su primer ítem en una operación', function () {
    $mostrador = actuarComo(Rol::Mostrador);
    $unidad = unidadDe('RN-4410');

    $r = $this->postJson('/api/ventas', ['codigo_barras' => $unidad->codigo_barras, 'metodo_captura' => 'escaneado'])
        ->assertCreated()
        ->assertJsonPath('unidad.id', $unidad->id)
        ->assertJsonPath('metodo_captura', 'escaneado')
        ->assertJsonPath('origen_codigo', 'etiqueta')
        ->assertJsonPath('totales.total', '85000.00');

    $venta = Venta::sole();
    expect($r->json('venta_id'))->toBe($venta->id)
        ->and($venta->estado)->toBe(EstadoVenta::Abierta)
        ->and($venta->usuario_id)->toBe($mostrador->id)
        ->and(VentaItem::where('venta_id', $venta->id)->pluck('unidad_id')->all())->toBe([$unidad->id])
        ->and($unidad->refresh()->estado->value)->toBe('vendida');

    // A crédito, con su cliente, y con cantidad por el código del proveedor (S-40).
    $cliente = Cliente::factory()->create();
    $ref = Referencia::factory()->create(['codigo_referencia' => 'S46-CANT']);
    ingresar($ref, 3);
    $id = $this->postJson('/api/ventas', ['forma_pago' => 'credito', 'cliente_id' => $cliente->id, 'codigo_barras' => 'S46-CANT', 'metodo_captura' => 'digitado', 'cantidad' => 2])
        ->assertCreated()->assertJsonCount(2, 'items')->json('venta_id');
    expect(Venta::find($id))->forma_pago->toBe(FormaPago::Credito)->cliente_id->toBe($cliente->id);
});

it('S-46 (3): un primer escaneo fallido no deja ninguna venta (inexistente, sin precio, no disponible, elegir referencia)', function () {
    $mostrador = actuarComo(Rol::Mostrador);
    $vendida = ventaConItemS46($mostrador, 'RN-4410', 0); // deja una unidad vendida y una venta previa
    $codigoVendido = VentaItem::where('venta_id', $vendida->id)->sole()->unidad->codigo_barras;
    $antes = Venta::count();
    $itemsAntes = VentaItem::count();

    // Código inexistente.
    $this->postJson('/api/ventas', ['codigo_barras' => 'NO-EXISTE-46', 'metodo_captura' => 'escaneado'])->assertNotFound();
    // Unidad no disponible (ya vendida): 409, el conflicto de estado de siempre.
    $this->postJson('/api/ventas', ['codigo_barras' => $codigoVendido, 'metodo_captura' => 'escaneado'])->assertConflict();
    // Referencia sin precio.
    $sinPrecio = unidadDe('ARR-12V-9D');
    DB::table('referencias')->where('id', $sinPrecio->referencia_id)->update(['precio_venta' => 0]);
    $this->postJson('/api/ventas', ['codigo_barras' => $sinPrecio->codigo_barras, 'metodo_captura' => 'escaneado'])
        ->assertUnprocessable()->assertJsonPath('message', fn ($m) => str_contains($m, 'no tiene precio de venta'));
    // Código en dos referencias: la respuesta de elección es la de siempre y no deja nada creado.
    foreach (['Prov S46 A', 'Prov S46 B'] as $nombre) {
        ingresar(Referencia::factory()->create(['codigo_referencia' => 'DUP-46', 'proveedor_id' => Proveedor::factory()->create(['nombre' => $nombre])->id]), 1);
    }
    $r = $this->postJson('/api/ventas', ['codigo_barras' => 'DUP-46', 'metodo_captura' => 'escaneado'])
        ->assertUnprocessable()->assertJsonPath('tipo', 'elegir_referencia')->assertJsonCount(2, 'referencias');

    expect(Venta::count())->toBe($antes)->and(VentaItem::count())->toBe($itemsAntes);

    // Con la referencia elegida, ahora sí nace la venta con su ítem.
    $this->postJson('/api/ventas', ['codigo_barras' => 'DUP-46', 'metodo_captura' => 'escaneado', 'referencia_id' => $r->json('referencias.1.id')])->assertCreated();
    expect(Venta::count())->toBe($antes + 1);
});

it('S-46 (4): descartar vacías: la vieja sin ítems vigentes pasa a descartada, auditada y sin borrarse; las demás no', function () {
    $mostrador = User::factory()->rol(Rol::Mostrador)->create();
    $vacia = ventaVaciaS46($mostrador);
    $conItem = ventaConItemS46($mostrador);
    $anulada = ventaConItemS46($mostrador, 'ARR-12V-9D', 1, anular: true);
    $deHoy = ventaVaciaS46($mostrador, 0);

    expect(servicioVentas()->descartarVacias())->toBe(2);

    $vacia->refresh();
    expect($vacia->estado)->toBe(EstadoVenta::Descartada)
        ->and($vacia->descartada_at)->not->toBeNull()
        ->and($vacia->descartada_por)->toBeNull()
        ->and(Auditoria::where(['tabla' => 'ventas', 'registro_id' => $vacia->id, 'campo' => 'estado'])->sole())
        ->valor_anterior->toBe('abierta')->valor_nuevo->toBe('descartada')->usuario_id->toBeNull()
        ->and($conItem->refresh()->estado)->toBe(EstadoVenta::Abierta)
        ->and($anulada->refresh()->estado)->toBe(EstadoVenta::Descartada)
        ->and(VentaItem::where('venta_id', $anulada->id)->count())->toBe(1)
        ->and(Reverso::where('venta_id', $anulada->id)->count())->toBe(1)
        ->and($deHoy->refresh()->estado)->toBe(EstadoVenta::Abierta)
        ->and(Venta::count())->toBe(4);

    // Con incluirHoy (--todas) también la de hoy.
    expect(servicioVentas()->descartarVacias(null, true))->toBe(1)
        ->and($deHoy->refresh()->estado)->toBe(EstadoVenta::Descartada)
        ->and(Venta::count())->toBe(4);
});

it('S-46 (5): abrir una venta descarta antes las vacías viejas del propio usuario, no las de otros', function () {
    $otro = User::factory()->rol(Rol::Mostrador)->create();
    $ajena = ventaVaciaS46($otro);
    $mostrador = actuarComo(Rol::Mostrador);
    $propia = ventaVaciaS46($mostrador, 3);
    $propiaDeHoy = ventaVaciaS46($mostrador, 0);

    $this->postJson('/api/ventas', escaneoInicial('RN-4410'))->assertCreated();

    expect($propia->refresh()->estado)->toBe(EstadoVenta::Descartada)
        ->and($propiaDeHoy->refresh()->estado)->toBe(EstadoVenta::Abierta)
        ->and($ajena->refresh()->estado)->toBe(EstadoVenta::Abierta);
});

it('S-46 (6): las descartadas no cuentan en cartera, informes, panel ni en las abiertas; salen con estado=descartada', function () {
    $mostrador = actuarComo(Rol::Mostrador);
    $cliente = Cliente::factory()->create();
    $credito = ventaConItemS46($mostrador, 'RN-4410', 2, anular: true, cliente: $cliente);
    $conItemVieja = ventaConItemS46($mostrador, 'ARR-12V-9D', 2);
    servicioVentas()->descartarVacias();
    expect($credito->refresh()->estado)->toBe(EstadoVenta::Descartada);

    $abiertas = collect($this->getJson('/api/ventas?estado=abierta')->assertOk()->json('data'))->pluck('id')->all();
    $descartadas = collect($this->getJson('/api/ventas?estado=descartada')->assertOk()->json('data'))->pluck('id')->all();
    expect($abiertas)->toBe([$conItemVieja->id])->and($descartadas)->toBe([$credito->id]);
    $this->getJson("/api/ventas/{$credito->id}")->assertOk()->assertJsonPath('estado', 'descartada')
        ->assertJsonPath('descartada_at', fn ($v) => $v !== null);

    actuarComo(Rol::Admin);
    $cartera = collect($this->getJson('/api/cartera?solo_con_saldo=0')->assertOk()->json('data'))->firstWhere('cliente.id', $cliente->id)
        ?? collect($this->getJson('/api/cartera?solo_con_saldo=0')->json('data'))->firstWhere('id', $cliente->id);
    expect($cartera === null || $cartera['ventas'] === '0.00')->toBeTrue();
    expect($this->getJson('/api/reportes/ventas-credito?desde='.now()->subDays(5)->toDateString().'&hasta='.now()->toDateString())->assertOk()->json('data'))->toBe([]);

    $control = $this->getJson('/api/panel/control?refrescar=1')->assertOk();
    // Solo la abierta vieja CON ítem vigente avisa; la descartada no.
    expect($control->json('ventas_abiertas_viejas'))->toBe(1);
    expect($this->getJson('/api/panel/resumen?refrescar=1')->assertOk()->json('ventas.ventas'))->toBe(0);
});

it('S-46 (7): escanear, cerrar, cambiar precio y reversar sobre una descartada se rechazan', function () {
    $mostrador = actuarComo(Rol::Mostrador);
    $venta = ventaConItemS46($mostrador, 'RN-4410', 1, anular: true, cliente: Cliente::factory()->create());
    servicioVentas()->descartarVacias();
    $item = VentaItem::where('venta_id', $venta->id)->sole();
    $descartada = fn ($m) => str_contains($m, 'descartada');

    // Escanear y cerrar responden 409 como con una venta cerrada (conflicto de estado); el resto, 422.
    $this->postJson("/api/ventas/{$venta->id}/escanear", escaneoInicial('ARR-12V-9D'))->assertConflict()->assertJsonPath('message', $descartada);
    cerrarVenta($venta->id)->assertConflict()->assertJsonPath('message', $descartada);
    $this->patchJson("/api/ventas/{$venta->id}/items/{$item->id}", ['precio_unitario' => 1000])->assertUnprocessable()->assertJsonPath('message', $descartada);
    $this->patchJson("/api/ventas/{$venta->id}/items/precio", ['ids' => [$item->id], 'precio_unitario' => 1000])->assertUnprocessable()->assertJsonPath('message', $descartada);
    $this->postJson("/api/ventas/{$venta->id}/reversos", ['tipo' => 'anulacion', 'motivo' => 'Prueba'])->assertUnprocessable()->assertJsonPath('message', $descartada);

    expect($venta->refresh()->estado)->toBe(EstadoVenta::Descartada)
        ->and(VentaItem::where('venta_id', $venta->id)->count())->toBe(1);
});

it('S-46 (8): el comando ventas:descartar-vacias cuenta con --dry-run, incluye hoy con --todas y está en el scheduler', function () {
    $mostrador = User::factory()->rol(Rol::Mostrador)->create();
    $vieja = ventaVaciaS46($mostrador, 2);
    $deHoy = ventaVaciaS46($mostrador, 0);

    $this->artisan('ventas:descartar-vacias', ['--dry-run' => true])->expectsOutputToContain('Se descartarían 1')->assertSuccessful();
    $this->artisan('ventas:descartar-vacias', ['--dry-run' => true, '--todas' => true])->expectsOutputToContain('Se descartarían 2')->assertSuccessful();
    expect(Venta::where('estado', 'descartada')->count())->toBe(0);

    $this->artisan('ventas:descartar-vacias')->expectsOutputToContain('Se descartaron 1')->assertSuccessful();
    expect($vieja->refresh()->estado)->toBe(EstadoVenta::Descartada)->and($deHoy->refresh()->estado)->toBe(EstadoVenta::Abierta);

    $this->artisan('ventas:descartar-vacias', ['--todas' => true])->expectsOutputToContain('Se descartaron 1')->assertSuccessful();
    expect($deHoy->refresh()->estado)->toBe(EstadoVenta::Descartada);

    $evento = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command ?? '', 'ventas:descartar-vacias'));
    expect($evento)->not->toBeNull()
        ->and($evento->expression)->toBe('55 23 * * *')
        ->and($evento->timezone)->toBe('America/Bogota');
});

it('S-46 (9): escaneo y limpieza en cualquier orden: o se descarta o se escanea; nunca una descartada con ítems', function () {
    $mostrador = User::factory()->rol(Rol::Mostrador)->create();

    // El escaneo entra primero: la limpieza (aun con --todas) ya no la toca.
    $a = ventaVaciaS46($mostrador, 0);
    servicioVentas()->escanear($a, unidadDe('RN-4410')->codigo_barras, MetodoCaptura::Escaneado, $mostrador);
    expect(servicioVentas()->descartarVacias(null, true))->toBe(0)->and($a->refresh()->estado)->toBe(EstadoVenta::Abierta);

    // La limpieza entra primero: el escaneo encuentra la venta descartada y se rechaza sin vender.
    $b = ventaVaciaS46($mostrador, 0);
    servicioVentas()->descartarVacias(null, true);
    $unidad = unidadDe('ARR-12V-9D');
    expect(fn () => servicioVentas()->escanear($b, $unidad->codigo_barras, MetodoCaptura::Escaneado, $mostrador))
        ->toThrow(ReglaInventarioException::class, 'descartada');
    expect($unidad->refresh()->estado->value)->toBe('disponible');

    $descartadasConItems = Venta::where('estado', 'descartada')
        ->whereHas('items', fn ($q) => $q->whereDoesntHave('reversoItem'))->count();
    expect($descartadasConItems)->toBe(0);
});

it('S-46 (10): admin y logística reciben 403 en POST /ventas; la venta es siempre de quien escanea; el guard sigue firme', function () {
    foreach ([Rol::Admin, Rol::Logistica] as $rol) {
        actuarComo($rol);
        $this->postJson('/api/ventas', escaneoInicial('RN-4410'))->assertForbidden()->assertJsonPath('message', 'Su rol no tiene acceso a este módulo.');
    }
    expect(Venta::count())->toBe(0);

    // Un mostrador no puede usar POST /ventas para escanear en la venta de otro: siempre nace una propia.
    $otro = User::factory()->rol(Rol::Mostrador)->create();
    $ajena = ventaConItemS46($otro, 'RN-4410', 0);
    $yo = actuarComo(Rol::Mostrador);
    $id = $this->postJson('/api/ventas', ['venta_id' => $ajena->id, ...escaneoInicial('ARR-12V-9D')])->assertCreated()->json('venta_id');
    expect($id)->not->toBe($ajena->id)
        ->and(Venta::find($id)->usuario_id)->toBe($yo->id)
        ->and(VentaItem::where('venta_id', $ajena->id)->count())->toBe(1);

    // Guard de inmutabilidad: descartar con otro campo, revivir una descartada o borrarla se rechazan.
    $vacia = ventaVaciaS46($yo);
    $vacia->estado = EstadoVenta::Descartada;
    $vacia->forma_pago = FormaPago::Credito;
    expect(fn () => $vacia->save())->toThrow(RegistroInmutableException::class);

    servicioVentas()->descartarVacias($yo);
    $descartada = $vacia->fresh();
    expect($descartada->estado)->toBe(EstadoVenta::Descartada);
    $descartada->estado = EstadoVenta::Abierta;
    expect(fn () => $descartada->save())->toThrow(RegistroInmutableException::class);
    $descartada = $vacia->fresh();
    $descartada->estado = EstadoVenta::Cerrada;
    expect(fn () => $descartada->save())->toThrow(RegistroInmutableException::class);
    expect(fn () => $vacia->fresh()->delete())->toThrow(RegistroInmutableException::class);
    expect(Venta::whereKey($vacia->id)->exists())->toBeTrue();
});
