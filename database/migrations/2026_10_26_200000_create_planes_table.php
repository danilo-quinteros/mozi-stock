<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('planes', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 50)->unique();
            $table->string('titulo', 150);
            $table->text('descripcion');
            $table->decimal('precio_mensual', 18, 2);
            $table->boolean('siat_habilitado')->default(false);
            $table->boolean('activo')->default(true);
            $table->unsignedSmallInteger('orden')->default(1);
            $table->timestamps();

            $table->index(['activo', 'orden']);
        });

        // Insertar los dos planes iniciales
        $ahora = Carbon::now();
        $planInventarioId = DB::table('planes')->insertGetId([
            'codigo' => 'inventario',
            'titulo' => 'Acceso al sistema de inventarios',
            'descripcion' => 'Acceso completo al sistema de gestión de inventarios, pedidos, ventas, compras, caja y reportes.',
            'precio_mensual' => 150.00,
            'siat_habilitado' => false,
            'activo' => true,
            'orden' => 1,
            'created_at' => $ahora,
            'updated_at' => $ahora,
        ]);

        $planSiatId = DB::table('planes')->insertGetId([
            'codigo' => 'siat',
            'titulo' => 'Inventarios + Facturación SIAT',
            'descripcion' => 'Acceso completo al sistema de inventarios más emisión de facturas electrónicas en línea autorizadas por el SIAT.',
            'precio_mensual' => 300.00,
            'siat_habilitado' => true,
            'activo' => true,
            'orden' => 2,
            'created_at' => $ahora,
            'updated_at' => $ahora,
        ]);

        // Modificar suscripciones para vincular el plan actual y el próximo plan (downgrade programado)
        Schema::table('suscripciones', function (Blueprint $table) {
            $table->foreignId('plan_id')->nullable()->after('empresa_id')->constrained('planes')->nullOnDelete();
            $table->foreignId('plan_siguiente_id')->nullable()->after('plan_id')->constrained('planes')->nullOnDelete();
        });

        // Vincular suscripciones existentes con sus planes
        $suscripciones = DB::table('suscripciones')
            ->join('empresas', 'empresas.id', '=', 'suscripciones.empresa_id')
            ->select('suscripciones.id', 'empresas.siat_habilitado')
            ->get();

        foreach ($suscripciones as $suscripcion) {
            DB::table('suscripciones')->where('id', $suscripcion->id)->update([
                'plan_id' => $suscripcion->siat_habilitado ? $planSiatId : $planInventarioId,
            ]);
        }

        // Tabla de auditoría e historial de cambios de plan
        Schema::create('cambios_plan', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('suscripcion_id')->constrained('suscripciones')->cascadeOnDelete();
            $table->foreignId('plan_anterior_id')->constrained('planes')->cascadeOnDelete();
            $table->foreignId('plan_nuevo_id')->constrained('planes')->cascadeOnDelete();
            $table->string('tipo', 20); // 'upgrade', 'downgrade'
            $table->unsignedInteger('dias_restantes')->default(0);
            $table->decimal('monto_prorrateado', 18, 2)->default(0);
            $table->decimal('precio_nuevo_completo', 18, 2);
            $table->date('vencimiento_conservado');
            $table->foreignId('usuario_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['empresa_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cambios_plan');

        Schema::table('suscripciones', function (Blueprint $table) {
            $table->dropForeign(['plan_siguiente_id']);
            $table->dropForeign(['plan_id']);
            $table->dropColumn(['plan_siguiente_id', 'plan_id']);
        });

        Schema::dropIfExists('planes');
    }
};
