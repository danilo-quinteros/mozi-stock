<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pedidos en cocina, etapa K1 (ESPECIFICACION_COCINA.md § 6). No hay tablas nuevas: se reutilizan
     * ventas.estado_entrega, eventos_entrega y el índice (empresa_id, sucursal_id, estado_entrega, fecha)
     * de despachos.
     *
     * - Los CHECK de estados de entrega se amplían con 'en_cocina'. En MySQL, agregar un CHECK copia la
     *   tabla ventas: conviene migrar fuera de horario.
     * - ventas.nombre_mesa: «Nombre o mesa» opcional al vender (C3), al final de la tabla para que el motor
     *   pueda agregarla sin copiarla.
     *
     * empresas.minutos_alerta_cocina es de la etapa K2.
     */
    private const ESTADOS_DESPACHOS = "'por_despachar', 'despachado', 'entregado', 'cancelado'";

    private const ESTADOS = "'por_despachar', 'despachado', 'en_cocina', 'entregado', 'cancelado'";

    public function up(): void
    {
        Schema::table('ventas', function (Blueprint $table) {
            $table->string('nombre_mesa', 40)->nullable();
        });

        $this->reemplazarChecks(self::ESTADOS);
    }

    /**
     * Falla si quedan ventas o eventos En cocina: el CHECK anterior no los admite.
     */
    public function down(): void
    {
        $this->reemplazarChecks(self::ESTADOS_DESPACHOS);

        Schema::table('ventas', function (Blueprint $table) {
            $table->dropColumn('nombre_mesa');
        });
    }

    private function reemplazarChecks(string $estados): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        DB::statement('ALTER TABLE ventas DROP CONSTRAINT ventas_estado_entrega_check');
        DB::statement("ALTER TABLE ventas ADD CONSTRAINT ventas_estado_entrega_check CHECK (estado_entrega IN ({$estados}))");
        DB::statement('ALTER TABLE eventos_entrega DROP CONSTRAINT eventos_entrega_estados_check');
        DB::statement("ALTER TABLE eventos_entrega ADD CONSTRAINT eventos_entrega_estados_check CHECK (estado_nuevo IN ({$estados}) AND (estado_anterior IS NULL OR estado_anterior IN ({$estados})))");
    }
};
