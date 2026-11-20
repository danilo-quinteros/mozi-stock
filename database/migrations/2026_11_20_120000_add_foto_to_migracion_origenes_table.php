<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nombre de la foto del producto en el sistema anterior: con él se sabe qué productos migrados tienen foto y se
 * descargan de Cyclone sin depender del catálogo central. '' = el producto no tenía foto; NULL = la empresa se migró
 * antes de esta columna (se completa volviendo a subir su respaldo en Superadmin › Migrar desde Cyclone).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('migracion_origenes', function (Blueprint $table) {
            $table->string('foto', 255)->nullable()->after('destino_id');
        });
    }

    public function down(): void
    {
        Schema::table('migracion_origenes', function (Blueprint $table) {
            $table->dropColumn('foto');
        });
    }
};
