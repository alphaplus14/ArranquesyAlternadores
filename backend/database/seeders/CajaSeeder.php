<?php

namespace Database\Seeders;

use App\Models\CategoriaCaja;
use App\Models\CuentaPago;
use Illuminate\Database\Seeder;

/**
 * RF-24 / RF-26 (S-48, S-51): cuentas de pago y categorías de caja del local. Idempotente (se puede
 * correr en producción con `php artisan db:seed --class=CajaSeeder`). "Efectivo" lo crea la migración.
 * Sin 'PRESTAMO': los préstamos a empleados van a la cuenta de empleados (RF-27, S-55).
 * No se incluye DCTO NEVERA ni nada que mezcle la SAS con cuentas personales: queda fuera del sistema.
 */
class CajaSeeder extends Seeder
{
    public const CUENTAS = [
        'Bancolombia' => 'banco',
        'Caja Social' => 'banco',
        'Datáfono' => 'datafono',
        'Nequi' => 'billetera',
        'Daviplata' => 'billetera',
        'Llave Dahianna' => 'billetera',
        'Llave Evelin' => 'billetera',
    ];

    public const CATEGORIAS = [
        'vario' => ['EMPRESA', 'PERSONAL', 'ENCOMIENDAS', 'COMISION', 'PROVEEDOR', 'DEVOLUCION', 'CONSIGNACION', 'TERCERO PEDRO'],
        'fijo' => [
            'Servicios públicos (energía empresa)', 'Servicios públicos (agua empresa)', 'Servicios públicos (gas empresa)',
            'Servicios públicos (Internet)', 'Plan celulares', 'Nómina empleados SAS', 'Nómina Lamus', 'Nómina Jhon',
            'Nómina Jhonnier', 'Nómina Juan Jose', 'Nómina Lili aseo', 'Nómina Don Jhon', 'Asesoría jurídica',
            'Asesoría contable SAS', 'SST Carlos', 'Practicante universitario', 'Planilla seguridad social empleados',
            'Planilla seguridad social practicante', 'Impuestos DIAN', 'Impuestos municipio',
        ],
        'financiero' => ['Banco Bogotá', 'Banco Caja Social', 'Banco Agrario', 'Bancoomeva'],
        'ingreso_externo' => ['CAJA GRAL', 'OTRO'],
        'otro_ingreso' => ['SERVICIO DE TORNO', 'MANTENIMIENTO DE ARRANQUE', 'OTROS'],
    ];

    public function run(): void
    {
        CuentaPago::firstOrCreate(['nombre' => 'Efectivo'], ['tipo' => 'efectivo']);

        foreach (self::CUENTAS as $nombre => $tipo) {
            CuentaPago::firstOrCreate(['nombre' => $nombre], ['tipo' => $tipo]);
        }

        foreach (self::CATEGORIAS as $grupo => $nombres) {
            foreach ($nombres as $nombre) {
                CategoriaCaja::firstOrCreate(['nombre' => $nombre, 'grupo' => $grupo]);
            }
        }
    }
}
