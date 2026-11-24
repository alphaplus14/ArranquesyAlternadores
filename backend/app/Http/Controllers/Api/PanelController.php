<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PanelRequest;
use App\Services\PanelService;
use Illuminate\Http\JsonResponse;

/**
 * RF-23 / S-45: panel de gestión del administrador. Un endpoint por informe para que la pantalla
 * cargue por partes; cada uno en caché 5 minutos (`refrescar=1` la salta). Solo en pantalla.
 */
class PanelController extends Controller
{
    public function __construct(private readonly PanelService $panel) {}

    public function resumen(PanelRequest $request): JsonResponse
    {
        return $this->responder($request, 'resumen', [], fn ($f) => $this->panel->resumen($f));
    }

    public function ventasPorDia(PanelRequest $request): JsonResponse
    {
        return $this->responder($request, 'ventas-por-dia', [], fn ($f) => $this->panel->ventasPorDia($f));
    }

    public function masVendidos(PanelRequest $request): JsonResponse
    {
        $orden = $request->validated('orden') ?? 'unidades';
        $limite = (int) ($request->validated('limite') ?? config('panel.mas_vendidos.limite'));

        return $this->responder($request, 'mas-vendidos', [$orden, $limite], fn ($f) => $this->panel->masVendidos($f, $orden, $limite));
    }

    public function reabastecer(PanelRequest $request): JsonResponse
    {
        return $this->responder($request, 'reabastecer', [], fn ($f) => $this->panel->reabastecer($f));
    }

    public function sinMovimiento(PanelRequest $request): JsonResponse
    {
        // Sin `dias` se usa el valor configurado (S-45 e).
        $dias = (int) ($request->validated('dias') ?? $this->panel->diasSinMovimiento());
        $pagina = (int) ($request->validated('page') ?? 1);

        return $this->responder($request, 'sin-movimiento', [$dias, $pagina], fn ($f) => $this->panel->sinMovimiento($f, $dias, $pagina));
    }

    public function antiguedadStock(PanelRequest $request): JsonResponse
    {
        return $this->responder($request, 'antiguedad-stock', [], fn ($f) => $this->panel->antiguedadStock($f));
    }

    public function rotacion(PanelRequest $request): JsonResponse
    {
        $agrupar = $request->validated('agrupar') ?? 'categoria';

        return $this->responder($request, 'rotacion', [$agrupar], fn ($f) => $this->panel->rotacion($f, $agrupar));
    }

    public function control(PanelRequest $request): JsonResponse
    {
        return $this->responder($request, 'control', [], fn ($f) => $this->panel->control($f));
    }

    private function responder(PanelRequest $request, string $informe, array $extra, \Closure $calcular): JsonResponse
    {
        $filtros = $request->filtros();

        return response()->json($this->panel->enCache($informe, [$filtros, $extra], $request->refrescar(), fn () => $calcular($filtros)));
    }
}
