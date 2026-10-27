<?php

namespace App\Domain\Facturacion\Actions;

use App\Domain\Auditoria\Auditor;
use App\Models\Empresa;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * El superadmin habilita o deshabilita la facturación SIAT de una empresa (decisión D6). El switch
 * no equivale a la autorización del SIN; deshabilitar no borra datos fiscales ni códigos.
 */
final class CambiarFacturacionEmpresa
{
    public function __construct(private readonly Auditor $auditor) {}

    public function ejecutar(User $actor, Empresa $empresa, bool $habilitada): Empresa
    {
        Gate::forUser($actor)->authorize('habilitarFacturacion', $empresa);

        return DB::transaction(function () use ($actor, $empresa, $habilitada): Empresa {
            $bloqueada = Empresa::query()->whereKey($empresa->id)->lockForUpdate()->firstOrFail();

            if ($bloqueada->siat_habilitado === $habilitada) {
                return $bloqueada;
            }

            $bloqueada->siat_habilitado = $habilitada;
            $bloqueada->save();
            $this->auditor->registrar($habilitada ? 'empresa.siat_habilitado' : 'empresa.siat_deshabilitado', $bloqueada, $actor,
                $bloqueada->id, ['siat_habilitado' => ! $habilitada], ['siat_habilitado' => $habilitada]);

            return $bloqueada;
        });
    }
}
