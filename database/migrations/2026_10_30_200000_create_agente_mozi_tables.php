<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Agente Mozi, Fase 1: conversaciones privadas por usuario, mensajes, datasets verificables y
 * artefactos del Lienzo. Nada de esto toca stock, ventas ni caja. Las FK compuestas
 * (empresa_id, x) impiden en el motor que un mensaje, dataset o artefacto apunte a otra empresa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agente_conversaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->unsignedBigInteger('usuario_id');
            $table->string('titulo', 120);
            $table->timestamp('ultima_actividad_en');
            $table->timestamps();

            $table->unique(['empresa_id', 'id']);
            $table->index(['empresa_id', 'usuario_id', 'ultima_actividad_en'], 'agente_conversaciones_historial_index');
            $table->foreign(['empresa_id', 'usuario_id'], 'agente_conversaciones_empresa_usuario_foreign')
                ->references(['empresa_id', 'id'])->on('users')->restrictOnDelete();
        });

        Schema::create('agente_mensajes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->unsignedBigInteger('conversacion_id');
            $table->string('rol', 10);
            $table->text('texto');
            $table->string('estado', 12);
            // UUID del envío (solo mensajes del usuario): repetir la misma solicitud devuelve la misma respuesta.
            $table->uuid('clave')->nullable();
            // Respuesta del agente: a qué mensaje del usuario contesta (una sola respuesta por mensaje).
            $table->unsignedBigInteger('respuesta_de_id')->nullable();
            $table->string('proveedor', 30)->nullable();
            $table->string('modelo', 80)->nullable();
            // Identificador de correlación para los registros (sin datos sensibles).
            $table->string('referencia', 40)->nullable();
            $table->timestamps();

            $table->unique(['empresa_id', 'id']);
            $table->unique(['empresa_id', 'clave']);
            $table->unique('respuesta_de_id');
            $table->index(['empresa_id', 'conversacion_id', 'id']);
            $table->foreign(['empresa_id', 'conversacion_id'], 'agente_mensajes_empresa_conversacion_foreign')
                ->references(['empresa_id', 'id'])->on('agente_conversaciones')->cascadeOnDelete();
            $table->foreign(['empresa_id', 'respuesta_de_id'], 'agente_mensajes_empresa_respuesta_foreign')
                ->references(['empresa_id', 'id'])->on('agente_mensajes')->cascadeOnDelete();
        });

        Schema::create('agente_datasets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->unsignedBigInteger('conversacion_id');
            $table->unsignedBigInteger('mensaje_id');
            $table->unsignedBigInteger('usuario_id');
            // Referencia opaca y aleatoria: no es una URL ni un ID secuencial.
            $table->string('ref', 40)->unique();
            $table->json('columnas');
            $table->json('filas');
            $table->unsignedInteger('total_filas');
            $table->json('meta');
            $table->timestamp('expira_en')->nullable();
            $table->timestamps();

            $table->unique(['empresa_id', 'id']);
            $table->index(['empresa_id', 'mensaje_id']);
            $table->foreign(['empresa_id', 'conversacion_id'], 'agente_datasets_empresa_conversacion_foreign')
                ->references(['empresa_id', 'id'])->on('agente_conversaciones')->cascadeOnDelete();
            $table->foreign(['empresa_id', 'mensaje_id'], 'agente_datasets_empresa_mensaje_foreign')
                ->references(['empresa_id', 'id'])->on('agente_mensajes')->cascadeOnDelete();
            $table->foreign(['empresa_id', 'usuario_id'], 'agente_datasets_empresa_usuario_foreign')
                ->references(['empresa_id', 'id'])->on('users')->restrictOnDelete();
        });

        Schema::create('agente_artefactos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->unsignedBigInteger('conversacion_id');
            $table->unsignedBigInteger('mensaje_id');
            $table->unsignedBigInteger('dataset_id')->nullable();
            $table->string('tipo', 20);
            $table->string('titulo', 160);
            // Spec ya validada por ValidadorLienzo; nunca la salida cruda del modelo.
            $table->json('spec');
            $table->json('meta');
            $table->unsignedSmallInteger('orden');
            $table->string('version', 10);
            // SHA-256 de tipo + spec: auditoría del contenido mostrado (en especial sandbox_web).
            $table->char('hash', 64);
            $table->timestamps();

            $table->unique(['empresa_id', 'id']);
            $table->index(['empresa_id', 'mensaje_id', 'orden']);
            $table->foreign(['empresa_id', 'conversacion_id'], 'agente_artefactos_empresa_conversacion_foreign')
                ->references(['empresa_id', 'id'])->on('agente_conversaciones')->cascadeOnDelete();
            $table->foreign(['empresa_id', 'mensaje_id'], 'agente_artefactos_empresa_mensaje_foreign')
                ->references(['empresa_id', 'id'])->on('agente_mensajes')->cascadeOnDelete();
            $table->foreign(['empresa_id', 'dataset_id'], 'agente_artefactos_empresa_dataset_foreign')
                ->references(['empresa_id', 'id'])->on('agente_datasets')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agente_artefactos');
        Schema::dropIfExists('agente_datasets');
        Schema::dropIfExists('agente_mensajes');
        Schema::dropIfExists('agente_conversaciones');
    }
};
