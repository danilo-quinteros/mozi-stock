<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Extras de la suscripción (decisión del propietario, 13/11/2026): cada plan incluye cierta
 * cantidad de sucursales y usuarios; la empresa suma sucursales extra y paquetes de usuarios.
 * La mensualidad es plan + extras. Agregar extras a mitad del período se paga en ese momento
 * (proporcional hasta el vencimiento ya pagado) con un comprobante tipo «extras».
 */
return new class extends Migration
{
    private const CHECK_ANTERIOR = "meses BETWEEN 1 AND 12 AND precio_mensual > 0 AND importe = precio_mensual * meses AND archivo_bytes > 0 AND ((estado = 'pendiente' AND revisado_por IS NULL AND revisado_en IS NULL AND vencimiento_nuevo IS NULL) OR (estado = 'aprobado' AND revisado_por IS NOT NULL AND revisado_en IS NOT NULL AND vencimiento_anterior IS NOT NULL AND vencimiento_nuevo > vencimiento_anterior) OR (estado = 'rechazado' AND revisado_por IS NOT NULL AND revisado_en IS NOT NULL AND motivo_rechazo IS NOT NULL AND vencimiento_nuevo IS NULL))";

    public function up(): void
    {
        // En null, sin límite (p. ej. un plan Empresa negociado).
        Schema::table('planes', function (Blueprint $table) {
            $table->unsignedSmallInteger('sucursales_incluidas')->nullable()->default(1)->after('agente_ia');
            $table->unsignedSmallInteger('usuarios_incluidos')->nullable()->default(3)->after('sucursales_incluidas');
            $table->decimal('precio_sucursal_extra', 18, 2)->default(120)->after('usuarios_incluidos');
            $table->decimal('precio_paquete_usuarios', 18, 2)->default(50)->after('precio_sucursal_extra');
            $table->unsignedTinyInteger('usuarios_por_paquete')->default(5)->after('precio_paquete_usuarios');
        });

        // Extras vigentes. suscripciones.precio_mensual sigue siendo el precio del plan.
        Schema::table('suscripciones', function (Blueprint $table) {
            $table->unsignedSmallInteger('sucursales_extra')->default(0)->after('precio_mensual');
            $table->unsignedSmallInteger('paquetes_usuarios')->default(0)->after('sucursales_extra');
        });

        DB::statement('ALTER TABLE pagos_saas DROP CONSTRAINT pagos_saas_check');

        Schema::table('pagos_saas', function (Blueprint $table) {
            $table->string('tipo', 12)->default('renovacion')->after('suscripcion_id');
            // Un comprobante de extras no paga meses: el vencimiento no cambia.
            $table->unsignedTinyInteger('meses')->nullable()->change();
            // Desglose de precio_mensual al enviar (plan + extras); en comprobantes previos, null.
            $table->decimal('precio_plan', 18, 2)->nullable()->after('precio_mensual');
            $table->unsignedSmallInteger('sucursales_extra')->nullable()->after('precio_plan');
            $table->decimal('precio_sucursal_extra', 18, 2)->nullable()->after('sucursales_extra');
            $table->unsignedSmallInteger('paquetes_usuarios')->nullable()->after('precio_sucursal_extra');
            $table->decimal('precio_paquete_usuarios', 18, 2)->nullable()->after('paquetes_usuarios');
        });

        $desglose = 'sucursales_extra IS NOT NULL AND precio_sucursal_extra IS NOT NULL AND paquetes_usuarios IS NOT NULL AND precio_paquete_usuarios IS NOT NULL'
            .' AND precio_mensual = precio_plan + sucursales_extra * precio_sucursal_extra + paquetes_usuarios * precio_paquete_usuarios';

        DB::statement('ALTER TABLE pagos_saas ADD CONSTRAINT pagos_saas_check CHECK (precio_mensual > 0 AND importe > 0 AND archivo_bytes > 0 AND ('
            ."(tipo = 'renovacion' AND meses BETWEEN 1 AND 12 AND importe = precio_mensual * meses AND (precio_plan IS NULL OR ({$desglose})))"
            ." OR (tipo = 'extras' AND meses IS NULL AND precio_plan IS NOT NULL AND {$desglose})"
            .') AND ('
            ."(estado = 'pendiente' AND revisado_por IS NULL AND revisado_en IS NULL AND vencimiento_nuevo IS NULL)"
            ." OR (estado = 'aprobado' AND revisado_por IS NOT NULL AND revisado_en IS NOT NULL AND vencimiento_anterior IS NOT NULL"
            ." AND (vencimiento_nuevo > vencimiento_anterior OR (tipo = 'extras' AND vencimiento_nuevo = vencimiento_anterior)))"
            ." OR (estado = 'rechazado' AND revisado_por IS NOT NULL AND revisado_en IS NOT NULL AND motivo_rechazo IS NOT NULL AND vencimiento_nuevo IS NULL)"
            .'))');
        DB::statement('ALTER TABLE suscripciones ADD CONSTRAINT suscripciones_extras_check CHECK (sucursales_extra <= 999 AND paquetes_usuarios <= 999)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE suscripciones DROP CONSTRAINT suscripciones_extras_check');
        DB::statement('ALTER TABLE pagos_saas DROP CONSTRAINT pagos_saas_check');

        Schema::table('pagos_saas', function (Blueprint $table) {
            $table->dropColumn(['tipo', 'precio_plan', 'sucursales_extra', 'precio_sucursal_extra', 'paquetes_usuarios', 'precio_paquete_usuarios']);
            $table->unsignedTinyInteger('meses')->nullable(false)->change();
        });

        DB::statement('ALTER TABLE pagos_saas ADD CONSTRAINT pagos_saas_check CHECK ('.self::CHECK_ANTERIOR.')');

        Schema::table('suscripciones', function (Blueprint $table) {
            $table->dropColumn(['sucursales_extra', 'paquetes_usuarios']);
        });

        Schema::table('planes', function (Blueprint $table) {
            $table->dropColumn(['sucursales_incluidas', 'usuarios_incluidos', 'precio_sucursal_extra', 'precio_paquete_usuarios', 'usuarios_por_paquete']);
        });
    }
};
