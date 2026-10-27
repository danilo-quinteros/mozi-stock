<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Facturación SIAT, etapa 4: facturas compra-venta emitidas desde ventas confirmadas. La factura
 * guarda solo su estado fiscal: nunca escribe en ventas, pagos_venta, movimientos_caja ni stock.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('siat_configuraciones', function (Blueprint $table) {
            // Códigos del SIN que usan las líneas de factura mientras no exista una equivalencia por producto.
            $table->string('actividad_economica', 20)->nullable()->after('metodo_pago_electronico');
            $table->unsignedInteger('producto_sin')->nullable()->after('actividad_economica');
            $table->unsignedSmallInteger('unidad_medida')->nullable()->after('producto_sin');
        });

        Schema::create('siat_facturas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->unsignedBigInteger('venta_id');
            // Igual a venta_id mientras la factura está vigente; null al anularla. Con el índice único,
            // una venta tiene como máximo una factura vigente aunque se repita la confirmación.
            $table->unsignedBigInteger('venta_vigente')->nullable();
            $table->unsignedBigInteger('siat_punto_venta_id')->nullable();
            $table->unsignedBigInteger('siat_cufd_id')->nullable();
            // Correlativo por punto de venta. Se asigna al preparar el primer envío: una factura bloqueada no consume número.
            $table->unsignedBigInteger('numero')->nullable();
            $table->string('estado', 20);
            // Por qué está bloqueada, o el último error o rechazo del SIN (sin datos sensibles).
            $table->string('motivo', 1000)->nullable();

            // Comprador, tal como irá en el XML.
            $table->unsignedTinyInteger('tipo_documento');
            $table->string('numero_documento', 20);
            $table->string('complemento', 5)->nullable();
            $table->string('razon_social', 500);
            $table->string('codigo_cliente', 100);
            $table->unsignedTinyInteger('codigo_excepcion')->default(0);
            $table->unsignedSmallInteger('metodo_pago')->nullable();
            // Solo el número ofuscado (4 dígitos, 8 ceros, 4 dígitos), nunca la tarjeta completa.
            $table->string('numero_tarjeta', 16)->nullable();

            // Montos fiscales calculados por CalculadoraFiscal; total es lo que pagó el cliente (ventas.total).
            $table->decimal('total', 18, 2);
            $table->decimal('sub_total_neto', 18, 2)->nullable();
            $table->decimal('descuento_adicional', 18, 2)->nullable();
            $table->decimal('total_precio_neto', 18, 2)->nullable();
            $table->decimal('iva', 18, 2)->nullable();
            $table->json('lineas')->nullable();

            // Documento preparado: se guarda ANTES de enviarlo y los reintentos reenvían este mismo XML.
            $table->dateTime('fecha_emision', 3)->nullable();
            $table->string('cuf', 100)->nullable();
            $table->string('leyenda', 500)->nullable();
            $table->longText('xml')->nullable();

            $table->string('codigo_recepcion', 100)->nullable();
            $table->unsignedSmallInteger('codigo_estado')->nullable();
            $table->json('mensajes')->nullable();
            $table->unsignedSmallInteger('intentos')->default(0);
            $table->timestamp('enviada_en')->nullable();
            $table->timestamp('validada_en')->nullable();

            $table->unsignedSmallInteger('motivo_anulacion')->nullable();
            $table->timestamp('anulada_en')->nullable();
            $table->foreignId('anulada_por')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('revertida_en')->nullable();
            $table->foreignId('revertida_por')->nullable()->constrained('users')->restrictOnDelete();

            $table->foreignId('emitida_por')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['empresa_id', 'id']);
            $table->unique(['empresa_id', 'venta_vigente']);
            $table->unique(['siat_punto_venta_id', 'numero']);
            $table->unique('cuf');
            $table->index(['empresa_id', 'venta_id']);
            $table->index(['empresa_id', 'estado', 'created_at']);
            $table->foreign(['empresa_id', 'venta_id'], 'siat_facturas_empresa_venta_foreign')
                ->references(['empresa_id', 'id'])->on('ventas')->restrictOnDelete();
            $table->foreign(['empresa_id', 'venta_vigente'], 'siat_facturas_empresa_vigente_foreign')
                ->references(['empresa_id', 'id'])->on('ventas')->restrictOnDelete();
            $table->foreign(['empresa_id', 'siat_punto_venta_id'], 'siat_facturas_empresa_punto_foreign')
                ->references(['empresa_id', 'id'])->on('siat_puntos_venta')->restrictOnDelete();
            $table->foreign(['empresa_id', 'siat_cufd_id'], 'siat_facturas_empresa_cufd_foreign')
                ->references(['empresa_id', 'id'])->on('siat_cufd')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('siat_facturas');

        Schema::table('siat_configuraciones', function (Blueprint $table) {
            $table->dropColumn(['actividad_economica', 'producto_sin', 'unidad_medida']);
        });
    }
};
