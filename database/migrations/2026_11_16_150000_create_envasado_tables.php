<?php

use App\Support\RestriccionCheck;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Producción, etapa P5 (ESPECIFICACION_PRODUCCION.md § 7, § 9 y § 10): presentaciones y envasado.
     *
     * - productos.granel_id / granel_por_unidad: una presentación indica de qué granel sale y cuánto granel lleva cada
     *   unidad (en la unidad del granel). Los dos o ninguno. FK compuesta: el granel es siempre de la misma empresa.
     * - presentacion_envases: materias primas (envases, tapas, etiquetas) y cantidad por unidad de la presentación.
     * - envasados: una operación de envasado (operación de inventario ENV). Guarda el granel, el lote del que salió,
     *   cuánto granel pedían las unidades (teórico) y cuánto se usó (real; la diferencia es merma), su costo y el total.
     *   El número es el de su operación (ENV-00001).
     * - envasado_items: presentaciones obtenidas (unidades buenas), con la copia del granel por unidad y su costo.
     * - envasado_consumos: envases teóricos y usados (la diferencia es merma) con el costo promedio leído al registrar.
     * - Claves foráneas compuestas (empresa_id, x_id) en todo: nada apunta a otra empresa aunque se manipule un id. El
     *   lote del granel, además, debe ser de ese granel.
     */
    public function up(): void
    {
        Schema::table('productos', function (Blueprint $table) {
            $table->unsignedBigInteger('granel_id')->nullable()->after('vida_util_dias');
            $table->decimal('granel_por_unidad', 18, 3)->nullable()->after('granel_id');
            // También es el índice de «presentaciones de este granel» (empresa_id, granel_id).
            $table->foreign(['empresa_id', 'granel_id'], 'productos_empresa_granel_foreign')
                ->references(['empresa_id', 'id'])->on('productos')->restrictOnDelete();
        });

        Schema::create('presentacion_envases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->unsignedBigInteger('presentacion_id');
            $table->unsignedBigInteger('producto_id');
            $table->unsignedSmallInteger('orden');
            $table->decimal('cantidad', 18, 3);

            $table->unique(['empresa_id', 'presentacion_id', 'producto_id'], 'presentacion_envases_unique');
            $table->index(['empresa_id', 'producto_id']);
            $table->foreign(['empresa_id', 'presentacion_id'], 'presentacion_envases_empresa_presentacion_foreign')
                ->references(['empresa_id', 'id'])->on('productos')->cascadeOnDelete();
            $table->foreign(['empresa_id', 'producto_id'], 'presentacion_envases_empresa_producto_foreign')
                ->references(['empresa_id', 'id'])->on('productos')->restrictOnDelete();
        });

        Schema::create('envasados', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->unsignedInteger('numero');
            $table->unsignedBigInteger('sucursal_id');
            $table->unsignedBigInteger('granel_id');
            $table->unsignedBigInteger('lote_id')->nullable();
            $table->unsignedBigInteger('operacion_id');
            $table->decimal('granel_teorico', 18, 3);
            $table->decimal('granel_real', 18, 3);
            $table->decimal('granel_costo_unitario', 18, 6);
            $table->decimal('costo_total', 18, 6);
            $table->string('nota', 250)->nullable();
            $table->foreignId('usuario_id')->constrained('users')->restrictOnDelete();
            $table->uuid('clave_idempotencia');
            $table->timestamps();

            // El historial se ordena por número; también la búsqueda «ENV-00012».
            $table->unique(['empresa_id', 'numero']);
            $table->unique(['empresa_id', 'clave_idempotencia']);
            $table->unique(['empresa_id', 'id']);
            // Trazabilidad de lote: del movimiento (operación) al envasado.
            $table->unique(['empresa_id', 'operacion_id']);
            $table->index(['empresa_id', 'granel_id', 'lote_id']);
            $table->foreign(['empresa_id', 'sucursal_id'], 'envasados_empresa_sucursal_foreign')
                ->references(['empresa_id', 'id'])->on('sucursales')->restrictOnDelete();
            $table->foreign(['empresa_id', 'granel_id'], 'envasados_empresa_granel_foreign')
                ->references(['empresa_id', 'id'])->on('productos')->restrictOnDelete();
            $table->foreign(['empresa_id', 'granel_id', 'lote_id'], 'envasados_empresa_lote_foreign')
                ->references(['empresa_id', 'producto_id', 'id'])->on('lotes')->restrictOnDelete();
            $table->foreign(['empresa_id', 'operacion_id'], 'envasados_empresa_operacion_foreign')
                ->references(['empresa_id', 'id'])->on('operaciones_inventario')->restrictOnDelete();
        });

        Schema::create('envasado_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->unsignedBigInteger('envasado_id');
            $table->unsignedBigInteger('producto_id');
            $table->unsignedSmallInteger('orden');
            $table->decimal('unidades', 18, 3);
            $table->decimal('granel_por_unidad', 18, 3);
            $table->decimal('costo_unitario', 18, 6);
            $table->decimal('costo_total', 18, 6);

            $table->unique(['empresa_id', 'envasado_id', 'producto_id']);
            $table->index(['empresa_id', 'producto_id']);
            $table->foreign(['empresa_id', 'envasado_id'], 'envasado_items_empresa_envasado_foreign')
                ->references(['empresa_id', 'id'])->on('envasados')->restrictOnDelete();
            $table->foreign(['empresa_id', 'producto_id'], 'envasado_items_empresa_producto_foreign')
                ->references(['empresa_id', 'id'])->on('productos')->restrictOnDelete();
        });

        Schema::create('envasado_consumos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->unsignedBigInteger('envasado_id');
            $table->unsignedBigInteger('producto_id');
            $table->unsignedSmallInteger('orden');
            $table->decimal('cantidad_teorica', 18, 3);
            $table->decimal('cantidad_real', 18, 3);
            $table->decimal('costo_unitario', 18, 6);

            $table->unique(['empresa_id', 'envasado_id', 'producto_id']);
            $table->index(['empresa_id', 'producto_id']);
            $table->foreign(['empresa_id', 'envasado_id'], 'envasado_consumos_empresa_envasado_foreign')
                ->references(['empresa_id', 'id'])->on('envasados')->restrictOnDelete();
            $table->foreign(['empresa_id', 'producto_id'], 'envasado_consumos_empresa_producto_foreign')
                ->references(['empresa_id', 'id'])->on('productos')->restrictOnDelete();
        });

        if ($this->esMysql()) {
            DB::statement('ALTER TABLE productos ADD CONSTRAINT productos_granel_check CHECK ((granel_id IS NULL) = (granel_por_unidad IS NULL) AND (granel_por_unidad IS NULL OR granel_por_unidad > 0))');
            DB::statement('ALTER TABLE presentacion_envases ADD CONSTRAINT presentacion_envases_cantidad_check CHECK (cantidad > 0)');
            DB::statement('ALTER TABLE envasados ADD CONSTRAINT envasados_check CHECK (granel_teorico > 0 AND granel_real >= granel_teorico AND granel_costo_unitario >= 0 AND costo_total >= 0)');
            DB::statement('ALTER TABLE envasado_items ADD CONSTRAINT envasado_items_check CHECK (unidades > 0 AND granel_por_unidad > 0 AND costo_unitario >= 0 AND costo_total >= 0)');
            DB::statement('ALTER TABLE envasado_consumos ADD CONSTRAINT envasado_consumos_check CHECK (cantidad_teorica > 0 AND cantidad_real >= cantidad_teorica AND costo_unitario >= 0)');
            RestriccionCheck::quitar('operaciones_inventario', 'operaciones_tipo_check');
            DB::statement("ALTER TABLE operaciones_inventario ADD CONSTRAINT operaciones_tipo_check CHECK (tipo IN ('entrada', 'salida', 'ajuste', 'traspaso', 'venta', 'compra', 'devolucion', 'produccion', 'envasado'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('envasado_consumos');
        Schema::dropIfExists('envasado_items');
        Schema::dropIfExists('envasados');
        Schema::dropIfExists('presentacion_envases');

        if ($this->esMysql()) {
            RestriccionCheck::quitar('productos', 'productos_granel_check');
            RestriccionCheck::quitar('operaciones_inventario', 'operaciones_tipo_check');
            DB::statement("ALTER TABLE operaciones_inventario ADD CONSTRAINT operaciones_tipo_check CHECK (tipo IN ('entrada', 'salida', 'ajuste', 'traspaso', 'venta', 'compra', 'devolucion', 'produccion'))");
        }

        Schema::table('productos', function (Blueprint $table) {
            $table->dropForeign('productos_empresa_granel_foreign');
            $table->dropIndex('productos_empresa_granel_foreign');
            $table->dropColumn(['granel_id', 'granel_por_unidad']);
        });
    }

    private function esMysql(): bool
    {
        return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    }
};
