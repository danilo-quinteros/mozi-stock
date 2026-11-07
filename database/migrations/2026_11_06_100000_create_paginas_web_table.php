<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sitio web público de MoziStock: SEO editable por página desde el superadmin. Las páginas existen
 * en código (App\Domain\SitioWeb\CatalogoPaginas); aquí solo se guardan los valores que el
 * superadmin cambia. Una columna en null usa el texto por defecto del código. La tabla es global
 * (no pertenece a ninguna empresa) y en el futuro puede crecer como CMS.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paginas_web', function (Blueprint $table) {
            $table->id();
            $table->string('clave', 80)->unique();
            $table->string('titulo_seo', 70)->nullable();
            $table->string('descripcion_seo', 170)->nullable();
            $table->string('og_titulo', 95)->nullable();
            $table->string('og_descripcion', 200)->nullable();
            $table->string('og_imagen_path')->nullable();
            $table->string('canonical_url', 255)->nullable();
            $table->boolean('indexar')->default(true);
            $table->string('mensaje_whatsapp', 300)->nullable();
            $table->timestamps();
        });

        Schema::table('ajustes_globales', function (Blueprint $table) {
            $table->string('sitio_whatsapp', 15)->nullable();
            $table->string('sitio_og_imagen_path')->nullable();
            $table->string('sitio_verificacion_google', 100)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ajustes_globales', function (Blueprint $table) {
            $table->dropColumn(['sitio_whatsapp', 'sitio_og_imagen_path', 'sitio_verificacion_google']);
        });

        Schema::dropIfExists('paginas_web');
    }
};
