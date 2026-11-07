<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Las consultas de ventas por rango de fechas de toda la empresa (Inicio, reporte de ventas, Agente Mozi)
 * no usan los índices existentes (empresa, sucursal, fecha) y (empresa, vendedor, fecha), porque no
 * filtran por la segunda columna: recorrían todas las ventas de la empresa (300–415 ms con ~80.000
 * ventas en la medición del 04/11/2026). Con (empresa_id, fecha) el rango se resuelve en el índice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ventas', function (Blueprint $table) {
            $table->index(['empresa_id', 'fecha']);
        });
    }

    public function down(): void
    {
        Schema::table('ventas', function (Blueprint $table) {
            $table->dropIndex(['empresa_id', 'fecha']);
        });
    }
};
