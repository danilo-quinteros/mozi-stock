<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Producción P4 (ESPECIFICACION_PRODUCCION.md § 12): los reportes leen las órdenes finalizadas por fecha de
 * finalización. Índice (empresa_id, estado, finalizada_en) para ese rango sin recorrer todas las órdenes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ordenes_produccion', function (Blueprint $table): void {
            $table->index(['empresa_id', 'estado', 'finalizada_en'], 'ordenes_produccion_reporte_index');
        });
    }

    public function down(): void
    {
        Schema::table('ordenes_produccion', function (Blueprint $table): void {
            $table->dropIndex('ordenes_produccion_reporte_index');
        });
    }
};
