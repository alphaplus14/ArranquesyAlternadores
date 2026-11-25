<?php

namespace App\Enums;

/**
 * RF-26 (S-51, S-52): grupo de una categoría de caja.
 * - vario (gasto), fijo (gasto fijo) y financiero (cuota bancaria): egresos, solo en efectivo.
 * - ingreso_externo (dinero que entra a la caja desde fuera, p. ej. CAJA GRAL): solo en efectivo y no
 *   es venta (no cuenta para la meta).
 * - otro_ingreso (torno, mantenimiento…): ingreso del negocio digitado a mano, en cualquier cuenta;
 *   sí cuenta en el total del día.
 */
enum GrupoCaja: string
{
    case Vario = 'vario';
    case Fijo = 'fijo';
    case Financiero = 'financiero';
    case IngresoExterno = 'ingreso_externo';
    case OtroIngreso = 'otro_ingreso';

    public function esEgreso(): bool
    {
        return in_array($this, [self::Vario, self::Fijo, self::Financiero], true);
    }

    /** S-52: todos los grupos salvo otro_ingreso solo admiten una cuenta de tipo efectivo. */
    public function soloEfectivo(): bool
    {
        return $this !== self::OtroIngreso;
    }

    public function nombre(): string
    {
        return match ($this) {
            self::Vario => 'Gasto',
            self::Fijo => 'Gasto fijo',
            self::Financiero => 'Cuota bancaria',
            self::IngresoExterno => 'Ingreso externo',
            self::OtroIngreso => 'Otro ingreso',
        };
    }

    /** @return list<string> */
    public static function valores(): array
    {
        return array_column(self::cases(), 'value');
    }
}
