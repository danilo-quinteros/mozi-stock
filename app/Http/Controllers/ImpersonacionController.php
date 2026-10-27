<?php

namespace App\Http\Controllers;

use App\Domain\Auditoria\Auditor;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * «Entrar como» otro usuario para dar soporte: el superadmin entra a cualquier cuenta de
 * empresa y el administrador a los vendedores de su propia empresa (Gate «impersonar»).
 * La sesión guarda quién inició la impersonación; no se encadenan impersonaciones y cada
 * inicio y salida queda en auditoría.
 */
class ImpersonacionController extends Controller
{
    public const SESION = 'impersonador_id';

    public function __construct(private readonly Auditor $auditor) {}

    public function iniciar(Request $request, User $usuario): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        abort_if($request->session()->has(self::SESION), 403, __('Primero vuelve a tu cuenta.'));
        Gate::forUser($actor)->authorize('impersonar', $usuario);

        $this->auditor->registrar('usuario.impersonacion_iniciada', $usuario, $actor, $usuario->empresa_id);
        Auth::guard('web')->login($usuario);
        $request->session()->forget(User::SESION_SUCURSAL);
        $request->session()->put(self::SESION, $actor->id);

        return redirect()->route('dashboard');
    }

    public function salir(Request $request): RedirectResponse
    {
        $id = $request->session()->get(self::SESION);
        abort_unless(is_int($id), 403);

        /** @var User $suplantado */
        $suplantado = $request->user();
        $original = User::query()->find($id);
        $request->session()->forget([self::SESION, User::SESION_SUCURSAL]);

        if ($original === null || $original->motivoBloqueo() !== null) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login');
        }

        $this->auditor->registrar('usuario.impersonacion_finalizada', $suplantado, $original, $suplantado->empresa_id);
        Auth::guard('web')->login($original);

        return redirect()->route($original->esSuperadmin() ? 'admin.empresas' : 'usuarios.index');
    }
}
