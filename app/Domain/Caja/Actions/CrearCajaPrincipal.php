<?php

namespace App\Domain\Caja\Actions;

use App\Domain\Auditoria\Auditor;
use App\Models\Caja;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Creación automática e idempotente de la Caja principal para una sucursal.
 */
final class CrearCajaPrincipal
{
    public function __construct(private readonly Auditor $auditor) {}

    public function ejecutar(User $actor, Sucursal $sucursal): Caja
    {
        return DB::transaction(function () use ($actor, $sucursal): Caja {
            $existente = Caja::withoutGlobalScopes()
                ->where('empresa_id', $sucursal->empresa_id)
                ->where('sucursal_id', $sucursal->id)
                ->where('es_principal', true)
                ->lockForUpdate()
                ->first();

            if ($existente !== null) {
                return $existente;
            }

            $caja = new Caja;
            $caja->empresa_id = $sucursal->empresa_id;
            $caja->sucursal_id = $sucursal->id;
            $caja->nombre = 'Caja principal';
            $caja->codigo = 'CP-'.$sucursal->codigo;
            $caja->modalidad = 'compartida';
            $caja->activa = true;
            $caja->es_principal = true;
            $caja->save();

            $this->auditor->registrar('caja.creada', $caja, $actor, $caja->empresa_id,
                despues: $caja->only(['nombre', 'codigo', 'modalidad', 'activa', 'es_principal', 'sucursal_id']));

            return $caja;
        });
    }
}
