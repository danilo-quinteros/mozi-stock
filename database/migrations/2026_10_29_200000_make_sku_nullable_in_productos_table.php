<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('productos', function (Blueprint $table) {
            $table->string('sku', 40)->nullable()->change();
        });

        Schema::table('venta_items', function (Blueprint $table) {
            $table->string('sku', 40)->nullable()->change();
        });

        Schema::table('compra_items', function (Blueprint $table) {
            $table->string('sku', 40)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('productos', function (Blueprint $table) {
            $table->string('sku', 40)->nullable(false)->change();
        });

        Schema::table('venta_items', function (Blueprint $table) {
            $table->string('sku', 40)->nullable(false)->change();
        });

        Schema::table('compra_items', function (Blueprint $table) {
            $table->string('sku', 40)->nullable(false)->change();
        });
    }
};
