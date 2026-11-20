<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La marca de almacén ya vive como permiso extra despachos.autorizar (2026_11_20_130000, ESPECIFICACION_PERMISOS.md
 * § 9): la columna se elimina. Al revertir se reconstruye desde los extras.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'puede_despachar')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('puede_despachar');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('puede_despachar')->default(false)->after('activo');
        });

        DB::table('users')->where('tipo', 'usuario')
            ->whereIn('id', DB::table('usuario_permisos')->where('permiso', 'despachos.autorizar')->select('user_id'))
            ->update(['puede_despachar' => true]);
    }
};
