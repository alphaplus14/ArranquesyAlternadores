<?php

namespace App\Enums;

/** Roles del sistema: un módulo por rol (RF-06, sección 4 del documento). */
enum Rol: string
{
    case Admin = 'admin';
    case Logistica = 'logistica';
    case Mostrador = 'mostrador';
    // RF-29: auxiliar contable, acotado a cartera, cuentas por pagar, pagos e informes.
    case Contable = 'contable';

    /**
     * S-71: ver el último costo de compra en la tabla de Inventario (campo `ultimo_costo` del
     * listado de referencias). El mostrador lo ve por decisión del dueño; para quitárselo basta
     * sacarlo de esta lista. Contable no tiene inventario (S-62). Gate `ver-ultimo-costo`.
     */
    public function puedeVerUltimoCosto(): bool
    {
        return in_array($this, [self::Admin, self::Logistica, self::Mostrador], true);
    }

    /** S-71: historial de costos de compra (pantalla, GET /api/costos y menú). Gate `ver-historial-costos`. */
    public function puedeVerHistorialCostos(): bool
    {
        return in_array($this, [self::Admin, self::Logistica], true);
    }

    /** @return list<string> */
    public static function valores(): array
    {
        return array_column(self::cases(), 'value');
    }
}
