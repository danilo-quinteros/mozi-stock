<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Productos sin control de stock, etapa K3 (ESPECIFICACION_PRODUCTOS_SIN_STOCK.md § 5): platos preparados,
     * servicios o cargos que se venden sin mover inventario. Los productos existentes quedan con true y nada cambia.
     * Sin índice nuevo: la marca se lee junto con el producto, por su clave primaria, en las consultas que ya existen.
     * El CHECK con controla_vencimiento se agrega con lotes y vencimientos (L3), cuando exista esa columna.
     */
    public function up(): void
    {
        Schema::table('productos', function (Blueprint $table) {
            $table->boolean('controla_stock')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('productos', function (Blueprint $table) {
            $table->dropColumn('controla_stock');
        });
    }
};
