<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Filtro por días del negocio (RF-21; reutilizable en RF-18). Los timestamps se guardan en la zona
 * de la app (UTC), pero "desde" y "hasta" son días en la hora local del local
 * (config app.zona_local, America/Bogota). Un ajuste hecho a las 8 p. m. del 20 en Bogotá es del
 * 20, aunque en UTC ya sea el 21.
 *
 * Devuelve límites en la zona de la app para comparar la columna tal cual (índice utilizable,
 * sin DATE() sobre ella): created_at >= inicio y created_at < fin. "hasta" es inclusivo del día
 * completo: fin = hasta + 1 día a las 00:00 locales.
 */
final class RangoFechas
{
    /** Inicio del día local (aaaa-mm-dd), expresado en la zona de la app. */
    public static function inicio(string $dia): Carbon
    {
        return Carbon::createFromFormat('Y-m-d', $dia, self::zonaLocal())
            ->startOfDay()
            ->setTimezone(config('app.timezone'));
    }

    /** Primer instante del día local siguiente (límite exclusivo), en la zona de la app. */
    public static function finExclusivo(string $dia): Carbon
    {
        return Carbon::createFromFormat('Y-m-d', $dia, self::zonaLocal())
            ->startOfDay()
            ->addDay()
            ->setTimezone(config('app.timezone'));
    }

    private static function zonaLocal(): string
    {
        return config('app.zona_local', 'America/Bogota');
    }
}
