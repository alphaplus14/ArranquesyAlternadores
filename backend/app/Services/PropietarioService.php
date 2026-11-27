<?php

namespace App\Services;

use App\Models\Propietario;

/** S-88 (RF-24): alta y edición de dueños de billetera. Nunca se borran. */
class PropietarioService
{
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
