<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Equivalencias de una migración desde el sistema anterior de una empresa (hoy: Cyclone): qué id de origen quedó como
 * qué registro de MoziStock. Sirve para no migrar dos veces la misma empresa y para enlazar después los productos con
 * el catálogo que reciben las distribuidoras (su externo_id es el id del producto en el sistema anterior).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('migracion_origenes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->string('sistema', 30);
            $table->string('entidad', 30);
            $table->string('origen_id', 64);
            $table->unsignedBigInteger('destino_id');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['empresa_id', 'sistema', 'entidad', 'origen_id'], 'migracion_origenes_origen_unique');
            $table->index(['empresa_id', 'entidad', 'destino_id'], 'migracion_origenes_destino_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('migracion_origenes');
    }
};
