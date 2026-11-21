<?php

namespace App\Services;

use App\Enums\EstadoIngreso;
use App\Enums\TipoIngreso;
use App\Exceptions\ReglaInventarioException;
use App\Models\Ingreso;
use App\Models\IngresoItem;
use App\Models\Proveedor;
use App\Models\User;
use App\Services\FacturaQr\DatosFacturaQr;
use App\Services\FacturaQr\FacturaQrService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * RF-05: ingreso por remisión, ítem por ítem, al mismo inventario.
 * RF-04 (S-06): factura de compra cuyo encabezado sale del QR; los ítems se digitan igual.
 * RF-09 / RF-10: descuento de la factura y líneas sin IVA.
 * RF-03: al confirmar se crean las unidades con su código de barras.
 */
class IngresoService
{
    public function __construct(
        private readonly InventarioService $inventario,
        private readonly CodigoBarrasService $codigos,
        private readonly FacturaQrService $qr,
    ) {}

    /** @param array{proveedor_id:int, numero_documento:string, fecha:string, descuento_total?:float} $datos */
    public function crearBorrador(array $datos, User $usuario): Ingreso
    {
        return Ingreso::create([
            ...$datos,
            'tipo' => TipoIngreso::Remision,
            'usuario_id' => $usuario->id,
        ])->refresh();
    }

    /**
     * RF-04: ubica la factura por el CUFE del QR.
     * - Ya en borrador: la reabre para seguir digitando ítems.
     * - Ya confirmada: la rechaza (un CUFE no entra dos veces).
     * - Nueva: crea el borrador factura_qr con el encabezado del QR. Lo que el QR no traiga
     *   (proveedor, número, fecha) se toma de $complemento; si falta, error de validación.
     *
     * @param  array{proveedor_id?:int|null, numero_documento?:string|null, fecha?:string|null}  $complemento
     */
    public function crearDesdeQr(string $contenido, array $complemento, User $usuario): Ingreso
    {
        $datos = $this->qr->interpretar($contenido);

        if ($existente = Ingreso::query()->where('cufe', $datos->cufe)->first()) {
            return $this->reabrirPorCufe($existente);
        }

        $encabezado = [
            'tipo' => TipoIngreso::FacturaQr,
            'cufe' => $datos->cufe,
            'proveedor_id' => $this->proveedorDelQr($datos) ?? ($complemento['proveedor_id'] ?? null),
            'numero_documento' => $datos->numero ?? ($complemento['numero_documento'] ?? null),
            'fecha' => $datos->fecha ?? ($complemento['fecha'] ?? null),
            'subtotal_qr' => $datos->subtotal,
            'iva_qr' => $datos->iva,
            'total_qr' => $datos->total,
            'usuario_id' => $usuario->id,
        ];

        $faltan = array_filter(['proveedor_id', 'numero_documento', 'fecha'], fn ($c) => blank($encabezado[$c]));
        if ($faltan) {
            throw ValidationException::withMessages(
                array_fill_keys($faltan, 'El QR no trae este dato; digítelo.'),
            );
        }

        try {
            return Ingreso::create($encabezado)->refresh();
        } catch (UniqueConstraintViolationException) {
            // Otro usuario escaneó la misma factura al mismo tiempo.
            return $this->reabrirPorCufe(Ingreso::query()->where('cufe', $datos->cufe)->firstOrFail());
        }
    }

    public function actualizarEncabezado(Ingreso $ingreso, array $datos): Ingreso
    {
        return $this->enBorrador($ingreso, function (Ingreso $bloqueado) use ($datos) {
            $bloqueado->update($datos);

            return $bloqueado;
        });
    }

    public function agregarItem(Ingreso $ingreso, array $datos): IngresoItem
    {
        return $this->enBorrador($ingreso, fn (Ingreso $bloqueado) => $bloqueado->items()->create($datos));
    }

    public function actualizarItem(Ingreso $ingreso, IngresoItem $item, array $datos): IngresoItem
    {
        return $this->enBorrador($ingreso, function () use ($item, $datos) {
            $item->update($datos);

            return $item;
        });
    }

    /** Solo en borrador: todavía no existen unidades ni movimientos de esta línea. */
    public function quitarItem(Ingreso $ingreso, IngresoItem $item): void
    {
        $this->enBorrador($ingreso, fn () => $item->delete());
    }

