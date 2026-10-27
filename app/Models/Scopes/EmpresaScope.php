<?php

namespace App\Models\Scopes;

use App\Domain\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Filtra por la empresa del usuario autenticado. Sin contexto de empresa (invitado, superadmin,
 * consola) falla cerrado y no devuelve filas: los procesos globales deben omitirlo de forma explícita.
 *
 * @template TModel of Model
 *
 * @implements Scope<TModel>
 */
class EmpresaScope implements Scope
{
    /**
     * @param  Builder<covariant TModel>  $builder
     * @param  TModel  $model
     */
    public function apply(Builder $builder, Model $model): void
    {
        $empresaId = app(TenantContext::class)->empresaId();

        if ($empresaId === null) {
            $builder->whereRaw('1 = 0');

            return;
        }

        $builder->where($model->qualifyColumn('empresa_id'), $empresaId);
    }
}
