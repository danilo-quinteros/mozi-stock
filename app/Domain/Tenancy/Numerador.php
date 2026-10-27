<?php

namespace App\Domain\Tenancy;

use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Correlativo por empresa y serie (AJU-00001, TRA-00001...). Debe llamarse dentro de la
 * transacción de la operación: la fila queda bloqueada hasta el commit y un rollback
 * no consume números.
 */
final class Numerador
{
    public function siguiente(int $empresaId, string $serie): int
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('El numerador solo puede usarse dentro de una transacción.');
        }

        DB::table('numeradores')->insertOrIgnore(['empresa_id' => $empresaId, 'serie' => $serie, 'ultimo' => 0]);
        $ultimo = (int) DB::table('numeradores')
            ->where('empresa_id', $empresaId)
            ->where('serie', $serie)
            ->lockForUpdate()
            ->value('ultimo');

        DB::table('numeradores')
            ->where('empresa_id', $empresaId)
            ->where('serie', $serie)
            ->update(['ultimo' => $ultimo + 1]);

        return $ultimo + 1;
    }
}
