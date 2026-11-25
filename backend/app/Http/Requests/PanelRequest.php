<?php

namespace App\Http\Requests;

use App\Support\RangoFechas;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * RF-23 / S-45: filtros comunes del panel (días de Bogotá; por defecto los últimos 30 días hasta
 * hoy) y los parámetros propios de cada informe.
 */
class PanelRequest extends FormRequest
{
    public function rules(): array
    {
        $sm = config('panel.sin_movimiento');

        return [
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:desde'],
            'categoria_id' => ['nullable', 'integer', 'exists:categorias,id'],
            'proveedor_id' => ['nullable', 'integer', 'exists:proveedores,id'],
            'refrescar' => ['nullable', 'boolean'],
            'orden' => ['nullable', Rule::in(['unidades', 'valor', 'margen'])],
            'limite' => ['nullable', 'integer', 'min:1', 'max:'.config('panel.mas_vendidos.limite_maximo')],
            'dias' => ['nullable', 'integer', "between:{$sm['dias_minimo']},{$sm['dias_maximo']}"],
            'page' => ['nullable', 'integer', 'min:1'],
            'agrupar' => ['nullable', Rule::in(['categoria', 'proveedor'])],
        ];
    }

    public function messages(): array
    {
        return ['hasta.after_or_equal' => 'La fecha final no puede ser anterior a la inicial.'];
    }

    /** @return array{desde: string, hasta: string, categoria_id: ?int, proveedor_id: ?int} */
    public function filtros(): array
    {
        $hasta = $this->validated('hasta') ?? RangoFechas::hoy();
        $desde = $this->validated('desde') ?? Carbon::parse($hasta)->subDays(29)->toDateString();

        return [
            'desde' => $desde,
            'hasta' => $hasta,
            'categoria_id' => $this->validated('categoria_id') === null ? null : (int) $this->validated('categoria_id'),
            'proveedor_id' => $this->validated('proveedor_id') === null ? null : (int) $this->validated('proveedor_id'),
        ];
    }

    public function refrescar(): bool
    {
        return $this->boolean('refrescar');
    }
}
