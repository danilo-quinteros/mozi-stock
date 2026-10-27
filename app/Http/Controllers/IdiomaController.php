<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Uri;
use Illuminate\Validation\Rule;

/**
 * Cambia el idioma de la interfaz. Se guarda en la sesión y, si hay usuario, en su perfil.
 * Es solo una preferencia visual: no cambia permisos, importes ni datos de la empresa.
 */
class IdiomaController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'idioma' => ['required', 'string', Rule::in(array_keys(config('mozistock.idiomas')))],
        ]);

        $request->session()->put(User::SESION_IDIOMA, $datos['idioma']);

        $usuario = $request->user();
        if ($usuario instanceof User && $usuario->idioma !== $datos['idioma']) {
            $usuario->forceFill(['idioma' => $datos['idioma']])->save();
        }

        $destino = Uri::of(url()->previous(route('home')));

        return $destino->host() === $request->getHost()
            ? redirect()->to((string) $destino)
            : redirect()->route('home');
    }
}
