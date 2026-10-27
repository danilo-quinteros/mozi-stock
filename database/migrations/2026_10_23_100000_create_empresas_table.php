<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tabla global del tenant. El acceso (activa) es independiente de la suscripción (etapa 6).
        Schema::create('empresas', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 150);
            $table->string('razon_social', 200)->nullable();
            $table->string('nit', 30)->nullable();
            $table->string('email')->nullable();
            $table->string('telefono', 40)->nullable();
            $table->boolean('activa')->default(true);
            // Punto de extensión SIAT: sin integración, no puede activarse desde la interfaz.
            $table->boolean('siat_habilitado')->default(false);
            $table->timestamps();

            $table->index(['activa', 'nombre']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('empresas');
    }
};
