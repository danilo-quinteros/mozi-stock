<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agente Mozi: el autor puede eliminar una charla. Se borran sus mensajes (texto), datasets y artefactos,
 * pero la fila de la charla y los contadores de uso de cada mensaje (proveedor, tokens, estado, fecha)
 * se conservan sin contenido: la cuota diaria por empresa se calcula con ellos y no debe reiniciarse.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agente_conversaciones', function (Blueprint $table) {
            $table->timestamp('eliminada_en')->nullable()->after('ultima_actividad_en');
        });
    }

    public function down(): void
    {
        Schema::table('agente_conversaciones', function (Blueprint $table) {
            $table->dropColumn('eliminada_en');
        });
    }
};
