<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Correlativos por empresa y serie (AJU, TRA y, más adelante, ventas). Se bloquean con FOR UPDATE.
        Schema::create('numeradores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->string('serie', 10);
            $table->unsignedBigInteger('ultimo')->default(0);

            $table->unique(['empresa_id', 'serie']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('numeradores');
    }
};
