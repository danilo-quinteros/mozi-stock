<?php

namespace App\Domain\CatalogoExterno\Actions;

use App\Domain\Auditoria\Auditor;
use App\Models\Empresa;
use App\Models\EmpresaProveedorExterno;
use App\Models\ProveedorExterno;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class VincularEmpresaProveedor
{
    public function __construct(private readonly Auditor $auditor) {}

    public function ejecutar(User $actor, Empresa $empresa, ProveedorExterno $proveedor, bool $activo): EmpresaProveedorExterno
    {
        Gate::forUser($actor)->authorize('cambiarAcceso', $empresa);

        return DB::transaction(function () use ($actor, $empresa, $proveedor, $activo) {
            $vinculo = EmpresaProveedorExterno::withoutGlobalScopes()
                ->where('empresa_id', $empresa->id)
                ->where('proveedor_externo_id', $proveedor->id)
                ->first();

            $antes = $vinculo ? ['activo' => $vinculo->activo] : null;

            if ($vinculo === null) {
                $vinculo = new EmpresaProveedorExterno([
                    'empresa_id' => $empresa->id,
                    'proveedor_externo_id' => $proveedor->id,
                    'activo' => $activo,
                    'vinculado_por' => $actor->id,
                ]);
                $vinculo->empresa_id = $empresa->id;
                $vinculo->save();
            } else {
                $vinculo->activo = $activo;
                $vinculo->save();
            }

            $accion = $activo ? 'empresa.proveedor_externo_vinculado' : 'empresa.proveedor_externo_desvinculado';
            $this->auditor->registrar(
                $accion,
                $vinculo,
                $actor,
                $empresa->id,
                $antes,
                ['activo' => $activo, 'proveedor' => $proveedor->codigo]
            );

            return $vinculo;
        });
    }
}
