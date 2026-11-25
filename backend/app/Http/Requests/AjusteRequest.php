<?php

namespace App\Http\Requests;

use App\Enums\MetodoCaptura;
use App\Enums\MotivoAjuste;
use App\Enums\OrigenCodigo;
use App\Enums\TipoAjuste;
use App\Services\AjusteService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * RF-21 (S-27): lote de una salida (baja) o de un reintegro. El tipo lo da la ruta.
 * RF-12: cada código trae su método de captura.
 */
class AjusteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function tipo(): TipoAjuste
    {
        return $this->routeIs('ajustes.reintegros') ? TipoAjuste::Reintegro : TipoAjuste::Baja;
    }

    public function rules(): array
    {
        return [
            'motivo' => ['required', Rule::in(MotivoAjuste::valoresPara($this->tipo()))],
            'nota' => ['nullable', 'string', 'max:500', Rule::requiredIf($this->input('motivo') === MotivoAjuste::Otro->value)],
            'codigos' => ['required', 'array', 'min:1', 'max:'.AjusteService::MAX_UNIDADES],
            // S-34: el mismo código de proveedor puede repetirse (cada lectura es otra unidad); las
            // etiquetas repetidas las rechaza AjusteService.
            'codigos.*.codigo' => ['required', 'string', 'max:32'],
            'codigos.*.metodo_captura' => ['required', Rule::enum(MetodoCaptura::class)],
            'codigos.*.origen_codigo' => ['nullable', Rule::enum(OrigenCodigo::class)],
            'codigos.*.unidad_codigo' => ['nullable', 'string', 'max:32'],
        ];
    }

    public function messages(): array
    {
        return [
            'motivo.in' => 'El motivo no corresponde a este tipo de ajuste.',
            'nota.required' => 'Escriba una nota: el motivo "Otro" la exige.',
            'codigos.required' => 'El lote no tiene unidades.',
        ];
    }

    public function motivo(): MotivoAjuste
    {
        return MotivoAjuste::from($this->validated('motivo'));
    }
}
