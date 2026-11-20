<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Campos opcionales pedidos por el propietario (20/11/2026) al migrar Cyclone: dirección de clientes y proveedores
 * (300 caracteres: hay direcciones que son enlaces de Google Maps) y teléfono de la sucursal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            $table->string('direccion', 300)->nullable()->after('correo');
        });

        Schema::table('proveedores', function (Blueprint $table) {
            $table->string('direccion', 300)->nullable()->after('ciudad');
        });

        Schema::table('sucursales', function (Blueprint $table) {
            $table->string('telefono', 40)->nullable()->after('direccion');
        });
    }

    public function down(): void
    {
        Schema::table('sucursales', function (Blueprint $table) {
            $table->dropColumn('telefono');
        });

        Schema::table('proveedores', function (Blueprint $table) {
            $table->dropColumn('direccion');
        });

        Schema::table('clientes', function (Blueprint $table) {
            $table->dropColumn('direccion');
        });
    }
};
