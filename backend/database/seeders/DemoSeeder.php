<?php

namespace Database\Seeders;

use App\Enums\Rol;
use App\Models\Categoria;
use App\Models\CodigoUniversal;
use App\Models\Proveedor;
use App\Models\Referencia;
use App\Models\User;
use App\Services\IngresoService;
use Illuminate\Database\Seeder;

/**
 * Datos de los ejemplos del documento. Las unidades entran por IngresoService, así tienen
 * código de barras real y movimientos, como cualquier ingreso.
 *
 * - RF-01/RF-02: universal IM204 con tres referencias de proveedor, 2 + 2 + 2 = 6.
 * - RF-03 / S-23: el regulador IM210 en tres referencias del mismo proveedor (una fina, una
 *   genérica y una sin calidad), 1 unidad cada una = 3.
 */
class DemoSeeder extends Seeder
{
    public function run(IngresoService $ingresos): void
    {
        if (CodigoUniversal::where('codigo', 'IM204')->exists()) {
            return; // ya sembrado
        }

        $logistica = User::where('rol', Rol::Logistica)->firstOrFail();
        $reguladores = Categoria::where('nombre', 'Reguladores')->firstOrFail();

        $proveedores = collect(['Repuestos del Norte', 'Distribuidora Eléctrica', 'Autopartes Central'])
            ->map(fn ($nombre) => Proveedor::firstOrCreate(['nombre' => $nombre]));

        // RF-01 / RF-02
        $im204 = CodigoUniversal::create([
            'codigo' => 'IM204',
            'categoria_id' => $reguladores->id,
            'descripcion' => 'Regulador IM204 (código de unificación Transpo)',
        ]);

        $referenciasIm204 = collect(['RN-4410', 'DE-IM204-A', 'AC-77812'])->map(fn ($codigo, $i) => Referencia::create([
            'codigo_referencia' => $codigo,
            'proveedor_id' => $proveedores[$i]->id,
            'categoria_id' => $reguladores->id,
            'codigo_universal_id' => $im204->id,
            'descripcion' => 'Regulador IM204',
            'precio_venta' => 85000,
        ]));

        // RF-03 / S-23: el mismo regulador en tres referencias del mismo proveedor.
        $im210 = CodigoUniversal::create([
            'codigo' => 'IM210',
            'categoria_id' => $reguladores->id,
            'descripcion' => 'Regulador IM210, ejemplo de tres referencias',
        ]);

        $referenciasIm210 = collect([
            ['F00M144136', 'fino', 140000],
            ['IM210', 'generico', 90000],
            ['ME-210', null, 90000],
        ])->map(fn ($r) => Referencia::create([
            'codigo_referencia' => $r[0],
            'calidad' => $r[1],
            'proveedor_id' => $proveedores[0]->id,
            'categoria_id' => $reguladores->id,
            'codigo_universal_id' => $im210->id,
            'descripcion' => 'Regulador IM210',
            'precio_venta' => $r[2],
        ]));

        // RF-01: una pieza fuera del esquema universal se consulta por su referencia original.
        $arranque = Referencia::create([
            'codigo_referencia' => 'ARR-12V-9D',
            'proveedor_id' => $proveedores[1]->id,
            'categoria_id' => Categoria::where('nombre', 'Arranques')->value('id'),
            'descripcion' => 'Arranque 12V 9 dientes',
            'precio_venta' => 420000,
        ]);

        // RF-08: una referencia agotada para el reporte (sin ingreso).
        Referencia::create([
            'codigo_referencia' => 'SEN-TEMP-01',
            'proveedor_id' => $proveedores[1]->id,
            'categoria_id' => Categoria::where('nombre', 'Sensores')->value('id'),
            'descripcion' => 'Sensor de temperatura',
            'precio_venta' => 35000,
        ]);

        // RF-05: ingresos por remisión.
        foreach ($referenciasIm204 as $i => $referencia) {
            $ingreso = $ingresos->crearBorrador([
                'proveedor_id' => $referencia->proveedor_id,
                'numero_documento' => 'REM-'.(1001 + $i),
                'fecha' => now()->toDateString(),
            ], $logistica);
            $ingresos->agregarItem($ingreso, ['referencia_id' => $referencia->id, 'cantidad' => 2, 'costo_unitario' => 52000]);
            $ingresos->confirmar($ingreso, $logistica);
        }

        $ingreso = $ingresos->crearBorrador([
            'proveedor_id' => $proveedores[0]->id,
            'numero_documento' => 'REM-2001',
            'fecha' => now()->toDateString(),
            'descuento_documento' => 5000, // RF-09
        ], $logistica);
        foreach ($referenciasIm210 as $referencia) {
            $ingresos->agregarItem($ingreso, ['referencia_id' => $referencia->id, 'cantidad' => 1, 'costo_unitario' => 60000]);
        }
        $ingresos->agregarItem($ingreso, [
            'referencia_id' => $arranque->id, 'cantidad' => 1, 'costo_unitario' => 250000, 'iva_porcentaje' => 0, // RF-10: sin IVA
        ]);
        $ingresos->confirmar($ingreso, $logistica);
    }
}
