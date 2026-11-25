<?php

namespace App\Enums;

/**
 * RF-21 (S-27): por qué se saca o se devuelve una unidad. "Garantía" es solo un motivo de salida:
 * no hay seguimiento de reclamos ni reposición. `otro` exige nota.
 */
enum MotivoAjuste: string
{
    // Baja
    case Garantia = 'garantia';
    case DevolucionProveedor = 'devolucion_proveedor';
    case Dano = 'dano';
    case Perdida = 'perdida';
    case FaltanteInventario = 'faltante_inventario'; // S-39
    // Reintegro
    case ErrorDeRegistro = 'error_de_registro';
    // Alta (S-39)
    case SobranteInventario = 'sobrante_inventario';
    case SinFactura = 'sin_factura';
    // Ambos
    case Otro = 'otro';

    /** @return list<self> */
    public static function para(TipoAjuste $tipo): array
    {
        return match ($tipo) {
            TipoAjuste::Baja => [self::Garantia, self::DevolucionProveedor, self::Dano, self::Perdida, self::FaltanteInventario, self::Otro],
            TipoAjuste::Reintegro => [self::ErrorDeRegistro, self::Otro],
            TipoAjuste::Alta => [self::SobranteInventario, self::SinFactura, self::Otro],
        };
    }

    /** @return list<string> */
    public static function valoresPara(TipoAjuste $tipo): array
    {
        return array_map(fn (self $m) => $m->value, self::para($tipo));
    }

    public function exigeNota(): bool
    {
        return $this === self::Otro;
    }
}
