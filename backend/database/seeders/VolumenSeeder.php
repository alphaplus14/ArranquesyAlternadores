<?php

namespace Database\Seeders;

use App\Enums\Calidad;
use App\Enums\EstadoUnidad;
use App\Enums\FormaPago;
use App\Enums\MetodoCaptura;
use App\Enums\Rol;
use App\Enums\TipoIngreso;
use App\Enums\TipoReverso;
use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\CodigoUniversal;
use App\Models\CuentaPago;
use App\Models\Proveedor;
use App\Models\Referencia;
use App\Models\Unidad;
use App\Models\User;
use App\Services\IngresoService;
use App\Services\ReversoService;
use App\Services\VentaService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * SOLO DESARROLLO. Volumen para probar el frontend con listas largas: ~100 filas en usuarios,
 * proveedores, clientes, universales, ingresos, ventas, más referencias, unidades, reversos y
 * auditoría que salen de esos flujos.
 *
 * Todo entra por los servicios (IngresoService, VentaService, ReversoService), así existencia,
 * códigos de barras (RF-03), movimientos, metodo_captura (RF-12) y auditoría (RF-11) quedan
 * coherentes. Las fechas se reparten en los últimos ~4 meses con Carbon::setTestNow.
 *
 * php artisan db:seed --class=VolumenSeeder
 */
class VolumenSeeder extends Seeder
{
    private const MARCA = 'vol01'; // usuario de control: si existe, ya se sembró

