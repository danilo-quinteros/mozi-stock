<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('productos', function (Blueprint $table) {
            $table->decimal('contenido', 18, 3)->nullable()->after('nombre');
        });

        $ahora = now();
        foreach (DB::table('empresas')->pluck('id') as $empresaId) {
            $existe = DB::table('unidades_medida')
                ->where('empresa_id', $empresaId)
                ->where('nombre', 'Mililitro')
                ->exists();

            if (! $existe) {
                DB::table('unidades_medida')->insert([
                    'empresa_id' => $empresaId,
                    'nombre' => 'Mililitro',
                    'abreviatura' => 'ml',
                    'admite_decimales' => false,
                    'activa' => true,
                    'unidad_medida_sin' => 68,
                    'created_at' => $ahora,
                    'updated_at' => $ahora,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('productos', function (Blueprint $table) {
            $table->dropColumn('contenido');
        });

        DB::table('unidades_medida')
            ->where('nombre', 'Mililitro')
            ->where('abreviatura', 'ml')
            ->delete();
    }
};
