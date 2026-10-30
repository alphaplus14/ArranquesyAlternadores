<?php

namespace Database\Seeders;

use App\Enums\Rol;
use App\Models\Categoria;
use App\Models\CodigoUniversal;
use App\Models\Marca;
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
 * - RF-03: un regulador en tres marcas (Bosch, Transpo, Marca Ejemplo), 1 unidad cada una = 3.
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
        $marcas = Marca::whereIn('nombre', ['Bosch', 'Transpo', 'Marca Ejemplo'])->get()->keyBy('nombre');

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
            'marca_id' => $marcas['Marca Ejemplo']->id,
            'proveedor_id' => $proveedores[$i]->id,
            'categoria_id' => $reguladores->id,
            'codigo_universal_id' => $im204->id,
            'descripcion' => 'Regulador IM204',
            'precio_venta' => 85000,
        ]));

        // RF-03: el mismo regulador en tres marcas.
        $im210 = CodigoUniversal::create([
            'codigo' => 'IM210',
            'categoria_id' => $reguladores->id,
            'descripcion' => 'Regulador IM210, ejemplo de tres marcas',
        ]);

        $porMarca = collect(['Bosch' => 'F00M144136', 'Transpo' => 'IM210', 'Marca Ejemplo' => 'ME-210'])
            ->map(fn ($codigo, $marca) => Referencia::create([
                'codigo_referencia' => $codigo,
                'marca_id' => $marcas[$marca]->id,
                'proveedor_id' => $proveedores[0]->id,
                'categoria_id' => $reguladores->id,
                'codigo_universal_id' => $im210->id,
                'descripcion' => "Regulador IM210 {$marca}",
                'precio_venta' => $marca === 'Bosch' ? 140000 : 90000,
            ]));

        // RF-01: una pieza fuera del esquema universal se consulta por su referencia original.
        $arranque = Referencia::create([
            'codigo_referencia' => 'ARR-12V-9D',
            'marca_id' => $marcas['Bosch']->id,
            'proveedor_id' => $proveedores[1]->id,
            'categoria_id' => Categoria::where('nombre', 'Arranques')->value('id'),
            'descripcion' => 'Arranque 12V 9 dientes',
            'precio_venta' => 420000,
        ]);

        // RF-08: una referencia agotada para el reporte (sin ingreso).
        Referencia::create([
            'codigo_referencia' => 'SEN-TEMP-01',
            'marca_id' => $marcas['Bosch']->id,
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
            'descuento_total' => 5000, // RF-09
        ], $logistica);
        foreach ($porMarca as $referencia) {
            $ingresos->agregarItem($ingreso, ['referencia_id' => $referencia->id, 'cantidad' => 1, 'costo_unitario' => 60000]);
        }
        $ingresos->agregarItem($ingreso, [
            'referencia_id' => $arranque->id, 'cantidad' => 1, 'costo_unitario' => 250000, 'sin_iva' => true, // RF-10
        ]);
        $ingresos->confirmar($ingreso, $logistica);
    }
}
