<?php

namespace App\Livewire\Facturacion\Concerns;

use App\Domain\Identidad\Impersonacion;
use App\Models\Empresa;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;

/**
 * Empresa con la que trabaja una sección de Facturación. El ID viene del panel (nunca del navegador:
 * es #[Locked]) y en cada petición se vuelve a autorizar con el Gate, que exige al administrador que
 * sea de esa misma empresa. Los datos se leen con DeEmpresa: el panel global no tiene contexto tenant.
 */
trait EmpresaDeFacturacion
{
    #[Locked]
    public int $empresaId;

    protected function empresa(): Empresa
    {
        $empresa = Empresa::query()->findOrFail($this->empresaId);
        Gate::forUser($this->actor())->authorize('facturacion-siat', $empresa);

        return $empresa;
    }

    /** Datos fiscales, token, sucursales y puntos: bloqueados durante «Entrar como» (decisión D6). */
    protected function puedeConfigurar(Empresa $empresa): bool
    {
        return Gate::forUser($this->actor())->allows('configurar-facturacion', $empresa);
    }

    protected function impersonando(): bool
    {
        return Impersonacion::activa();
    }

    protected function actor(): User
    {
        /** @var User */
        return Auth::user();
    }
}
