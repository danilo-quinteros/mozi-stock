<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Resultado del arqueo guardado al cerrar el turno: efectivo esperado (fondo + cobros en efectivo
 * − salidas en efectivo del turno) y diferencia (contado − esperado). Los reportes leen estos
 * valores en vez de recalcular cada turno. Los turnos ya cerrados se completan desde
 * movimientos_caja con la misma fórmula. El índice por fecha de apertura sirve a los reportes
 * de turnos por período.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('turnos_caja', function (Blueprint $table) {
            $table->decimal('efectivo_esperado', 18, 2)->nullable()->after('fecha_cierre');
            $table->decimal('diferencia', 18, 2)->nullable()->after('monto_cierre');
            $table->index(['empresa_id', 'fecha_apertura']);
            $table->index(['empresa_id', 'fecha_cierre']);
        });

        DB::statement(<<<'SQL'
            UPDATE turnos_caja t
            LEFT JOIN (
                SELECT turno_caja_id,
                    SUM(CASE WHEN metodo = 'efectivo' AND sentido = 'ingreso' THEN monto ELSE 0 END)
                  - SUM(CASE WHEN metodo = 'efectivo' AND sentido = 'egreso' THEN monto ELSE 0 END) AS neto
                FROM movimientos_caja
                WHERE turno_caja_id IS NOT NULL
                GROUP BY turno_caja_id
            ) m ON m.turno_caja_id = t.id
            SET t.efectivo_esperado = t.monto_apertura + COALESCE(m.neto, 0),
                t.diferencia = CASE WHEN t.monto_cierre IS NULL THEN NULL
                    ELSE t.monto_cierre - (t.monto_apertura + COALESCE(m.neto, 0)) END
            WHERE t.estado = 'cerrado'
            SQL);
    }

    public function down(): void
    {
        Schema::table('turnos_caja', function (Blueprint $table) {
            $table->dropIndex(['empresa_id', 'fecha_apertura']);
            $table->dropIndex(['empresa_id', 'fecha_cierre']);
            $table->dropColumn(['efectivo_esperado', 'diferencia']);
        });
    }
};
