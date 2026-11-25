<?php

namespace App\Http\Requests;

use App\Enums\MetodoCaptura;
use App\Services\VentaService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * RF-06 / RF-12: una lectura en la venta. La comparten POST /ventas/{venta}/escanear y, S-46,
 * POST /ventas (la venta nace con su primer escaneo): las mismas reglas en un solo lugar.
 */
class EscanearVentaRequest extends FormRequest
{
    /** @return array<string, array<int, mixed>> */
    public static function reglasEscaneo(): array
    {
        return [
            'codigo_barras' => ['required', 'string', 'max:32'],
            'metodo_captura' => ['required', Rule::enum(MetodoCaptura::class)],
            // S-31: para resolver un código de proveedor que está en varias referencias.
            'referencia_id' => ['nullable', 'integer'],
            // S-40: unidades por lectura con el código del proveedor (con una etiqueta, 1).
            'cantidad' => ['nullable', 'integer', 'min:1', 'max:'.VentaService::MAX_CANTIDAD],
        ];
    }

    /** @return array<string, string> */
    public static function mensajesEscaneo(): array
    {
        $rango = 'La cantidad debe estar entre 1 y '.VentaService::MAX_CANTIDAD.'.';

        return [
            'codigo_barras.required' => 'Escanee o digite un código para empezar la venta.',
            'cantidad.min' => $rango,
            'cantidad.max' => $rango,
            'cantidad.integer' => 'La cantidad debe ser un número entero.',
        ];
    }

    public function rules(): array
    {
        return self::reglasEscaneo();
    }

    public function messages(): array
    {
        return self::mensajesEscaneo();
    }

    public function codigo(): string
    {
        return $this->validated('codigo_barras');
    }

    public function metodo(): MetodoCaptura
    {
        return MetodoCaptura::from($this->validated('metodo_captura'));
    }

    public function referenciaId(): ?int
    {
        $id = $this->validated('referencia_id');

        return $id === null ? null : (int) $id;
    }

    public function cantidad(): int
    {
        return (int) ($this->validated('cantidad') ?? 1);
    }
}
