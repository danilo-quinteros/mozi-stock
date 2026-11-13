<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Facturación SIAT, fase 2: datos fiscales por empresa. Tablas separadas de las del negocio
 * (la facturación no escribe en ventas, caja ni stock). Todas llevan empresa_id y las relaciones
 * internas usan claves foráneas compuestas (empresa_id, id) para impedir cruces entre empresas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('siat_configuraciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->unique()->constrained('empresas')->restrictOnDelete();
            // NIT fiscal (numérico); queda fijo cuando la empresa ya obtuvo códigos del SIN.
            $table->unsignedBigInteger('nit')->nullable();
            $table->string('razon_social', 200)->nullable();
            // manual: el vendedor elige con o sin factura al confirmar; automatica: toda venta se factura (decisión D1b).
            $table->string('modo', 20)->default('manual');
            // Código SIN del catálogo de métodos de pago que equivale a «electrónico» (decisión D2a).
            $table->unsignedSmallInteger('metodo_pago_electronico')->nullable();
            // Token delegado cifrado con APP_KEY. Nunca se muestra completo ni se registra en logs o auditoría.
            $table->text('token')->nullable();
            $table->string('token_sufijo', 8)->nullable();
            $table->timestamp('token_vence_en')->nullable();
            $table->timestamp('token_actualizado_en')->nullable();
            $table->foreignId('token_actualizado_por')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('siat_sucursales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->unsignedBigInteger('sucursal_id');
            // Código de la sucursal en el padrón del SIN (0 = casa matriz).
            $table->unsignedSmallInteger('codigo');
            $table->string('municipio', 100);
            $table->string('telefono', 25)->nullable();
            $table->timestamps();

            $table->unique(['empresa_id', 'sucursal_id']);
            $table->unique(['empresa_id', 'codigo']);
            $table->unique(['empresa_id', 'id']);
            $table->foreign(['empresa_id', 'sucursal_id'], 'siat_sucursales_empresa_sucursal_foreign')
                ->references(['empresa_id', 'id'])->on('sucursales')->restrictOnDelete();
        });

        Schema::create('siat_puntos_venta', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->unsignedBigInteger('siat_sucursal_id');
            // 0 = la sucursal sin punto de venta; los demás los asigna el SIN al registrarlos.
            $table->unsignedSmallInteger('codigo');
            $table->string('nombre', 120);
            $table->string('tipo', 100)->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();

            $table->unique(['siat_sucursal_id', 'codigo']);
            $table->unique(['empresa_id', 'id']);
            $table->foreign(['empresa_id', 'siat_sucursal_id'], 'siat_puntos_empresa_sucursal_foreign')
                ->references(['empresa_id', 'id'])->on('siat_sucursales')->restrictOnDelete();
        });

        // Historial de CUIS. Cada código vale solo para el ambiente y el código de sistema con que se pidió.
        Schema::create('siat_cuis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->unsignedBigInteger('siat_punto_venta_id');
            $table->unsignedTinyInteger('ambiente');
            $table->string('codigo_sistema', 50);
            $table->string('codigo', 100);
            $table->timestamp('vence_en');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->nullable();

            $table->unique(['siat_punto_venta_id', 'ambiente', 'codigo_sistema', 'codigo'], 'siat_cuis_codigo_unique');
            $table->index(['siat_punto_venta_id', 'ambiente', 'codigo_sistema', 'vence_en'], 'siat_cuis_vigente_index');
            $table->unique(['empresa_id', 'id']);
            $table->foreign(['empresa_id', 'siat_punto_venta_id'], 'siat_cuis_empresa_punto_foreign')
                ->references(['empresa_id', 'id'])->on('siat_puntos_venta')->restrictOnDelete();
        });

        // Historial de CUFD (vigencia de 24 horas). Las facturas guardarán el CUFD con que se emitieron.
        Schema::create('siat_cufd', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->unsignedBigInteger('siat_punto_venta_id');
            $table->unsignedBigInteger('siat_cuis_id');
            $table->string('codigo', 255);
            $table->string('codigo_control', 50);
            $table->string('codigo_interno', 50);
            $table->string('direccion', 500)->nullable();
            $table->timestamp('vence_en');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['siat_punto_venta_id', 'vence_en'], 'siat_cufd_vigente_index');
            $table->unique(['empresa_id', 'id']);
            $table->foreign(['empresa_id', 'siat_punto_venta_id'], 'siat_cufd_empresa_punto_foreign')
                ->references(['empresa_id', 'id'])->on('siat_puntos_venta')->restrictOnDelete();
            $table->foreign(['empresa_id', 'siat_cuis_id'], 'siat_cufd_empresa_cuis_foreign')
                ->references(['empresa_id', 'id'])->on('siat_cuis')->restrictOnDelete();
        });

        // Catálogos sincronizados por empresa: cada sincronización reemplaza el catálogo completo.
        Schema::create('siat_catalogos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->string('catalogo', 60);
            $table->string('codigo', 50);
            $table->text('descripcion');
            $table->json('atributos')->nullable();

            $table->index(['empresa_id', 'catalogo', 'codigo']);
        });

        Schema::create('siat_sincronizaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->string('catalogo', 60);
            $table->unsignedInteger('cantidad')->default(0);
            $table->timestamp('sincronizado_en')->nullable();
            $table->timestamp('fallo_en')->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamps();

            $table->unique(['empresa_id', 'catalogo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('siat_sincronizaciones');
        Schema::dropIfExists('siat_catalogos');
        Schema::dropIfExists('siat_cufd');
        Schema::dropIfExists('siat_cuis');
        Schema::dropIfExists('siat_puntos_venta');
        Schema::dropIfExists('siat_sucursales');
        Schema::dropIfExists('siat_configuraciones');
    }
};
