<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Csv;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CSV de usuarios de la empresa del administrador. Mismos datos y filtro que la pantalla.
 */
class ExportarUsuariosController extends Controller
{
    public function __invoke(Request $request): StreamedResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        Gate::forUser($actor)->authorize('viewAny', User::class);

        $usuarios = User::query()
            ->where('empresa_id', $actor->empresa_id)
            ->with('sucursal:id,nombre')
            ->buscar(trim((string) $request->query('buscar', '')))
            ->orderBy('name')
            ->lazy(500);

        return Csv::descargar('usuarios.csv', [__('Nombre'), __('Correo'), __('Rol'), __('Sucursal'), __('Estado')],
            function () use ($usuarios): iterable {
                foreach ($usuarios as $usuario) {
                    yield [
                        $usuario->name,
                        $usuario->email,
                        $usuario->rol?->etiqueta() ?? '',
                        $usuario->sucursal->nombre ?? __('Todas'),
                        $usuario->activo ? __('Activo') : __('Inactivo'),
                    ];
                }
            });
    }
}
