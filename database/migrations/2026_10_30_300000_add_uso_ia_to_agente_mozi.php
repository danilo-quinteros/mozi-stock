<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agente Mozi, Fase 2: uso real reportado por el proveedor en cada respuesta (tokens, latencia y
 * herramientas usadas) para cuotas y costos, y la configuración global del motor que elige el
 * superadministrador. Las claves de API nunca se guardan aquí: viven solo en el entorno del servidor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agente_mensajes', function (Blueprint $table) {
            $table->unsignedInteger('tokens_entrada')->nullable()->after('modelo');
            $table->unsignedInteger('tokens_salida')->nullable()->after('tokens_entrada');
            $table->unsignedInteger('latencia_ms')->nullable()->after('tokens_salida');
            $table->unsignedSmallInteger('herramientas_usadas')->nullable()->after('latencia_ms');

            // Cuota diaria por empresa: respuestas de un proveedor en un rango de fechas.
            $table->index(['empresa_id', 'proveedor', 'created_at'], 'agente_mensajes_uso_index');
        });

        Schema::table('ajustes_globales', function (Blueprint $table) {
            $table->string('agente_motor', 20)->nullable();
            $table->string('agente_modelo', 80)->nullable();
            $table->unsignedInteger('agente_mensajes_diarios')->nullable();
            $table->unsignedInteger('agente_tokens_diarios')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ajustes_globales', function (Blueprint $table) {
            $table->dropColumn(['agente_motor', 'agente_modelo', 'agente_mensajes_diarios', 'agente_tokens_diarios']);
        });

        Schema::table('agente_mensajes', function (Blueprint $table) {
            $table->dropIndex('agente_mensajes_uso_index');
            $table->dropColumn(['tokens_entrada', 'tokens_salida', 'latencia_ms', 'herramientas_usadas']);
        });
    }
};
