<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tallas y colores, etapa V1 (ESPECIFICACION_TALLAS_Y_COLORES.md § 6). Cada combinación (variante) es una fila
     * normal de productos con modelo_id, talla_id y color_id: ventas, compras, ajustes, traspasos, devoluciones,
     * caja y reportes no cambian. Los productos que ya existen quedan con modelo_id NULL, exactamente igual que hoy.
     *
     * - Claves foráneas compuestas con empresa_id: un modelo, una talla o un color de otra empresa no se pueden
     *   referenciar ni manipulando IDs.
     * - clave_variante (columna generada): «modelo-talla-color» solo en las variantes, NULL en los productos sueltos;
     *   con el índice único (empresa_id, clave_variante) la base rechaza dos «M · Negro» en un mismo modelo.
     * - Índices: (empresa_id, modelo_id) agrupa el catálogo y arma la cuadrícula de stock; las FK de talla y color
     *   usan (empresa_id, talla_id) y (empresa_id, color_id), que también usa el renombrado en cascada.
     */
    public function up(): void
    {
        Schema::create('grupos_talla', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->string('nombre', 40);
            $table->unsignedSmallInteger('orden')->default(0);
            $table->boolean('activo')->default(true);
            $table->timestamps();

            $table->unique(['empresa_id', 'nombre']);
            $table->unique(['empresa_id', 'id']);
        });

        Schema::create('tallas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->unsignedBigInteger('grupo_talla_id');
            $table->string('nombre', 15);
            // XS antes que S: el orden de la lista, no el alfabético.
            $table->unsignedSmallInteger('orden')->default(0);
            $table->boolean('activa')->default(true);
            $table->timestamps();

            $table->unique(['empresa_id', 'grupo_talla_id', 'nombre']);
            $table->unique(['empresa_id', 'id']);
            $table->foreign(['empresa_id', 'grupo_talla_id'], 'tallas_empresa_grupo_foreign')
                ->references(['empresa_id', 'id'])->on('grupos_talla')->restrictOnDelete();
        });

        Schema::create('colores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->string('nombre', 40);
            // Código corto para el SKU sugerido (NEG, AZM).
            $table->string('codigo', 6);
            $table->char('muestra_hex', 7)->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();

            $table->unique(['empresa_id', 'nombre']);
            $table->unique(['empresa_id', 'codigo']);
            $table->unique(['empresa_id', 'id']);
        });

        Schema::create('modelos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->string('nombre', 150);
            $table->unsignedBigInteger('categoria_id')->nullable();
            $table->unsignedBigInteger('unidad_medida_id');
            $table->string('sku_base', 30)->nullable();
            $table->text('descripcion')->nullable();
            // Precio del modelo: lo copian las variantes sin precio propio (§ 4.4).
            $table->decimal('precio_consumidor', 18, 2);
            $table->decimal('precio_minorista', 18, 2)->nullable();
            $table->decimal('precio_mayorista', 18, 2)->nullable();
            // Se copia a las variantes al crearlas; después cada variante tiene el suyo.
            $table->decimal('stock_minimo', 18, 3)->default(0);
            $table->unsignedBigInteger('grupo_talla_id')->nullable();
            $table->string('imagen_path')->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();

            $table->unique(['empresa_id', 'nombre']);
            $table->unique(['empresa_id', 'id']);
            $table->index(['empresa_id', 'activo', 'nombre']);
            $table->foreign(['empresa_id', 'categoria_id'], 'modelos_empresa_categoria_foreign')
                ->references(['empresa_id', 'id'])->on('categorias')->restrictOnDelete();
            $table->foreign(['empresa_id', 'unidad_medida_id'], 'modelos_empresa_unidad_foreign')
                ->references(['empresa_id', 'id'])->on('unidades_medida')->restrictOnDelete();
            $table->foreign(['empresa_id', 'grupo_talla_id'], 'modelos_empresa_grupo_foreign')
                ->references(['empresa_id', 'id'])->on('grupos_talla')->restrictOnDelete();
        });

        Schema::table('productos', function (Blueprint $table) {
            $table->unsignedBigInteger('modelo_id')->nullable();
            $table->unsignedBigInteger('talla_id')->nullable();
            $table->unsignedBigInteger('color_id')->nullable();
            // false: usa el precio del modelo; true: precio propio, que el cambio de precio del modelo no toca.
            $table->boolean('precio_propio')->default(false);
            $table->string('clave_variante', 40)->nullable()->storedAs(
                "case when modelo_id is null then null else concat(modelo_id, '-', ifnull(talla_id, 0), '-', ifnull(color_id, 0)) end"
            );

            $table->unique(['empresa_id', 'clave_variante']);
            $table->index(['empresa_id', 'modelo_id']);
            $table->index(['empresa_id', 'talla_id']);
            $table->index(['empresa_id', 'color_id']);
            $table->foreign(['empresa_id', 'modelo_id'], 'productos_empresa_modelo_foreign')
                ->references(['empresa_id', 'id'])->on('modelos')->restrictOnDelete();
            $table->foreign(['empresa_id', 'talla_id'], 'productos_empresa_talla_foreign')
                ->references(['empresa_id', 'id'])->on('tallas')->restrictOnDelete();
            $table->foreign(['empresa_id', 'color_id'], 'productos_empresa_color_foreign')
                ->references(['empresa_id', 'id'])->on('colores')->restrictOnDelete();
        });

        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE modelos ADD CONSTRAINT modelos_precios_check CHECK (precio_consumidor >= 0 AND (precio_minorista IS NULL OR precio_minorista >= 0) AND (precio_mayorista IS NULL OR precio_mayorista >= 0) AND stock_minimo >= 0)');
            // Un producto suelto no tiene talla ni color.
            DB::statement('ALTER TABLE productos ADD CONSTRAINT productos_variante_check CHECK (modelo_id IS NOT NULL OR (talla_id IS NULL AND color_id IS NULL))');
        }
    }

    public function down(): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE productos DROP CONSTRAINT productos_variante_check');
        }

        Schema::table('productos', function (Blueprint $table) {
            $table->dropForeign('productos_empresa_modelo_foreign');
            $table->dropForeign('productos_empresa_talla_foreign');
            $table->dropForeign('productos_empresa_color_foreign');
            $table->dropUnique(['empresa_id', 'clave_variante']);
            $table->dropIndex(['empresa_id', 'modelo_id']);
            $table->dropIndex(['empresa_id', 'talla_id']);
            $table->dropIndex(['empresa_id', 'color_id']);
        });

        Schema::table('productos', function (Blueprint $table) {
            $table->dropColumn(['clave_variante', 'modelo_id', 'talla_id', 'color_id', 'precio_propio']);
        });

        Schema::dropIfExists('modelos');
        Schema::dropIfExists('colores');
        Schema::dropIfExists('tallas');
        Schema::dropIfExists('grupos_talla');
    }
};
