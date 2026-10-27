<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Con explicit_defaults_for_timestamp desactivado (MariaDB de XAMPP), la primera columna
     * TIMESTAMP NOT NULL de una tabla recibe ON UPDATE CURRENT_TIMESTAMP: registrar un abono
     * reescribía la fecha de la venta. Las fechas de venta, cobro y caja pasan a DATETIME,
     * que nunca se modifica sola. Los valores se conservan (UTC escrito por la aplicación).
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE ventas MODIFY fecha DATETIME NOT NULL');
        DB::statement('ALTER TABLE pagos_venta MODIFY fecha_cobro DATETIME NOT NULL');
        DB::statement('ALTER TABLE movimientos_caja MODIFY fecha_operacion DATETIME NOT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE ventas MODIFY fecha TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
        DB::statement('ALTER TABLE pagos_venta MODIFY fecha_cobro TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
        DB::statement('ALTER TABLE movimientos_caja MODIFY fecha_operacion TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
    }
};
