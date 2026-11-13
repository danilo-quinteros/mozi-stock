<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Despachos, etapa D1 (ESPECIFICACION_DESPACHOS.md § 6).
     *
     * - ventas.estado_entrega y los datos de despacho y entrega. Las ventas que ya existen quedan
     *   «entregado» por el valor por defecto, sin quién ni cuándo: el sistema no inventa quién entregó.
     *   Las columnas van al final de la tabla para que el motor pueda agregarlas sin copiarla.
     * - Índices: (empresa, sucursal, estado, fecha) para la pestaña Despachos de una sucursal, y
     *   (empresa, estado, fecha) para «todas las sucursales» del administrador y para comprobar si quedan
     *   ventas sin entregar al apagar la capacidad, sin recorrer todas las ventas de la empresa.
     * - eventos_entrega: historial de solo inserción con cada cambio de estado, incluidas las correcciones.
     * - users.puede_despachar: permiso de almacén (marca, no rol: no toca users_rol_check).
     *
     * El CHECK de estados se amplía en la etapa K1 de cocina (en_cocina).
     */
    public function up(): void
    {
        Schema::table('ventas', function (Blueprint $table) {
            $table->string('estado_entrega', 15)->default('entregado');
            $table->unsignedBigInteger('despachado_por')->nullable();
            $table->timestamp('despachado_en')->nullable();
            $table->string('despacho_retira', 100)->nullable();
            $table->unsignedBigInteger('entregado_por')->nullable();
            $table->timestamp('entregado_en')->nullable();
            $table->string('entrega_recibio', 100)->nullable();

            $table->index(['empresa_id', 'sucursal_id', 'estado_entrega', 'fecha'], 'ventas_empresa_sucursal_entrega_fecha_index');
            $table->index(['empresa_id', 'estado_entrega', 'fecha'], 'ventas_empresa_entrega_fecha_index');
            $table->foreign(['empresa_id', 'despachado_por'], 'ventas_empresa_despachado_por_foreign')
                ->references(['empresa_id', 'id'])->on('users')->restrictOnDelete();
            $table->foreign(['empresa_id', 'entregado_por'], 'ventas_empresa_entregado_por_foreign')
                ->references(['empresa_id', 'id'])->on('users')->restrictOnDelete();
        });

        Schema::create('eventos_entrega', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->unsignedBigInteger('venta_id');
            // null en el evento inicial, cuando la venta nace con su primer estado.
            $table->string('estado_anterior', 15)->nullable();
            $table->string('estado_nuevo', 15);
            $table->unsignedBigInteger('usuario_id');
            $table->string('nota', 250)->nullable();
            $table->boolean('es_correccion')->default(false);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['empresa_id', 'venta_id', 'created_at']);
            $table->foreign(['empresa_id', 'venta_id'], 'eventos_entrega_empresa_venta_foreign')
                ->references(['empresa_id', 'id'])->on('ventas')->restrictOnDelete();
            $table->foreign(['empresa_id', 'usuario_id'], 'eventos_entrega_empresa_usuario_foreign')
                ->references(['empresa_id', 'id'])->on('users')->restrictOnDelete();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('puede_despachar')->default(false);
        });

        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $estados = "'por_despachar', 'despachado', 'entregado', 'cancelado'";
            DB::statement("ALTER TABLE ventas ADD CONSTRAINT ventas_estado_entrega_check CHECK (estado_entrega IN ({$estados}))");
            DB::statement("ALTER TABLE eventos_entrega ADD CONSTRAINT eventos_entrega_estados_check CHECK (estado_nuevo IN ({$estados}) AND (estado_anterior IS NULL OR estado_anterior IN ({$estados})))");
        }
    }

    public function down(): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE ventas DROP CONSTRAINT ventas_estado_entrega_check');
        }

        Schema::dropIfExists('eventos_entrega');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('puede_despachar');
        });

        Schema::table('ventas', function (Blueprint $table) {
            $table->dropForeign('ventas_empresa_despachado_por_foreign');
            $table->dropForeign('ventas_empresa_entregado_por_foreign');
            $table->dropIndex('ventas_empresa_sucursal_entrega_fecha_index');
            $table->dropIndex('ventas_empresa_entrega_fecha_index');
            $table->dropColumn(['estado_entrega', 'despachado_por', 'despachado_en', 'despacho_retira', 'entregado_por', 'entregado_en', 'entrega_recibio']);
        });
    }
};
