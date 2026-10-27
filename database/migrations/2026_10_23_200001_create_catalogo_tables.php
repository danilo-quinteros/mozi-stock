<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('unidades_medida', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->string('nombre', 60);
            $table->string('abreviatura', 15);
            // Kilogramos o litros admiten fracciones; unidades y cajas solo enteros.
            $table->boolean('admite_decimales')->default(false);
            $table->boolean('activa')->default(true);
            $table->timestamps();

            $table->unique(['empresa_id', 'nombre']);
            $table->unique(['empresa_id', 'id']);
        });

        Schema::create('categorias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->string('nombre', 80);
            $table->boolean('activa')->default(true);
            $table->timestamps();

            $table->unique(['empresa_id', 'nombre']);
            $table->unique(['empresa_id', 'id']);
        });

        Schema::create('productos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->unsignedBigInteger('categoria_id')->nullable();
            $table->unsignedBigInteger('unidad_medida_id');
            $table->string('sku', 40);
            $table->string('codigo_barras', 64)->nullable();
            $table->string('nombre', 150);
            $table->text('descripcion')->nullable();
            // Precios de venta actuales. Pedidos y ventas guardarán su propio snapshot.
            $table->decimal('precio_consumidor', 18, 2);
            $table->decimal('precio_minorista', 18, 2)->nullable();
            $table->decimal('precio_mayorista', 18, 2)->nullable();
            // Alerta general; cada sucursal puede definir la suya en stock_niveles.
            $table->decimal('stock_minimo', 18, 3)->default(0);
            $table->string('imagen_path')->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();

            $table->unique(['empresa_id', 'sku']);
            $table->unique(['empresa_id', 'codigo_barras']);
            $table->unique(['empresa_id', 'id']);
            $table->index(['empresa_id', 'activo', 'nombre']);
            $table->foreign(['empresa_id', 'categoria_id'], 'productos_empresa_categoria_foreign')
                ->references(['empresa_id', 'id'])->on('categorias')->restrictOnDelete();
            $table->foreign(['empresa_id', 'unidad_medida_id'], 'productos_empresa_unidad_foreign')
                ->references(['empresa_id', 'id'])->on('unidades_medida')->restrictOnDelete();
        });

        if ($this->esMysql()) {
            DB::statement('ALTER TABLE productos ADD CONSTRAINT productos_precios_check CHECK (precio_consumidor >= 0 AND (precio_minorista IS NULL OR precio_minorista >= 0) AND (precio_mayorista IS NULL OR precio_mayorista >= 0) AND stock_minimo >= 0)');
        }

        // Unidades base para empresas existentes; las nuevas las reciben al crearse.
        $ahora = now();
        foreach (DB::table('empresas')->pluck('id') as $empresaId) {
            DB::table('unidades_medida')->insertOrIgnore(array_map(fn (array $unidad) => $unidad + [
                'empresa_id' => $empresaId, 'activa' => true, 'created_at' => $ahora, 'updated_at' => $ahora,
            ], [
                ['nombre' => 'Unidad', 'abreviatura' => 'und.', 'admite_decimales' => false],
                ['nombre' => 'Kilogramo', 'abreviatura' => 'kg', 'admite_decimales' => true],
                ['nombre' => 'Gramo', 'abreviatura' => 'g', 'admite_decimales' => false],
                ['nombre' => 'Litro', 'abreviatura' => 'L', 'admite_decimales' => true],
                ['nombre' => 'Caja', 'abreviatura' => 'cj.', 'admite_decimales' => false],
            ]));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('productos');
        Schema::dropIfExists('categorias');
        Schema::dropIfExists('unidades_medida');
    }

    private function esMysql(): bool
    {
        return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    }
};
