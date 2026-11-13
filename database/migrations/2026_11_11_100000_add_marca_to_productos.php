<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Marca del producto (decisión del propietario, 11/11/2026): opcional y en texto libre; en farmacias se muestra
     * como «Marca / laboratorio». Cada marca sigue siendo un producto distinto, con su stock, precio y códigos.
     *
     * Índice (empresa_id, marca): sirve al filtro por marca del listado y a las marcas sugeridas del formulario
     * (DISTINCT por empresa), sin recorrer todo el catálogo. Los productos existentes quedan sin marca.
     */
    public function up(): void
    {
        Schema::table('productos', function (Blueprint $table) {
            $table->string('marca', 60)->nullable()->after('nombre');
            $table->index(['empresa_id', 'marca']);
        });
    }

    public function down(): void
    {
        Schema::table('productos', function (Blueprint $table) {
            $table->dropIndex(['empresa_id', 'marca']);
            $table->dropColumn('marca');
        });
    }
};
