<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Registro append-only de eventos críticos. Nunca guarda contraseñas ni tokens.
        Schema::create('auditoria', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->nullable()->constrained('empresas')->restrictOnDelete();
            $table->foreignId('usuario_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('accion', 60);
            $table->string('entidad', 60);
            $table->unsignedBigInteger('entidad_id')->nullable();
            $table->json('antes')->nullable();
            $table->json('despues')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['empresa_id', 'created_at']);
            $table->index(['entidad', 'entidad_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auditoria');
    }
};
