<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CuentaRequest;
use App\Services\UsuarioService;
use Illuminate\Http\JsonResponse;

/** Cuenta propia del admin: nombre, usuario y contraseña. */
class CuentaController extends Controller
{
    public function update(CuentaRequest $request, UsuarioService $usuarios): JsonResponse
    {
        $u = $usuarios->actualizarCuenta($request->user(), $request->validated());

        return response()->json(['id' => $u->id, 'name' => $u->name, 'usuario' => $u->usuario, 'rol' => $u->rol]);
    }
}
