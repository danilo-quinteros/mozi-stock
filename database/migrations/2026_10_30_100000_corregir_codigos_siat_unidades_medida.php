<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Añadir columna opcional para registrar la unidad SIAT sugerida por el proveedor externo
        if (Schema::hasTable('catalogo_externo_productos') && ! Schema::hasColumn('catalogo_externo_productos', 'unidad_siat_sugerida')) {
            Schema::table('catalogo_externo_productos', function (Blueprint $table) {
                $table->string('unidad_siat_sugerida', 50)->nullable()->after('unidad_sugerida');
            });
        }

        // 2. Corregir códigos clasificadores del SIAT en las unidades de medida de todas las empresas
        // Litro: código SIN oficial 28 (LITRO) en vez de 24 (KILOVATIO HORA)
        DB::table('unidades_medida')
            ->where(function ($q) {
                $q->whereIn('abreviatura', ['L', 'lt', 'lts'])->orWhere('nombre', 'Litro');
            })
            ->update(['unidad_medida_sin' => 28]);

        // Kilogramo: código SIN oficial 22 (KILOGRAMO) en vez de 2 (BALDE)
        DB::table('unidades_medida')
            ->where(function ($q) {
                $q->whereIn('abreviatura', ['kg', 'kgs'])->orWhere('nombre', 'Kilogramo');
            })
            ->update(['unidad_medida_sin' => 22]);

        // Gramo: código SIN oficial 17 (GRAMO) en vez de 1 (BOBINAS)
        DB::table('unidades_medida')
            ->where(function ($q) {
                $q->whereIn('abreviatura', ['g', 'gr', 'grs'])->orWhere('nombre', 'Gramo');
            })
            ->update(['unidad_medida_sin' => 17]);

        // Mililitro: código SIN oficial 34 (MILILITRO) en vez de 68 (MESES)
        DB::table('unidades_medida')
            ->where(function ($q) {
                $q->whereIn('abreviatura', ['ml', 'mls', 'cc'])->orWhere('nombre', 'Mililitro');
            })
            ->update(['unidad_medida_sin' => 34]);

        // Caja: código SIN oficial 6 (CAJA) en vez de 15 (FARDO)
        DB::table('unidades_medida')
            ->where(function ($q) {
                $q->whereIn('abreviatura', ['cj.', 'cj'])->orWhere('nombre', 'Caja');
            })
            ->update(['unidad_medida_sin' => 6]);

        // Unidad (bienes): código SIN oficial 57 (UNIDAD (BIENES)) para productos comerciales
        DB::table('unidades_medida')
            ->where(function ($q) {
                $q->whereIn('abreviatura', ['und.', 'und'])->orWhere('nombre', 'Unidad');
            })
            ->where(function ($q) {
                $q->whereNull('unidad_medida_sin')->orWhere('unidad_medida_sin', 58);
            })
            ->update(['unidad_medida_sin' => 57]);
    }

    public function down(): void
    {
        if (Schema::hasTable('catalogo_externo_productos') && Schema::hasColumn('catalogo_externo_productos', 'unidad_siat_sugerida')) {
            Schema::table('catalogo_externo_productos', function (Blueprint $table) {
                $table->dropColumn('unidad_siat_sugerida');
            });
        }
    }
};
