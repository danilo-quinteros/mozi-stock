<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Proveedores externos (ej. Cyclone)
        Schema::create('proveedores_externos', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 40)->unique();
            $table->string('nombre', 100);
            $table->string('driver', 40)->default('cyclone');
            $table->string('base_url', 255)->nullable();
            $table->text('token')->nullable(); // Cast 'encrypted'
            $table->boolean('activo')->default(true);
            $table->timestamp('ultima_descarga_en')->nullable();
            $table->text('ultimo_error')->nullable();
            $table->timestamps();
        });

        // 2. Catálogo central descargado desde la API del proveedor
        // Exclusivo del Superadmin y Jobs. CERO columnas de precio ni costo.
        Schema::create('catalogo_externo_productos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proveedor_externo_id')->constrained('proveedores_externos')->restrictOnDelete();
            $table->string('externo_id', 64);
            $table->string('codigo', 40)->nullable();
            $table->string('nombre_original', 150);
            $table->string('nombre_limpio', 150);
            $table->decimal('contenido', 18, 3)->nullable();
            $table->string('unidad_sugerida', 15)->default('und.');
            $table->string('tipo_venta', 20)->default('unidad');
            $table->text('descripcion')->nullable();
            $table->string('linea_nombre', 100)->nullable();
            $table->string('foto_url', 500)->nullable();
            $table->string('foto_cache_path', 255)->nullable();
            $table->boolean('activo_origen')->default(true);
            $table->boolean('disponible')->default(true);
            $table->string('hash', 64);
            $table->timestamp('origen_updated_at')->nullable();
            $table->timestamps();

            $table->unique(['proveedor_externo_id', 'externo_id'], 'cat_ext_prod_prov_externo_unique');
            $table->index(['proveedor_externo_id', 'disponible', 'nombre_limpio'], 'cat_ext_prod_prov_disp_idx');
        });

        // 3. Empresas vinculadas como revendedoras de un proveedor
        Schema::create('empresa_proveedor_externo', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->foreignId('proveedor_externo_id')->constrained('proveedores_externos')->restrictOnDelete();
            $table->boolean('activo')->default(true);
            $table->unsignedBigInteger('vinculado_por')->nullable();
            $table->timestamp('ultima_sincronizacion_en')->nullable();
            $table->json('ultimo_resultado')->nullable();
            $table->timestamps();

            $table->unique(['empresa_id', 'proveedor_externo_id'], 'empresa_proveedor_ext_unique');
            $table->unique(['empresa_id', 'id'], 'empresa_proveedor_ext_id_unique');
        });

        // 4. Vínculos entre productos locales de cada revendedor y el producto externo
        Schema::create('producto_vinculos_externos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->unsignedBigInteger('producto_id');
            $table->foreignId('proveedor_externo_id')->constrained('proveedores_externos')->restrictOnDelete();
            $table->string('externo_id', 64);
            $table->string('hash_aplicado', 64)->nullable();
            $table->timestamp('sincronizado_en');
            $table->timestamps();

            $table->unique(['empresa_id', 'proveedor_externo_id', 'externo_id'], 'prod_vinc_ext_emp_prov_ext_unique');
            $table->unique(['empresa_id', 'producto_id'], 'prod_vinc_ext_emp_prod_unique');
            $table->foreign(['empresa_id', 'producto_id'], 'prod_vinc_ext_empresa_producto_foreign')
                ->references(['empresa_id', 'id'])->on('productos')->cascadeOnDelete();
        });

        // Insertar el proveedor Cyclone por defecto si no existe
        $ahora = now();
        DB::table('proveedores_externos')->insertOrIgnore([
            'codigo' => 'cyclone',
            'nombre' => 'Cyclone',
            'driver' => 'cyclone',
            // config(), no env(): con config:cache env() devuelve null y quedaría la URL de pruebas.
            'base_url' => config('services.cyclone.base_url'),
            'activo' => true,
            'created_at' => $ahora,
            'updated_at' => $ahora,
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('producto_vinculos_externos');
        Schema::dropIfExists('empresa_proveedor_externo');
        Schema::dropIfExists('catalogo_externo_productos');
        Schema::dropIfExists('proveedores_externos');
    }
};