    /**
     * RF-05: confirma el ingreso, crea las unidades y suma existencia.
     * RF-04: en una factura_qr con total, la diferencia (digitado − QR) queda registrada siempre.
     * Si supera la tolerancia, solo se confirma aceptándola con un motivo (por ejemplo, qué no
     * llegó); quien confirma queda como quien la aceptó.
     */
    public function confirmar(Ingreso $ingreso, User $usuario, bool $aceptarDiferencia = false, ?string $motivo = null): Ingreso
    {
        return DB::transaction(function () use ($ingreso, $usuario, $aceptarDiferencia, $motivo) {
            $ingreso = Ingreso::query()->lockForUpdate()->findOrFail($ingreso->id);
            $this->exigirBorrador($ingreso);

            $items = $ingreso->items()->orderBy('id')->get();

            if ($items->isEmpty()) {
                throw new ReglaInventarioException('El ingreso no tiene ítems.', 422);
            }

            $ingreso->setRelation('items', $items);
            $diferencia = $ingreso->diferenciaQr();
            $aceptada = $this->exigirCuadreConQr($ingreso, $diferencia, $aceptarDiferencia, $motivo);

            $codigos = $this->codigos->reservar($items->sum('cantidad'));
            $this->inventario->darEntrada($ingreso, $items, $codigos, $usuario->id);

            $ingreso->estado = EstadoIngreso::Confirmado;
            $ingreso->confirmado_at = now();
            $ingreso->confirmado_por = $usuario->id;
            $ingreso->diferencia_qr = $diferencia;
            $ingreso->diferencia_aceptada = $aceptada;
            $ingreso->diferencia_motivo = $aceptada ? trim((string) $motivo) : null;
            $ingreso->save();

            return $ingreso;
        });
    }

    /**
     * Ediciones del borrador con la fila del ingreso bloqueada: así no se cuela un ítem mientras
     * otro usuario lo confirma (quedaría un ítem sin unidades y la diferencia mal registrada).
     *
     * @template T
     *
     * @param  callable(Ingreso): T  $cambio
     * @return T
     */
    private function enBorrador(Ingreso $ingreso, callable $cambio): mixed
    {
        return DB::transaction(function () use ($ingreso, $cambio) {
            $bloqueado = Ingreso::query()->lockForUpdate()->findOrFail($ingreso->id);
            $this->exigirBorrador($bloqueado);

            return $cambio($bloqueado);
        });
    }

    private function reabrirPorCufe(Ingreso $ingreso): Ingreso
    {
        if (! $ingreso->esBorrador()) {
            $cuando = $ingreso->confirmado_at?->format('Y-m-d H:i');
            throw new ReglaInventarioException(
                "Esta factura ({$ingreso->numero_documento}) ya se ingresó el {$cuando} (ingreso #{$ingreso->id}). Un CUFE no se ingresa dos veces.",
            );
        }

        return $ingreso;
    }

    /** NIT del emisor → proveedor. Si el QR trae NIT y no hay proveedor con él, no se crea solo. */
    private function proveedorDelQr(DatosFacturaQr $datos): ?int
    {
        if ($datos->nitEmisor === null) {
            return null;
        }

        // proveedores.nit puede estar guardado con o sin dígito de verificación; ambos usan el índice único.
        $proveedor = Proveedor::query()
            ->where('nit', $datos->nitEmisor)
            ->orWhere('nit', 'like', $datos->nitEmisor.'-%')
            ->first(['id']);

        if ($proveedor === null) {
            throw new ReglaInventarioException(
                "No hay un proveedor con NIT {$datos->nitEmisor}. Pida al administrador que lo cree (o le agregue ese NIT) y vuelva a escanear.",
                422,
            );
        }

        return $proveedor->id;
    }

    /**
     * RF-04: true si la diferencia supera la tolerancia y se aceptó con motivo; false si cuadra
     * (o no hay total del QR). Sin aceptación o sin motivo, no se confirma.
     */
    private function exigirCuadreConQr(Ingreso $ingreso, ?float $diferencia, bool $aceptarDiferencia, ?string $motivo): bool
    {
        if ($diferencia === null || abs($diferencia) <= (float) config('ingresos.tolerancia_total')) {
            return false;
        }

        if (! $aceptarDiferencia) {
            $digitado = number_format($ingreso->totalCalculado(), 0, ',', '.');
            $qr = number_format((float) $ingreso->total_qr, 0, ',', '.');
            throw new ReglaInventarioException(
                "El total digitado (\${$digitado}) no cuadra con el total de la factura según el QR (\${$qr}). Revise los ítems o acepte la diferencia.",
                422,
            );
        }

        if (blank($motivo)) {
            throw ValidationException::withMessages([
                'diferencia_motivo' => 'Para aceptar la diferencia con el QR escriba el motivo (por ejemplo, qué no llegó).',
            ]);
        }

        return true;
    }

    private function exigirBorrador(Ingreso $ingreso): void
    {
        if (! $ingreso->esBorrador()) {
            throw new ReglaInventarioException('El ingreso ya está confirmado y no se puede modificar.');
        }
    }
}
