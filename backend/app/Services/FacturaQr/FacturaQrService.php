<?php

namespace App\Services\FacturaQr;

use App\Exceptions\ReglaInventarioException;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * RF-04 (S-06): lee el contenido del QR de una factura electrónica de compra. No toca la base.
 *
 * Formatos aceptados (provisional, falta probar con una factura real):
 *  1. Pares "Clave: valor" del anexo técnico DIAN (NumFac, FecFac, NitFac, ValFac, ValIva,
 *     ValTolFac, CUFE, QRCode…), separados por saltos de línea o todo en una línea.
 *  2. Solo la URL de consulta de la DIAN con ?documentkey=<CUFE>.
 *  3. Solo el CUFE.
 */
class FacturaQrService
{
    private const CLAVES = ['NumFac', 'FecFac', 'HorFac', 'NitFac', 'DocAdq', 'ValFac', 'ValIva', 'ValOtroIm', 'ValTolFac', 'CUFE', 'CUDE', 'QRCode'];

    /** CUFE = SHA-384 en hexadecimal. */
    private const PATRON_CUFE = '/^[0-9a-f]{96}$/i';

    public function interpretar(string $contenido): DatosFacturaQr
    {
        $contenido = trim($contenido);
        $campos = $this->pares($contenido);

        $cufe = $this->cufe($campos, $contenido);
        if ($cufe === null) {
            throw new ReglaInventarioException('El QR leído no trae un CUFE válido. Vuelva a escanearlo o digite los datos de la factura.', 422);
        }

        return new DatosFacturaQr(
            cufe: $cufe,
            numero: $this->texto($campos['numfac'] ?? null, 60),
            fecha: $this->fecha($campos['fecfac'] ?? null),
            nitEmisor: $this->nit($campos['nitfac'] ?? null),
            subtotal: $this->numero($campos['valfac'] ?? null),
            iva: $this->numero($campos['valiva'] ?? null),
            total: $this->numero($campos['valtolfac'] ?? null),
        );
    }

    /** NIT sin dígito de verificación ni separadores, para buscarlo en proveedores.nit. */
    public function nit(?string $valor): ?string
    {
        if ($valor === null) {
            return null;
        }
        $sinDv = preg_replace('/-\s*\d$/', '', trim($valor));
        $digitos = preg_replace('/\D/', '', (string) $sinDv);

        return $digitos === '' ? null : $digitos;
    }

    /** @return array<string, string> claves en minúscula */
    private function pares(string $contenido): array
    {
        $claves = implode('|', self::CLAVES);
        // El valor va hasta la próxima clave conocida, con o sin salto de línea en medio.
        // Sin \b antes de la clave: al pegar en un input, el navegador quita los saltos de línea
        // y las claves quedan pegadas al valor anterior ("FE-1234FecFac: …").
        $patron = '/('.$claves.')\s*[:=]\s*(.*?)(?=\s*(?:'.$claves.')\s*[:=]|\z)/is';
        preg_match_all($patron, $contenido, $m, PREG_SET_ORDER);

        $campos = [];
        foreach ($m as [, $clave, $valor]) {
            $campos[strtolower($clave)] = trim($valor);
        }

        return $campos;
    }

    /** @param array<string, string> $campos */
    private function cufe(array $campos, string $contenido): ?string
    {
        $candidatos = [$campos['cufe'] ?? null, $campos['cude'] ?? null];

        if (preg_match('/documentkey=([0-9a-f]{96})/i', $contenido, $m)) {
            $candidatos[] = $m[1];
        }
        $candidatos[] = $contenido;

        foreach ($candidatos as $candidato) {
            if ($candidato !== null && preg_match(self::PATRON_CUFE, trim($candidato))) {
                return strtolower(trim($candidato));
            }
        }

        return null;
    }

    private function texto(?string $valor, int $max): ?string
    {
        $valor = $valor === null ? '' : trim($valor);

        return $valor === '' ? null : mb_substr($valor, 0, $max);
    }

    private function fecha(?string $valor): ?string
    {
        if ($valor === null || trim($valor) === '') {
            return null;
        }
        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'Y/m/d'] as $formato) {
            try {
                $fecha = CarbonImmutable::createFromFormat("!$formato", trim($valor));
                if ($fecha !== false && $fecha->format($formato) === trim($valor)) {
                    return $fecha->toDateString();
                }
            } catch (Throwable) {
                // probar el siguiente formato
            }
        }

        return null;
    }

    /** "1500000.00", "1.500.000,00", "$ 1,500,000.00" → 1500000.0 */
    private function numero(?string $valor): ?float
    {
        if ($valor === null) {
            return null;
        }
        $limpio = preg_replace('/[^\d.,]/', '', $valor);
        if ($limpio === '' || $limpio === null) {
            return null;
        }

        $ultimaComa = strrpos($limpio, ',');
        $ultimoPunto = strrpos($limpio, '.');
        $decimal = match (true) {
            $ultimaComa !== false && $ultimoPunto !== false => $ultimaComa > $ultimoPunto ? ',' : '.',
            $ultimaComa !== false => strlen($limpio) - $ultimaComa - 1 === 3 ? null : ',',
            // Un solo punto es decimal (formato DIAN); varios puntos son miles.
            $ultimoPunto !== false => substr_count($limpio, '.') > 1 ? null : '.',
            default => null,
        };

        $miles = $decimal === ',' ? '.' : ($decimal === '.' ? ',' : null);
        if ($miles !== null) {
            $limpio = str_replace($miles, '', $limpio);
        } else {
            $limpio = str_replace([',', '.'], '', $limpio);
        }

        return round((float) str_replace(',', '.', $limpio), 2);
    }
}
