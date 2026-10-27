<?php

namespace App\Http\Controllers;

use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Uri;
use Illuminate\Validation\ValidationException;

/**
 * Sucursal de trabajo del administrador, elegida en el menú lateral y guardada en su sesión.
 * Solo filtra lo que ve; no amplía permisos. El vendedor no puede cambiarla nunca.
 */
class SucursalActivaController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        /** @var User $usuario */
        $usuario = $request->user();
        abort_unless($usuario->esAdministrador(), 403);

        $valor = (string) $request->input('sucursal_id', '');

        if ($valor === '') {
            $request->session()->forget(User::SESION_SUCURSAL);
        } else {
            $existe = ctype_digit($valor) && Sucursal::query()->whereKey((int) $valor)
                ->where('empresa_id', $usuario->empresa_id)->where('activa', true)->exists();

            if (! $existe) {
                throw ValidationException::withMessages(['sucursal_id' => __('Selecciona una sucursal activa de tu empresa.')]);
            }

            $request->session()->put(User::SESION_SUCURSAL, (int) $valor);
        }

        // Se vuelve a la misma pantalla sin el filtro de sucursal anterior en la URL.
        $destino = Uri::of(url()->previous(route('dashboard')));

        return $destino->host() === $request->getHost()
            ? redirect()->to((string) $destino->withoutQuery(['sucursal', 'page']))
            : redirect()->route('dashboard');
    }
}
