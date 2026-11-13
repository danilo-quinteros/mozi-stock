<?php

namespace App\Domain\Tenancy;

use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Correlativo por empresa y serie (AJU-00001, TRA-00001...). Debe llamarse dentro de la
 * transacción de la operación: la fila queda bloqueada hasta el commit y un rollback
 * no consume números.
 *
 * Primero bloquea la fila existente con FOR UPDATE y solo la crea si la serie es nueva. Un
 * INSERT IGNORE sobre una fila que ya existe toma un bloqueo compartido; si después se pide el
 * exclusivo, dos operaciones simultáneas de la misma empresa se bloquean mutuamente (deadlock).
 */
final class Numerador
{
    public function siguiente(int $empresaId, string $serie): int
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('El numerador solo puede usarse dentro de una transacción.');
        }

        $ultimo = $this->bloquear($empresaId, $serie);

        if ($ultimo === null) {
            DB::table('numeradores')->insertOrIgnore(['empresa_id' => $empresaId, 'serie' => $serie, 'ultimo' => 0]);
            $ultimo = $this->bloquear($empresaId, $serie) ?? throw new LogicException("No se pudo crear el numerador {$serie}.");
        }

        DB::table('numeradores')
            ->where('empresa_id', $empresaId)
            ->where('serie', $serie)
            ->update(['ultimo' => $ultimo + 1]);

        return $ultimo + 1;
    }

    private function bloquear(int $empresaId, string $serie): ?int
    {
        $ultimo = DB::table('numeradores')
            ->where('empresa_id', $empresaId)
            ->where('serie', $serie)
            ->lockForUpdate()
            ->value('ultimo');

        return $ultimo === null ? null : (int) $ultimo;
    }
}
