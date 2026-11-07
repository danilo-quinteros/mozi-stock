<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Con explicit_defaults_for_timestamp=OFF (MariaDB 10.4 y MySQL 5.7 por defecto), el primer
 * $table->timestamp('x') NOT NULL de una tabla recibe "ON UPDATE CURRENT_TIMESTAMP": cualquier
 * UPDATE de la fila reescribe la fecha. Así, cerrar un turno cambiaba su fecha de apertura y
 * actualizar un cobro movía su fecha real de cobro. Se quita solo el ON UPDATE; los valores
 * guardados y el DEFAULT de inserción no cambian. En MySQL 8 estas columnas ya no lo tienen y
 * la migración no hace nada.
 *
 * Para columnas nuevas: usar ->useCurrent() o ->nullable(), nunca timestamp() a secas.
 */
return new class extends Migration
{
    /** @var array<string, string> */
    private const COLUMNAS = [
        'turnos_caja' => 'fecha_apertura',
        'turno_usuarios' => 'incorporado_at',
        'cobros' => 'fecha_cobro',
        'pagos_cuenta_pagar' => 'fecha_pago',
        'siat_cufd' => 'vence_en',
        'siat_cuis' => 'vence_en',
        'producto_vinculos_externos' => 'sincronizado_en',
        'agente_conversaciones' => 'ultima_actividad_en',
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql' && DB::getDriverName() !== 'mariadb') {
            return;
        }

        foreach (self::COLUMNAS as $tabla => $columna) {
            $autoActualiza = DB::table('information_schema.COLUMNS')
                ->where('TABLE_SCHEMA', DB::getDatabaseName())
                ->where('TABLE_NAME', $tabla)
                ->where('COLUMN_NAME', $columna)
                ->where('EXTRA', 'like', '%on update%')
                ->exists();

            if ($autoActualiza) {
                DB::statement("ALTER TABLE `{$tabla}` MODIFY `{$columna}` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP");
            }
        }
    }

    public function down(): void
    {
        // No se restaura el ON UPDATE: era un defecto que reescribía fechas de negocio.
    }
};
