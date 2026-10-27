<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Superadmin y cuentas sin asignar no pertenecen a ninguna empresa.
            $table->foreignId('empresa_id')->nullable()->after('id')->constrained('empresas')->restrictOnDelete();
            // Sucursal asignada por el administrador; obligatoria para vendedores.
            $table->unsignedBigInteger('sucursal_id')->nullable()->after('empresa_id');
            $table->string('rol', 20)->nullable()->after('sucursal_id');
            $table->boolean('activo')->default(true)->after('rol');

            $table->index(['empresa_id', 'rol']);
            $table->foreign(['empresa_id', 'sucursal_id'], 'users_empresa_sucursal_foreign')
                ->references(['empresa_id', 'id'])->on('sucursales')->restrictOnDelete();
        });

        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            // Defensa en profundidad: la combinación rol/empresa/sucursal también se valida en el motor.
            DB::statement("ALTER TABLE users ADD CONSTRAINT users_rol_check CHECK (rol IS NULL OR rol IN ('superadmin', 'administrador', 'vendedor'))");
            DB::statement("ALTER TABLE users ADD CONSTRAINT users_rol_empresa_check CHECK ((rol IN ('administrador', 'vendedor') AND empresa_id IS NOT NULL) OR ((rol IS NULL OR rol = 'superadmin') AND empresa_id IS NULL AND sucursal_id IS NULL))");
            DB::statement("ALTER TABLE users ADD CONSTRAINT users_vendedor_sucursal_check CHECK (rol IS NULL OR rol <> 'vendedor' OR sucursal_id IS NOT NULL)");
        }
    }

    public function down(): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE users DROP CONSTRAINT users_vendedor_sucursal_check');
            DB::statement('ALTER TABLE users DROP CONSTRAINT users_rol_empresa_check');
            DB::statement('ALTER TABLE users DROP CONSTRAINT users_rol_check');
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign('users_empresa_sucursal_foreign');
            $table->dropConstrainedForeignId('empresa_id');
            $table->dropColumn(['sucursal_id', 'rol', 'activo']);
        });
    }
};
