<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agente Mozi (IA-P3): acciones de una respuesta, enlaces a pantallas existentes de la lista cerrada de
 * CatalogoAcciones ({tipo, id, etiqueta}). Se guarda el tipo y el id, nunca una URL: la URL se arma al
 * mostrar el mensaje y se vuelve a comprobar el permiso del usuario.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agente_mensajes', function (Blueprint $table) {
            $table->json('acciones')->nullable()->after('texto');
        });
    }

    public function down(): void
    {
        Schema::table('agente_mensajes', function (Blueprint $table) {
            $table->dropColumn('acciones');
        });
    }
};
