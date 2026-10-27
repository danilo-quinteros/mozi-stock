<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sucursales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->string('nombre', 120);
            $table->string('codigo', 20);
            $table->string('direccion')->nullable();
            $table->boolean('activa')->default(true);
            $table->timestamps();

            // Código único por empresa; dos empresas pueden repetirlo.
            $table->unique(['empresa_id', 'codigo']);
            // Destino de claves foráneas compuestas (empresa_id, sucursal_id): impide referencias cruzadas entre empresas.
            $table->unique(['empresa_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sucursales');
    }
};
