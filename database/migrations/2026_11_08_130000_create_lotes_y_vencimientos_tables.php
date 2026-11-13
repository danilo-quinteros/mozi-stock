<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lotes y vencimientos, etapa L1 (ESPECIFICACION_LOTES_Y_VENCIMIENTOS.md § 6). Solo LibroStock (con su
     * colaborador LibroLotes) escribe en stock_lotes y movimientos_stock_lotes. Invariante: para un producto con
     * vencimiento, en cada sucursal, stock_niveles.cantidad = Σ stock_lotes.cantidad.
     *
     * - productos.controla_vencimiento: false por defecto; las empresas actuales no cambian. CHECK pendiente de K3
     *   (ESPECIFICACION_PRODUCTOS_SIN_STOCK.md § 4.6): un producto con vencimiento debe controlar stock.
     * - Claves foráneas compuestas (empresa_id, producto_id, lote_id): un saldo, un movimiento o una línea de compra
     *   nunca apuntan a un lote de otra empresa ni de otro producto, aunque se manipule el lote_id.
     * - El lote de regularización (sin número ni fecha) es único por producto gracias a la columna generada
     *   clave_regularizacion (producto_id solo en ese lote, NULL en los demás).
     */
    public function up(): void
    {
        Schema::table('productos', function (Blueprint $table) {
            $table->boolean('controla_vencimiento')->default(false);
        });

        Schema::create('lotes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->unsignedBigInteger('producto_id');
            // NULL solo en el lote de regularización (§ 9).
            $table->string('numero', 40)->nullable();
            $table->date('vencimiento')->nullable();
            $table->boolean('es_regularizacion')->default(false);
            $table->unsignedBigInteger('clave_regularizacion')->nullable()
                ->storedAs('case when es_regularizacion = 1 then producto_id end');
            $table->timestamp('created_at')->useCurrent();

            // Un número = un vencimiento por producto. Con la intercalación de la base, «a123» y «A123» son el mismo lote.
            $table->unique(['empresa_id', 'producto_id', 'numero']);
            $table->unique(['empresa_id', 'clave_regularizacion']);
            // Destino de las FK compuestas: liga el lote a su empresa y a su producto.
            $table->unique(['empresa_id', 'producto_id', 'id']);
            // Reporte de vencimientos (L4).
            $table->index(['empresa_id', 'vencimiento']);
            $table->foreign(['empresa_id', 'producto_id'], 'lotes_empresa_producto_foreign')
                ->references(['empresa_id', 'id'])->on('productos')->restrictOnDelete();
        });

        // Saldo materializado por lote y sucursal. Las filas se crean en cero fuera de la transacción de la operación.
        Schema::create('stock_lotes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->unsignedBigInteger('sucursal_id');
            $table->unsignedBigInteger('producto_id');
            $table->unsignedBigInteger('lote_id');
            $table->decimal('cantidad', 18, 3)->default(0);
            $table->timestamps();

            $table->unique(['empresa_id', 'sucursal_id', 'lote_id']);
            // Reparto FEFO: todos los lotes de un producto en una sucursal, bloqueados en orden.
            $table->index(['empresa_id', 'sucursal_id', 'producto_id']);
            $table->foreign(['empresa_id', 'sucursal_id'], 'stock_lotes_empresa_sucursal_foreign')
                ->references(['empresa_id', 'id'])->on('sucursales')->restrictOnDelete();
            $table->foreign(['empresa_id', 'producto_id', 'lote_id'], 'stock_lotes_empresa_lote_foreign')
                ->references(['empresa_id', 'producto_id', 'id'])->on('lotes')->restrictOnDelete();
        });

        // Libro por lote: una fila por lote y sucursal afectados en cada operación. Append-only.
        Schema::create('movimientos_stock_lotes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->unsignedBigInteger('operacion_id');
            $table->unsignedBigInteger('sucursal_id');
            $table->unsignedBigInteger('producto_id');
            $table->unsignedBigInteger('lote_id');
            $table->decimal('cantidad', 18, 3);
            $table->decimal('saldo_anterior', 18, 3);
            $table->decimal('saldo_posterior', 18, 3);
            $table->timestamp('created_at')->useCurrent();

            // Trazabilidad de un lote (L4).
            $table->index(['empresa_id', 'lote_id', 'created_at']);
            // Reparto de una operación (devoluciones, detalle).
            $table->index('operacion_id');
            $table->foreign(['empresa_id', 'operacion_id'], 'movimientos_lotes_empresa_operacion_foreign')
                ->references(['empresa_id', 'id'])->on('operaciones_inventario')->restrictOnDelete();
            $table->foreign(['empresa_id', 'sucursal_id'], 'movimientos_lotes_empresa_sucursal_foreign')
                ->references(['empresa_id', 'id'])->on('sucursales')->restrictOnDelete();
            $table->foreign(['empresa_id', 'producto_id', 'lote_id'], 'movimientos_lotes_empresa_lote_foreign')
                ->references(['empresa_id', 'producto_id', 'id'])->on('lotes')->restrictOnDelete();
        });

        // § 6.5: una línea por lote; NULL en productos sin vencimiento y en las compras anteriores.
        Schema::table('compra_items', function (Blueprint $table) {
            $table->unsignedBigInteger('lote_id')->nullable()->after('producto_id');
            $table->foreign(['empresa_id', 'producto_id', 'lote_id'], 'compra_items_empresa_lote_foreign')
                ->references(['empresa_id', 'producto_id', 'id'])->on('lotes')->restrictOnDelete();
        });

        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE productos ADD CONSTRAINT productos_vencimiento_requiere_stock_check CHECK (controla_stock = 1 OR controla_vencimiento = 0)');
            DB::statement("ALTER TABLE lotes ADD CONSTRAINT lotes_datos_check CHECK ((es_regularizacion = 1 AND numero IS NULL AND vencimiento IS NULL) OR (es_regularizacion = 0 AND numero IS NOT NULL AND numero <> '' AND vencimiento IS NOT NULL))");
            DB::statement('ALTER TABLE stock_lotes ADD CONSTRAINT stock_lotes_cantidad_check CHECK (cantidad >= 0)');
            DB::statement('ALTER TABLE movimientos_stock_lotes ADD CONSTRAINT movimientos_lotes_saldo_check CHECK (cantidad <> 0 AND saldo_anterior >= 0 AND saldo_posterior >= 0 AND saldo_posterior = saldo_anterior + cantidad)');
        }
    }

    public function down(): void
    {
        Schema::table('compra_items', function (Blueprint $table) {
            $table->dropForeign('compra_items_empresa_lote_foreign');
            $table->dropColumn('lote_id');
        });

        Schema::dropIfExists('movimientos_stock_lotes');
        Schema::dropIfExists('stock_lotes');
        Schema::dropIfExists('lotes');

        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE productos DROP CONSTRAINT productos_vencimiento_requiere_stock_check');
        }

        Schema::table('productos', function (Blueprint $table) {
            $table->dropColumn('controla_vencimiento');
        });
    }
};
