<?php

namespace App\Services;

use App\Enums\Rol;
use App\Models\Propietario;

/** S-88 (RF-24): alta y edición de dueños de billetera. Nunca se borran. */
class PropietarioService
{
    /**
     * Dueños para los selects. S-88: el mostrador no los ve (lista vacía); solo el admin pide inactivos.
     *
     * @return \Illuminate\Support\Collection<int, Propietario>
     */
    public function listar(Rol $rol, bool $incluirInactivos)
    {
        if ($rol === Rol::Mostrador) {
            return collect();
        }
        $todas = $incluirInactivos && $rol === Rol::Admin;

        return Propietario::query()
            ->when(! $todas, fn ($q) => $q->where('activo', true))
            ->orderBy('nombre')
            ->get(['id', 'nombre', 'activo']);
    }

    public function crear(array $datos): Propietario
    {
        return Propietario::create($datos);
    }

    public function actualizar(Propietario $propietario, array $datos): Propietario
    {
        $propietario->fill($datos);
        $propietario->save();

        return $propietario;
    }
}
