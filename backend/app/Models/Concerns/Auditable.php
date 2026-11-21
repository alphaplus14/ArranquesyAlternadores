<?php

namespace App\Models\Concerns;

use App\Models\Auditoria;
use Illuminate\Support\Facades\Auth;

/**
 * RF-11: toda edición guarda tabla, registro, campo, valor anterior, valor nuevo, usuario y fecha.
 * Una fila por campo cambiado. Un borrado guarda campo "*" con la foto del registro.
 */
trait Auditable
{
    /** Campos que nunca se copian a la auditoría. */
    protected static array $auditoriaExcluidos = ['remember_token', 'created_at', 'updated_at'];

    /** Campos cuyo cambio se registra (quién y cuándo) sin copiar el valor ni su hash. */
    protected static array $auditoriaOcultos = ['password'];

    protected const VALOR_OCULTO = '••••';

    public static function bootAuditable(): void
    {
        static::updated(function ($modelo) {
            $filas = [];
            $ahora = now();

            foreach (array_keys($modelo->getChanges()) as $campo) {
                if (in_array($campo, static::$auditoriaExcluidos, true)) {
                    continue;
                }

                $oculto = in_array($campo, static::$auditoriaOcultos, true);

                // Ambos lados con el cast del modelo: decimal "85000.00", enum por su valor, bool 1/0.
                $filas[] = [
                    'tabla' => $modelo->getTable(),
                    'registro_id' => $modelo->getKey(),
                    'campo' => $campo,
                    'valor_anterior' => $oculto ? self::VALOR_OCULTO : static::valorAuditable($modelo->getOriginal($campo)),
                    'valor_nuevo' => $oculto ? self::VALOR_OCULTO : static::valorAuditable($modelo->getAttribute($campo)),
                    'usuario_id' => Auth::id(),
                    'created_at' => $ahora,
                ];
            }

            if ($filas) {
                Auditoria::insert($filas);
            }
        });

        static::deleted(function ($modelo) {
            Auditoria::insert([
                'tabla' => $modelo->getTable(),
                'registro_id' => $modelo->getKey(),
                'campo' => '*',
                'valor_anterior' => json_encode(
                    collect($modelo->getRawOriginal())->except([...static::$auditoriaExcluidos, ...static::$auditoriaOcultos]),
                    JSON_UNESCAPED_UNICODE
                ),
                'valor_nuevo' => null,
                'usuario_id' => Auth::id(),
                'created_at' => now(),
            ]);
        });
    }

    protected static function valorAuditable(mixed $valor): ?string
    {
        return match (true) {
            $valor === null => null,
            $valor instanceof \BackedEnum => (string) $valor->value,
            $valor instanceof \DateTimeInterface => $valor->format('Y-m-d H:i:s'),
            is_bool($valor) => $valor ? '1' : '0',
            is_scalar($valor) => (string) $valor,
            default => json_encode($valor, JSON_UNESCAPED_UNICODE),
        };
    }
}
