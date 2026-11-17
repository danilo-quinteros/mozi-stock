<?php

use App\Support\RestriccionCheck;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Producción, etapa P3 (ESPECIFICACION_PRODUCCION.md § 6 y § 10): órdenes de producción.
     *
     * - ordenes_produccion: número correlativo por empresa (serie OP del numerador), estado emitida → aprobada →
     *   finalizada, o anulada. Guarda la copia de la cantidad base de la receta; created_at es la fecha de emisión.
     *   Las tres operaciones de inventario (consumo al aprobar, cierre al finalizar, devolución al anular) y el lote del
     *   producto obtenido quedan enlazados con FK compuestas.
     * - orden_produccion_items: copia de las líneas de la receta (cantidad_receta), cantidad teórica escalada, consumo
     *   real al finalizar y costo promedio de la materia prima guardado al aprobar.
     * - Índices: (empresa_id, estado, fecha_programada) para el listado y la regla de apagado; los únicos por operación
     *   sirven a las FK y a la trazabilidad (de una operación de inventario a su orden).
     * - operaciones_inventario.tipo admite 'produccion' (serie PRD).
     */
    public function up(): void
    {
        Schema::create('ordenes_produccion', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->unsignedBigInteger('numero');
            $table->unsignedBigInteger('sucursal_id');
            $table->unsignedBigInteger('producto_id');
            $table->unsignedBigInteger('receta_id');
            $table->string('estado', 12)->default('emitida');
            $table->decimal('cantidad_planificada', 18, 3);
            $table->decimal('cantidad_base', 18, 3);
            $table->decimal('cantidad_obtenida', 18, 3)->nullable();
            $table->date('fecha_programada');
            $table->string('nota', 250)->nullable();
            $table->foreignId('emitida_por')->constrained('users')->restrictOnDelete();
            $table->dateTime('aprobada_en')->nullable();
            $table->foreignId('aprobada_por')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('finalizada_en')->nullable();
            $table->foreignId('finalizada_por')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('anulada_en')->nullable();
            $table->foreignId('anulada_por')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('motivo_anulacion', 200)->nullable();
            $table->unsignedBigInteger('operacion_consumo_id')->nullable();
            $table->unsignedBigInteger('operacion_cierre_id')->nullable();
            $table->unsignedBigInteger('operacion_anulacion_id')->nullable();
            $table->unsignedBigInteger('lote_id')->nullable();
            $table->decimal('costo_total', 18, 6)->nullable();
            $table->decimal('costo_unitario', 18, 6)->nullable();
            $table->uuid('clave_idempotencia');
            $table->timestamps();

            $table->unique(['empresa_id', 'numero']);
            $table->unique(['empresa_id', 'clave_idempotencia']);
            $table->unique(['empresa_id', 'id']);
            $table->unique(['empresa_id', 'operacion_consumo_id']);
            $table->unique(['empresa_id', 'operacion_cierre_id']);
            $table->unique(['empresa_id', 'operacion_anulacion_id']);
            $table->index(['empresa_id', 'estado', 'fecha_programada']);
            $table->index(['empresa_id', 'producto_id', 'lote_id']);
            $table->foreign(['empresa_id', 'sucursal_id'], 'ordenes_produccion_empresa_sucursal_foreign')
                ->references(['empresa_id', 'id'])->on('sucursales')->restrictOnDelete();
            $table->foreign(['empresa_id', 'producto_id'], 'ordenes_produccion_empresa_producto_foreign')
                ->references(['empresa_id', 'id'])->on('productos')->restrictOnDelete();
            $table->foreign(['empresa_id', 'receta_id'], 'ordenes_produccion_empresa_receta_foreign')
                ->references(['empresa_id', 'id'])->on('recetas')->restrictOnDelete();
            $table->foreign(['empresa_id', 'operacion_consumo_id'], 'ordenes_produccion_empresa_consumo_foreign')
                ->references(['empresa_id', 'id'])->on('operaciones_inventario')->restrictOnDelete();
            $table->foreign(['empresa_id', 'operacion_cierre_id'], 'ordenes_produccion_empresa_cierre_foreign')
                ->references(['empresa_id', 'id'])->on('operaciones_inventario')->restrictOnDelete();
            $table->foreign(['empresa_id', 'operacion_anulacion_id'], 'ordenes_produccion_empresa_anulacion_foreign')
                ->references(['empresa_id', 'id'])->on('operaciones_inventario')->restrictOnDelete();
            // El lote es siempre del producto de la orden.
            $table->foreign(['empresa_id', 'producto_id', 'lote_id'], 'ordenes_produccion_empresa_lote_foreign')
                ->references(['empresa_id', 'producto_id', 'id'])->on('lotes')->restrictOnDelete();
        });

        Schema::create('orden_produccion_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->unsignedBigInteger('orden_id');
            $table->unsignedBigInteger('producto_id');
            $table->unsignedSmallInteger('orden');
            $table->decimal('cantidad_receta', 18, 3);
            $table->decimal('cantidad_teorica', 18, 3);
            $table->decimal('cantidad_real', 18, 3)->nullable();
            $table->decimal('costo_unitario', 18, 6)->nullable();

            $table->unique(['empresa_id', 'orden_id', 'producto_id']);
            // Consumo de una materia prima en órdenes (reportes de P4) y la FK compuesta del producto.
            $table->index(['empresa_id', 'producto_id']);
            $table->foreign(['empresa_id', 'orden_id'], 'orden_produccion_items_empresa_orden_foreign')
                ->references(['empresa_id', 'id'])->on('ordenes_produccion')->restrictOnDelete();
            $table->foreign(['empresa_id', 'producto_id'], 'orden_produccion_items_empresa_producto_foreign')
                ->references(['empresa_id', 'id'])->on('productos')->restrictOnDelete();
        });

        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE ordenes_produccion ADD CONSTRAINT ordenes_produccion_check CHECK (
                estado IN ('emitida', 'aprobada', 'finalizada', 'anulada')
                AND cantidad_planificada > 0 AND cantidad_base > 0
                AND (cantidad_obtenida IS NULL OR cantidad_obtenida > 0)
                AND (costo_total IS NULL OR costo_total >= 0) AND (costo_unitario IS NULL OR costo_unitario >= 0)
                AND (estado <> 'emitida' OR aprobada_en IS NULL)
                AND (estado NOT IN ('aprobada', 'finalizada') OR aprobada_en IS NOT NULL)
                AND (estado <> 'finalizada' OR (finalizada_en IS NOT NULL AND cantidad_obtenida IS NOT NULL AND costo_total IS NOT NULL))
                AND (estado <> 'anulada' OR (anulada_en IS NOT NULL AND motivo_anulacion IS NOT NULL))
            )");
            DB::statement('ALTER TABLE orden_produccion_items ADD CONSTRAINT orden_produccion_items_check CHECK (
                cantidad_receta > 0 AND cantidad_teorica > 0
                AND (cantidad_real IS NULL OR cantidad_real >= 0) AND (costo_unitario IS NULL OR costo_unitario >= 0)
            )');
            RestriccionCheck::quitar('operaciones_inventario', 'operaciones_tipo_check');
            DB::statement("ALTER TABLE operaciones_inventario ADD CONSTRAINT operaciones_tipo_check CHECK (tipo IN ('entrada', 'salida', 'ajuste', 'traspaso', 'venta', 'compra', 'devolucion', 'produccion'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('orden_produccion_items');
        Schema::dropIfExists('ordenes_produccion');

        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            RestriccionCheck::quitar('operaciones_inventario', 'operaciones_tipo_check');
            DB::statement("ALTER TABLE operaciones_inventario ADD CONSTRAINT operaciones_tipo_check CHECK (tipo IN ('entrada', 'salida', 'ajuste', 'traspaso', 'venta', 'compra', 'devolucion'))");
        }
    }
};
