<?php

namespace App\Models\Concerns;

use App\Domain\Tenancy\TenantContext;
use App\Models\Empresa;
use App\Models\Scopes\EmpresaScope;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Datos de negocio de una empresa. empresa_id nunca es asignable masivamente:
 * se completa desde el contexto del servidor y no puede cambiar después de crearse.
 *
 * @property int $empresa_id
 */
trait BelongsToEmpresa
{
    public static function bootBelongsToEmpresa(): void
    {
        static::addGlobalScope(new EmpresaScope);

        static::creating(function (self $model): void {
            $contexto = app(TenantContext::class)->empresaId();
            $model->empresa_id ??= $contexto;

            if ($model->empresa_id === null || ($contexto !== null && (int) $model->empresa_id !== $contexto)) {
                throw new LogicException('El registro debe pertenecer a la empresa del usuario autenticado.');
            }
        });

        static::updating(function (self $model): void {
            if ($model->isDirty('empresa_id')) {
                throw new LogicException('La empresa de un registro no puede modificarse.');
            }
        });
    }

    /**
     * @return BelongsTo<Empresa, $this>
     */
    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }
}
