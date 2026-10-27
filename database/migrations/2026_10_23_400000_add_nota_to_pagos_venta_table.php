<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Referencia opcional del abono (por ejemplo, n.º de transferencia). No cambia importes.
        Schema::table('pagos_venta', function (Blueprint $table) {
            $table->string('nota', 150)->nullable()->after('monto_electronico');
        });
    }

    public function down(): void
    {
        Schema::table('pagos_venta', function (Blueprint $table) {
            $table->dropColumn('nota');
        });
    }
};
