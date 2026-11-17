<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Producción, etapa P2 (ESPECIFICACION_PRODUCCION.md § 5 y § 10): recetas con versiones y vida útil del producto.
     *
     * - recetas: una fila por versión. Guardar cambios crea la versión N + 1 y la deja vigente; las anteriores quedan de
     *   solo lectura. La columna generada producto_vigente (producto_id solo en la vigente) hace única la vigente por
     *   producto, aunque dos guardados lleguen a la vez.
     * - receta_items: materias primas y cantidades para la cantidad base; una materia prima no se repite en una versión.
     *   No se modifican: una versión nueva copia sus líneas.
     * - Claves foráneas compuestas (empresa_id, x_id): una receta o una línea nunca apuntan a un producto de otra
     *   empresa, aunque se manipule el id. Que el producto sea de venta y la línea materia prima lo valida GuardarReceta.
     * - productos.vida_util_dias: días desde la producción hasta el vencimiento del lote producido (PR13).
     */
    public function up(): void
    {
        Schema::table('productos', function (Blueprint $table) {
            $table->unsignedSmallInteger('vida_util_dias')->nullable()->after('costo_promedio');
        });

        Schema::create('recetas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->unsignedBigInteger('producto_id');
            $table->unsignedSmallInteger('version');
            $table->decimal('cantidad_base', 18, 3);
            $table->string('nota', 250)->nullable();
            $table->boolean('vigente')->default(true);
            // Desactivada: no se emiten órdenes nuevas con ella (§ 5.5). Se lleva en la versión vigente.
            $table->boolean('activa')->default(true);
            $table->unsignedBigInteger('producto_vigente')->nullable()->storedAs('case when vigente = 1 then producto_id end');
            $table->foreignId('usuario_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['empresa_id', 'producto_id', 'version']);
            // Una sola vigente por producto. También sirve al listado (empresa_id + vigentes).
            $table->unique(['empresa_id', 'producto_vigente']);
            // Destino de las FK compuestas de receta_items (y de las órdenes en P3).
            $table->unique(['empresa_id', 'id']);
            $table->foreign(['empresa_id', 'producto_id'], 'recetas_empresa_producto_foreign')
                ->references(['empresa_id', 'id'])->on('productos')->restrictOnDelete();
        });

        Schema::create('receta_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->unsignedBigInteger('receta_id');
            $table->unsignedBigInteger('producto_id');
            $table->unsignedSmallInteger('orden');
            $table->decimal('cantidad', 18, 3);

            $table->unique(['empresa_id', 'receta_id', 'producto_id']);
            // «¿En qué recetas se usa esta materia prima?» y la FK compuesta del producto.
            $table->index(['empresa_id', 'producto_id']);
            $table->foreign(['empresa_id', 'receta_id'], 'receta_items_empresa_receta_foreign')
                ->references(['empresa_id', 'id'])->on('recetas')->restrictOnDelete();
            $table->foreign(['empresa_id', 'producto_id'], 'receta_items_empresa_producto_foreign')
                ->references(['empresa_id', 'id'])->on('productos')->restrictOnDelete();
        });

        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE productos ADD CONSTRAINT productos_vida_util_check CHECK (vida_util_dias IS NULL OR vida_util_dias BETWEEN 1 AND 3650)');
            DB::statement('ALTER TABLE recetas ADD CONSTRAINT recetas_datos_check CHECK (cantidad_base > 0 AND version >= 1)');
            DB::statement('ALTER TABLE receta_items ADD CONSTRAINT receta_items_cantidad_check CHECK (cantidad > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('receta_items');
        Schema::dropIfExists('recetas');

        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE productos DROP CONSTRAINT productos_vida_util_check');
        }

        Schema::table('productos', function (Blueprint $table) {
            $table->dropColumn('vida_util_dias');
        });
    }
};
