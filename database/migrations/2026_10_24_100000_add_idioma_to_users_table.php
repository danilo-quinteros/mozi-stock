<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Preferencia de idioma de la interfaz; null usa el idioma predeterminado.
        Schema::table('users', function (Blueprint $table) {
            $table->string('idioma', 5)->nullable()->after('activo');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('idioma');
        });
    }
};
