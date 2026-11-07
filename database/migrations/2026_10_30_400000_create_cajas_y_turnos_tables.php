<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cajas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->foreignId('sucursal_id')->constrained('sucursales')->restrictOnDelete();
            $table->string('nombre', 100);
            $table->string('codigo', 50)->nullable();
            $table->string('modalidad', 20)->default('compartida'); // compartida, individual
            $table->boolean('activa')->default(true);
            $table->boolean('es_principal')->default(false);
            $table->timestamps();

            $table->unique(['empresa_id', 'id']);
            $table->index(['empresa_id', 'sucursal_id']);
            $table->index(['sucursal_id', 'es_principal']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('caja_id')->nullable()->after('sucursal_id')->constrained('cajas')->nullOnDelete();
            $table->index(['empresa_id', 'caja_id']);
        });

        Schema::create('turnos_caja', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->foreignId('sucursal_id')->constrained('sucursales')->restrictOnDelete();
            $table->foreignId('caja_id')->constrained('cajas')->restrictOnDelete();
            $table->foreignId('usuario_apertura_id')->constrained('users')->restrictOnDelete();
            $table->decimal('monto_apertura', 18, 2)->default(0);
            $table->timestamp('fecha_apertura');
            $table->string('estado', 20)->default('abierto'); // abierto, cerrado
            $table->foreignId('usuario_cierre_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('fecha_cierre')->nullable();
            $table->decimal('monto_cierre', 18, 2)->nullable();
            $table->text('observaciones')->nullable();
            $table->timestamps();

            $table->unique(['empresa_id', 'id']);
            $table->index(['empresa_id', 'caja_id', 'estado']);
            $table->index(['empresa_id', 'sucursal_id', 'estado']);
            $table->index(['usuario_apertura_id', 'estado']);
        });

        Schema::create('turno_usuarios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->foreignId('turno_caja_id')->constrained('turnos_caja')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('incorporado_at');
            $table->timestamp('retirado_at')->nullable();
            $table->timestamps();

            $table->unique(['turno_caja_id', 'user_id']);
            $table->index(['empresa_id', 'user_id']);
        });

        Schema::table('ventas', function (Blueprint $table) {
            $table->foreignId('caja_id')->nullable()->after('sucursal_id')->constrained('cajas')->nullOnDelete();
            $table->foreignId('turno_caja_id')->nullable()->after('caja_id')->constrained('turnos_caja')->nullOnDelete();
            $table->index(['empresa_id', 'caja_id']);
            $table->index(['empresa_id', 'turno_caja_id']);
        });

        Schema::table('movimientos_caja', function (Blueprint $table) {
            $table->foreignId('caja_id')->nullable()->after('sucursal_id')->constrained('cajas')->nullOnDelete();
            $table->foreignId('turno_caja_id')->nullable()->after('caja_id')->constrained('turnos_caja')->nullOnDelete();
            $table->index(['empresa_id', 'caja_id']);
            $table->index(['empresa_id', 'turno_caja_id']);
        });

        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE cajas ADD CONSTRAINT cajas_modalidad_check CHECK (modalidad IN ('compartida', 'individual'))");
            DB::statement("ALTER TABLE turnos_caja ADD CONSTRAINT turnos_caja_check CHECK (monto_apertura >= 0 AND estado IN ('abierto', 'cerrado'))");
        }
    }

    public function down(): void
    {
        Schema::table('movimientos_caja', function (Blueprint $table) {
            $table->dropForeign(['caja_id']);
            $table->dropForeign(['turno_caja_id']);
            $table->dropIndex(['empresa_id', 'caja_id']);
            $table->dropIndex(['empresa_id', 'turno_caja_id']);
            $table->dropColumn(['caja_id', 'turno_caja_id']);
        });

        Schema::table('ventas', function (Blueprint $table) {
            $table->dropForeign(['caja_id']);
            $table->dropForeign(['turno_caja_id']);
            $table->dropIndex(['empresa_id', 'caja_id']);
            $table->dropIndex(['empresa_id', 'turno_caja_id']);
            $table->dropColumn(['caja_id', 'turno_caja_id']);
        });

        Schema::dropIfExists('turno_usuarios');
        Schema::dropIfExists('turnos_caja');

        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['caja_id']);
            $table->dropIndex(['empresa_id', 'caja_id']);
            $table->dropColumn('caja_id');
        });

        Schema::dropIfExists('cajas');
    }
};