    public function run(IngresoService $ingresos, VentaService $ventas, ReversoService $reversos): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('VolumenSeeder es solo para desarrollo.');
        }
        if (User::where('usuario', self::MARCA)->exists()) {
            $this->command?->warn('VolumenSeeder ya se ejecutó; no se repite.');

            return;
        }

        $admin = User::where('rol', Rol::Admin)->firstOrFail();
        Auth::setUser($admin); // Auditable toma Auth::id()

        // Todo o nada: si algo falla a mitad, no queda media carga.
        try {
            DB::transaction(fn () => $this->sembrar($ingresos, $ventas, $reversos));
        } finally {
            Carbon::setTestNow();
        }
    }

    private function sembrar(IngresoService $ingresos, VentaService $ventas, ReversoService $reversos): void
    {
        $f = fake('es_ES');
        $f->seed(2026);
        mt_srand(2026);

        $ahora = now(); // reloj real, antes de moverlo
        $hoy = $ahora->copy()->startOfDay();
        Carbon::setTestNow(now()->subDays(130));

        // ---------- Usuarios (hasta 100) ----------
        $usuarios = collect();
        for ($i = 1; $i <= 97; $i++) {
            $rol = $i % 3 === 0 ? Rol::Logistica : Rol::Mostrador;
            $usuarios->push(User::create([
                'name' => $f->firstName().' '.$f->lastName(),
                'usuario' => sprintf('vol%02d', $i),
                'password' => DatabaseSeeder::CLAVE_DESARROLLO,
                'rol' => $rol,
                'activo' => $i % 11 !== 0,
            ]));
        }
        $logisticas = $usuarios->where('rol', Rol::Logistica)->where('activo', true)->values()
            ->push(User::where('usuario', 'logistica')->first());
        $mostradores = $usuarios->where('rol', Rol::Mostrador)->where('activo', true)->values()
            ->push(User::where('usuario', 'mostrador')->first());

        // ---------- Proveedores (hasta 100) ----------
        $faltan = max(0, 100 - Proveedor::count());
        $proveedores = collect();
        for ($i = 1; $i <= $faltan; $i++) {
            $proveedores->push(Proveedor::create([
                'nombre' => $f->unique()->company().' '.$f->randomElement(['Repuestos', 'Autoeléctricos', 'Distribuciones', 'Importaciones']),
                'nit' => $i % 5 === 0 ? null : (string) $f->unique()->numberBetween(800000000, 999999999).'-'.$f->numberBetween(0, 9),
                'plazo_dias' => $f->randomElement([0, 15, 30, 30, 45, 60]),
            ]));
        }
        $proveedores = $proveedores->merge(Proveedor::whereIn('nombre', ['Repuestos del Norte', 'Distribuidora Eléctrica', 'Autopartes Central'])->get());

        // ---------- Clientes (100) ----------
        for ($i = 1; $i <= 100; $i++) {
            Cliente::create([
                'nombre' => $i % 4 === 0 ? $f->company() : $f->firstName().' '.$f->lastName().' '.$f->lastName(),
                'documento' => $i % 9 === 0 ? null : (string) $f->unique()->numberBetween(10000000, 1999999999),
                'telefono' => $i % 6 === 0 ? null : '3'.$f->numerify('#########'),
                'plazo_dias' => $f->randomElement([15, 30, 30, 30, 45, 60]),
                'activo' => $i % 12 !== 0,
            ]);
        }
        $clientes = Cliente::where('activo', true)->get();

        // ---------- Universales (hasta 100) y referencias con precio > 0 ----------
        $catUniversal = Categoria::where('admite_codigo_universal', true)->get();
        $catSinUniversal = Categoria::where('admite_codigo_universal', false)->get();
        $prefijos = ['Reguladores' => 'IM', 'Portadiodos' => 'PD', 'Poleas' => 'PL', 'Automáticos / solenoides' => 'AU'];
        $piezas = ['Reguladores' => 'Regulador', 'Portadiodos' => 'Portadiodo', 'Poleas' => 'Polea', 'Automáticos / solenoides' => 'Automático'];
        $vehiculos = ['Hilux', 'Spark', 'Aveo', 'Sail', 'Logan', 'Sandero', 'Duster', 'NPR', 'NHR', 'Corolla', 'Prado', 'Mazda 3', 'BT-50', 'Fiesta', 'Ranger', 'Swift', 'Twingo', 'Kia Rio', 'Picanto', 'Tucson'];

        $referencias = collect();
        $nuevaReferencia = function (Categoria $cat, string $desc, ?int $universalId, int $base) use ($f, $proveedores, $referencias) {
            $proveedor = $proveedores->random();
            $referencias->push(Referencia::create([
                'codigo_referencia' => strtoupper($f->unique()->bothify('??-####-?#')),
                'calidad' => $f->randomElement([Calidad::Fino, Calidad::Generico, null]),
                'proveedor_id' => $proveedor->id,
                'categoria_id' => $cat->id,
                'codigo_universal_id' => $universalId,
                'descripcion' => $desc,
                'precio_venta' => round($base * $f->randomFloat(2, 0.8, 1.4), -2),
                'iva_venta_porcentaje' => $f->boolean(90) ? 19 : 0,
            ]));
        };

        $faltanU = max(0, 100 - CodigoUniversal::count());
        for ($i = 1; $i <= $faltanU; $i++) {
            $cat = $catUniversal->random();
            $codigo = ($prefijos[$cat->nombre] ?? 'UN').(300 + $i);
            $desc = ($piezas[$cat->nombre] ?? $cat->nombre).' '.$codigo.' '.$f->randomElement($vehiculos).' '.$f->randomElement(['12V', '24V']);
            $u = CodigoUniversal::create(['codigo' => $codigo, 'categoria_id' => $cat->id, 'descripcion' => $desc]);
            foreach (range(1, $f->numberBetween(1, 3)) as $_) {
                $nuevaReferencia($cat, $desc, $u->id, $f->randomElement([35000, 60000, 90000, 140000]));
            }
        }
        for ($i = 1; $i <= 60; $i++) {
            $cat = $catSinUniversal->random();
            $desc = rtrim($cat->nombre, 'es').' '.$f->randomElement($vehiculos).' '.$f->randomElement(['12V', '24V']).' '.$f->randomElement(['9 dientes', '10 dientes', '90A', '120A', '']);
            $nuevaReferencia($cat, trim($desc), null, $f->randomElement([120000, 250000, 420000, 650000]));
        }
        $porProveedor = $referencias->groupBy('proveedor_id');

        // ---------- Ingresos (100): remisiones y facturas, contado y crédito, 15 en borrador ----------
        for ($i = 1; $i <= 100; $i++) {
            Carbon::setTestNow(now()->startOfDay()->addHours(7)->addDays(1)->addMinutes($f->numberBetween(0, 600)));
            $provId = $porProveedor->keys()->random();
            $refs = $porProveedor[$provId];
            $usuario = $logisticas->random();
            $tipo = $f->boolean(55) ? TipoIngreso::Factura : TipoIngreso::Remision;
            $credito = $f->boolean(40);

            $datos = [
                'tipo' => $tipo,
                'proveedor_id' => $provId,
                'numero_documento' => ($tipo === TipoIngreso::Factura ? 'FE-' : 'REM-').(5000 + $i),
                'fecha' => now()->toDateString(),
                'forma_pago' => $credito ? FormaPago::Credito : FormaPago::Contado,
                'vence_el' => $credito ? now()->addDays($f->randomElement([30, 45, 60]))->toDateString() : null,
            ];
            if ($i % 7 === 0) {
                $datos['descuento_documento_porcentaje'] = $f->randomElement([2, 5, 10]);
            } elseif ($i % 9 === 0) {
                $datos['descuento_documento'] = $f->randomElement([5000, 10000, 20000]);
            }
            $ingreso = $ingresos->crearBorrador($datos, $usuario);

            foreach ($refs->shuffle()->take($f->numberBetween(2, 5)) as $ref) {
                $ingresos->agregarItem($ingreso, [
                    'referencia_id' => $ref->id,
                    'cantidad' => $f->numberBetween(1, 5),
                    'costo_unitario' => round((float) $ref->precio_venta * $f->randomFloat(2, 0.5, 0.7), -2),
                    'descuento_porcentaje' => $f->boolean(20) ? $f->randomElement([3, 5, 8]) : 0,
                ]);
            }

            if ($i <= 85) {
                $ingresos->confirmar($ingreso, $usuario);
            }
        }

        // ---------- Ventas (110) y reversos ----------
        $disponibles = Unidad::query()
            ->whereIn('referencia_id', $referencias->pluck('id'))
            ->where('estado', EstadoUnidad::Disponible)
            ->inRandomOrder()
            ->pluck('codigo_barras');

        $abiertas = collect();
        $cerradas = collect();
        for ($i = 1; $i <= 110; $i++) {
            // Cerradas: el último mes, de 8 a 18, nunca después de ayer. Abiertas (últimas 15): hoy.
            if ($i <= 95) {
                $momento = now()->addHours($f->numberBetween(1, 4))->addMinutes($f->numberBetween(0, 59));
                if ($momento->hour < 8 || $momento->hour >= 18) {
                    $momento = $momento->addDay()->setTime(8, $f->numberBetween(0, 59));
                }
                Carbon::setTestNow($momento->min($hoy->copy()->subDay()->setTime(17, 0)));
            } else {
                Carbon::setTestNow($ahora->copy()->subMinutes((111 - $i) * 15));
            }
            $vendedor = $mostradores->random();
            $credito = $i % 3 === 0;
            $venta = $ventas->abrir(
                $vendedor,
                $credito ? FormaPago::Credito : FormaPago::Contado,
                $credito ? $clientes->random()->id : null,
                $f->boolean(30) ? 'FE-'.$f->numberBetween(10000, 99999) : null,
            );

            $items = collect();
            foreach (range(1, $f->numberBetween(1, 4)) as $_) {
                $codigo = $disponibles->shift();
                if (! $codigo) {
                    break;
                }
                $items->push($ventas->escanear($venta, $codigo, $f->boolean(85) ? MetodoCaptura::Escaneado : MetodoCaptura::Digitado, $vendedor));
            }

            // RF-14 / S-18: precio libre en crédito abierto, con auditoría.
            if ($credito && $f->boolean(50)) {
                $item = $items->first();
                $ventas->cambiarPrecio($venta, $item, round((float) $item->precio_unitario * $f->randomFloat(2, 0.85, 1.1), -2), $vendedor);
            }

            // Las últimas 15 quedan abiertas.
            if ($i <= 95) {
                // RF-24 (S-48): la de contado se paga con el total exacto (casi siempre en efectivo).
                $pagos = $credito ? [] : [['cuenta_pago_id' => ($f->boolean(75) ? CuentaPago::efectivo() : CuentaPago::query()->where('tipo', '!=', 'efectivo')->inRandomOrder()->first() ?? CuentaPago::efectivo())->id, 'valor' => $ventas->totales($venta->id)->total]];
                $cerradas->push($ventas->cerrar($venta, $pagos));
            } else {
                $abiertas->push([$venta, $items, $vendedor]);
            }
        }

        $motivos = ['Cliente devolvió: no era la pieza', 'Pieza defectuosa', 'Error de digitación', 'Cliente desistió de la compra', 'Referencia equivocada'];
        // RF-07: devoluciones completas y parciales de ventas cerradas.
        foreach ($cerradas->shuffle()->take(25)->values() as $n => $venta) {
            Carbon::setTestNow($venta->cerrada_at->copy()->addDays($f->numberBetween(0, 3))->addHours(2)->min($ahora->copy()->subHours(5)));
            $item = $n % 2 === 0 ? null : $venta->items()->value('id');
            $reversos->reversar($venta, TipoReverso::Devolucion, $f->randomElement($motivos), $item, $mostradores->random());
        }
        // Quitar ítems de ventas abiertas = anulación del ítem.
        foreach ($abiertas->take(6) as [$venta, $items, $vendedor]) {
            Carbon::setTestNow($venta->created_at->copy()->addMinutes(5));
            $reversos->reversar($venta, TipoReverso::Anulacion, 'Ítem escaneado por error', $items->last()->id, $vendedor);
        }

        $this->command?->info('VolumenSeeder: '.$usuarios->count().' usuarios, '.$proveedores->count().' proveedores nuevos/demo, 100 clientes, '
            .$faltanU.' universales, '.$referencias->count().' referencias, 100 ingresos, 110 ventas, 31 reversos.');
    }
}
