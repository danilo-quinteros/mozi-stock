<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Anulación por el administrador (decisión del propietario, 31/10/2026): cobros, pagos de
 * cuentas por pagar, cuentas por cobrar manuales y obligaciones manuales nunca se borran; quedan
 * en su historial como anulados, con quién, cuándo y por qué. El dinero se compensa con una
 * contrapartida en movimientos_caja en la fecha de la anulación (origen anulacion_cobro o
 * anulacion_pago_cxp); los días y arqueos anteriores no cambian.
 *
 * También se quita el tipo de las obligaciones manuales (decisión del propietario, 31/10/2026):
 * el concepto ya describe la deuda y la clasificación de gastos vive en Egresos.
 */
return new class extends Migration
{
    private const TABLAS = ['cobros', 'pagos_cuenta_pagar', 'cuentas_cobrar', 'cuentas_pagar'];

    public function up(): void
    {
        foreach (self::TABLAS as $tabla) {
            Schema::table($tabla, function (Blueprint $table) {
                $table->timestamp('anulado_at')->nullable();
                $table->foreignId('anulado_por')->nullable()->constrained('users')->restrictOnDelete();
                $table->string('motivo_anulacion', 200)->nullable();
            });
            DB::statement("ALTER TABLE {$tabla} ADD CONSTRAINT {$tabla}_anulacion_check CHECK ((anulado_at IS NULL AND anulado_por IS NULL AND motivo_anulacion IS NULL) OR (anulado_at IS NOT NULL AND anulado_por IS NOT NULL AND motivo_anulacion IS NOT NULL))");
        }

        DB::statement('ALTER TABLE cuentas_pagar DROP CONSTRAINT cuentas_pagar_check');
        Schema::table('cuentas_pagar', function (Blueprint $table) {
            $table->dropColumn('tipo');
        });
        // Solo una obligación manual se anula; las de compras y egresos siguen a su documento.
        DB::statement("ALTER TABLE cuentas_pagar ADD CONSTRAINT cuentas_pagar_check CHECK (total_documento > 0 AND pagado_origen >= 0 AND saldo_pendiente >= 0 AND pagado_origen + saldo_pendiente <= total_documento AND ((origen = 'compra' AND compra_id IS NOT NULL AND egreso_id IS NULL) OR (origen = 'egreso' AND egreso_id IS NOT NULL AND compra_id IS NULL) OR (origen = 'manual' AND compra_id IS NULL AND egreso_id IS NULL)) AND (anulado_at IS NULL OR origen = 'manual'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE cuentas_pagar DROP CONSTRAINT cuentas_pagar_check');
        Schema::table('cuentas_pagar', function (Blueprint $table) {
            $table->string('tipo', 12)->nullable()->after('concepto');
        });
        DB::table('cuentas_pagar')->where('origen', 'manual')->update(['tipo' => 'otro']);
        DB::statement("ALTER TABLE cuentas_pagar ADD CONSTRAINT cuentas_pagar_check CHECK (total_documento > 0 AND pagado_origen >= 0 AND saldo_pendiente >= 0 AND pagado_origen + saldo_pendiente <= total_documento AND ((origen = 'compra' AND compra_id IS NOT NULL AND egreso_id IS NULL) OR (origen = 'egreso' AND egreso_id IS NOT NULL AND compra_id IS NULL) OR (origen = 'manual' AND compra_id IS NULL AND egreso_id IS NULL AND tipo IN ('proveedor', 'prestamo', 'impuesto', 'servicio', 'otro'))))");

        foreach (self::TABLAS as $tabla) {
            DB::statement("ALTER TABLE {$tabla} DROP CONSTRAINT {$tabla}_anulacion_check");
            Schema::table($tabla, function (Blueprint $table) {
                $table->dropConstrainedForeignId('anulado_por');
                $table->dropColumn(['anulado_at', 'motivo_anulacion']);
            });
        }
    }
};
