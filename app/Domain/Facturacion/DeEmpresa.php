<?php

namespace App\Domain\Facturacion;

use App\Models\Scopes\EmpresaScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Consultas de facturación con la empresa indicada de forma explícita. Los servicios SIAT también
 * corren en jobs y en el panel global, donde EmpresaScope no tiene contexto y falla cerrado. Quien
 * llama ya resolvió la empresa en el servidor: usuario autenticado, superadmin o job, nunca la petición.
 */
final class DeEmpresa
{
    /**
     * @template TModelo of Model
     *
     * @param  class-string<TModelo>  $modelo
     * @return Builder<TModelo>
     */
    public static function consulta(string $modelo, int $empresaId): Builder
    {
        $consulta = $modelo::query()->withoutGlobalScope(EmpresaScope::class);

        return $consulta->where($consulta->getModel()->qualifyColumn('empresa_id'), $empresaId);
    }
}
