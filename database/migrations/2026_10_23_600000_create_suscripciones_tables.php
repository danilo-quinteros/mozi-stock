<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Suscripciones SaaS de MoziStudios (etapa 6). Separadas por completo del dinero de las
 * empresas: nada de esto escribe en movimientos_caja ni en pagos_venta (T27), y nada
 * cambia empresas.activa (T26). Decisiones del propietario § 8.4 y § 8.5 (23/10/2026).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Una por empresa. dia_vencimiento conserva el día original del ciclo: en meses
        // cortos el vencimiento cae en el último día y luego vuelve a ese día.
        Schema::create('suscripciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->unique()->constrained('empresas')->restrictOnDelete();
            $table->decimal('precio_mensual', 18, 2);
            $table->unsignedTinyInteger('dia_vencimiento');
            // Fecha de calendario de La Paz, sin hora.
            $table->date('vencimiento');
            $table->timestamps();

            $table->unique(['empresa_id', 'id']);
            $table->index('vencimiento');
        });

        // Comprobante de pago QR enviado por la empresa. Solo el superadministrador lo
        // aprueba o rechaza; subir el archivo no cuenta como pago.
        Schema::create('pagos_saas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->unsignedBigInteger('suscripcion_id');
            $table->unsignedTinyInteger('meses');
            // Precio vigente al enviar el comprobante: un cambio posterior no lo altera.
            $table->decimal('precio_mensual', 18, 2);
            $table->decimal('importe', 18, 2);
            $table->string('nota', 300)->nullable();
            $table->string('archivo_path', 255);
            $table->string('archivo_mime', 100);
            $table->unsignedInteger('archivo_bytes');
            $table->string('estado', 10)->default('pendiente');
            $table->foreignId('enviado_por')->constrained('users')->restrictOnDelete();
            $table->foreignId('revisado_por')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('revisado_en')->nullable();
            $table->string('motivo_rechazo', 300)->nullable();
            $table->date('vencimiento_anterior')->nullable();
            $table->date('vencimiento_nuevo')->nullable();
            $table->uuid('clave_idempotencia');
            // Un solo comprobante en revisión por empresa, garantizado por la base.
            $table->unsignedBigInteger('pendiente_empresa_id')->nullable()
                ->storedAs("CASE WHEN estado = 'pendiente' THEN empresa_id END");
            $table->timestamps();

            $table->unique(['empresa_id', 'clave_idempotencia']);
            $table->unique('pendiente_empresa_id');
            $table->unique(['empresa_id', 'id']);
            $table->index(['empresa_id', 'created_at']);
            $table->index(['estado', 'created_at']);
            $table->index(['estado', 'revisado_en']);
            $table->foreign(['empresa_id', 'suscripcion_id'], 'pagos_saas_empresa_suscripcion_foreign')
                ->references(['empresa_id', 'id'])->on('suscripciones')->restrictOnDelete();
        });

        // Avisos D-5, D0 y D+3: uno por empresa, período (vencimiento) y evento (T24).
        Schema::create('avisos_suscripcion', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->unsignedBigInteger('suscripcion_id');
            $table->date('vencimiento');
            $table->string('evento', 4);
            $table->date('fecha_programada');
            $table->string('canal', 10)->default('correo');
            $table->string('estado', 18)->default('pendiente');
            $table->json('destinatarios')->nullable();
            $table->dateTime('enviado_en')->nullable();
            $table->string('error', 255)->nullable();
            $table->timestamps();

            $table->unique(['empresa_id', 'vencimiento', 'evento']);
            $table->index(['estado', 'created_at']);
            $table->index(['empresa_id', 'created_at']);
            $table->foreign(['empresa_id', 'suscripcion_id'], 'avisos_suscripcion_empresa_suscripcion_foreign')
                ->references(['empresa_id', 'id'])->on('suscripciones')->restrictOnDelete();
        });

        // Datos de cobro de MoziStudios (una sola fila). El QR va al disco privado.
        Schema::create('ajustes_globales', function (Blueprint $table) {
            $table->id();
            $table->string('receptor_nombre', 150)->nullable();
            $table->string('correo_contacto', 255)->nullable();
            $table->string('qr_path', 255)->nullable();
            $table->timestamps();
        });

        DB::statement('ALTER TABLE suscripciones ADD CONSTRAINT suscripciones_check CHECK (precio_mensual > 0 AND dia_vencimiento BETWEEN 1 AND 31 AND (DAY(vencimiento) = dia_vencimiento OR (DAY(vencimiento) < dia_vencimiento AND vencimiento = LAST_DAY(vencimiento))))');
        DB::statement("ALTER TABLE pagos_saas ADD CONSTRAINT pagos_saas_check CHECK (meses BETWEEN 1 AND 12 AND precio_mensual > 0 AND importe = precio_mensual * meses AND archivo_bytes > 0 AND ((estado = 'pendiente' AND revisado_por IS NULL AND revisado_en IS NULL AND vencimiento_nuevo IS NULL) OR (estado = 'aprobado' AND revisado_por IS NOT NULL AND revisado_en IS NOT NULL AND vencimiento_anterior IS NOT NULL AND vencimiento_nuevo > vencimiento_anterior) OR (estado = 'rechazado' AND revisado_por IS NOT NULL AND revisado_en IS NOT NULL AND motivo_rechazo IS NOT NULL AND vencimiento_nuevo IS NULL)))");
        DB::statement("ALTER TABLE avisos_suscripcion ADD CONSTRAINT avisos_suscripcion_check CHECK (evento IN ('d-5', 'd0', 'd+3') AND canal IN ('correo') AND estado IN ('pendiente', 'enviando', 'enviado', 'no_enviado', 'sin_destinatarios', 'fallido', 'omitido'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('ajustes_globales');
        Schema::dropIfExists('avisos_suscripcion');
        Schema::dropIfExists('pagos_saas');
        Schema::dropIfExists('suscripciones');
    }
};
