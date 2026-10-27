<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('unidades_medida', function (Blueprint $table) {
            $table->unsignedInteger('unidad_medida_sin')->nullable()->after('abreviatura');
        });

        DB::table('unidades_medida')->where('nombre', 'Unidad')->update(['unidad_medida_sin' => 58]);
        DB::table('unidades_medida')->where('nombre', 'Kilogramo')->update(['unidad_medida_sin' => 2]);
        DB::table('unidades_medida')->where('nombre', 'Gramo')->update(['unidad_medida_sin' => 1]);
        DB::table('unidades_medida')->where('nombre', 'Litro')->update(['unidad_medida_sin' => 24]);
        DB::table('unidades_medida')->where('nombre', 'Caja')->update(['unidad_medida_sin' => 15]);
    }

    public function down(): void
    {
        Schema::table('unidades_medida', function (Blueprint $table) {
            $table->dropColumn('unidad_medida_sin');
        });
    }
};
