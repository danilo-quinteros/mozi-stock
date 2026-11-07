<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Importación de productos desde una hoja de cálculo cualquiera (sin plantilla).
 * El archivo se lee una vez a importacion_producto_filas (celdas en crudo); la asignación
 * de columnas y valores, la validación y la vista previa trabajan sobre esa tabla.
 * Los productos creados guardan la importación y la fila de origen: así se recuperan sus
 * ids tras la inserción por lotes y queda la trazabilidad de dónde salió cada ficha.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('importaciones_productos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->foreignId('usuario_id')->constrained('users')->restrictOnDelete();
            $table->string('archivo_nombre', 255);
            // Disco privado, carpeta de la empresa. Se borra al terminar o cancelar.
            $table->string('archivo_path', 255)->nullable();
            $table->json('hojas')->nullable();
            $table->unsignedSmallInteger('hoja')->default(0);
            $table->unsignedInteger('fila_encabezados')->default(1);
            $table->json('mapeo')->nullable();
            $table->json('valores')->nullable();
            $table->unsignedBigInteger('unidad_defecto_id')->nullable();
            // null: el archivo no trae stock o aún no se respondió la pregunta.
            $table->boolean('usar_stock')->nullable();
            $table->unsignedBigInteger('sucursal_stock_id')->nullable();
            $table->string('estado', 20)->default('columnas');
            $table->unsignedInteger('total_filas')->default(0);
            $table->json('resumen')->nullable();
            $table->timestamp('confirmada_en')->nullable();
            $table->timestamps();

            $table->unique(['empresa_id', 'id']);
            $table->index(['empresa_id', 'created_at']);
        });

        Schema::create('importacion_producto_filas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->foreignId('importacion_id')->constrained('importaciones_productos')->cascadeOnDelete();
            $table->unsignedInteger('fila');
            $table->json('celdas');
            // Índices de columna cuyo valor llegó como número de la hoja (no como texto).
            $table->json('numericas')->nullable();
            $table->string('estado', 12)->nullable();
            $table->json('normalizado')->nullable();
            $table->json('errores')->nullable();
            $table->json('advertencias')->nullable();
            $table->unsignedBigInteger('producto_id')->nullable();

            $table->unique(['importacion_id', 'fila']);
            $table->index(['importacion_id', 'estado', 'fila']);
        });

        Schema::table('productos', function (Blueprint $table) {
            $table->foreignId('importacion_id')->nullable()->after('activo')
                ->constrained('importaciones_productos')->nullOnDelete();
            $table->unsignedInteger('importacion_fila')->nullable()->after('importacion_id');
            $table->index(['empresa_id', 'importacion_id', 'importacion_fila'], 'productos_empresa_importacion_index');
        });
    }

    public function down(): void
    {
        Schema::table('productos', function (Blueprint $table) {
            $table->dropForeign(['importacion_id']);
            $table->dropIndex('productos_empresa_importacion_index');
            $table->dropColumn(['importacion_id', 'importacion_fila']);
        });

        Schema::dropIfExists('importacion_producto_filas');
        Schema::dropIfExists('importaciones_productos');
    }
};
