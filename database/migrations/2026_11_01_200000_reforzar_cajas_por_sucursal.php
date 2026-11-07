<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Una caja pertenece a una sola sucursal y la base de datos lo hace cumplir:
 *
 * - FK compuestas (empresa_id, sucursal_id, caja_id) → cajas en usuarios, turnos, ventas y
 *   movimientos de caja: nada se asigna ni se registra con la caja de otra sucursal o empresa.
 *   Con RESTRICT, la sucursal de una caja con historial tampoco se puede cambiar.
 * - FK (empresa_id, caja_id, turno_caja_id) → turnos_caja en ventas y movimientos: el turno es
 *   de esa misma caja.
 * - Quien abre un turno y sus participantes son usuarios de la misma empresa.
 * - Un solo turno abierto por caja física: índice único sobre una columna generada que vale
 *   caja_id mientras el turno está abierto y NULL al cerrarlo.
 *
 * Si hay datos inconsistentes de dinero, la migración se detiene sin tocarlos para que se
 * revisen a mano. La asignación de cajas de usuarios inconsistente se limpia (caja_id = NULL):
 * el administrador vuelve a asignar la caja desde Usuarios.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->verificarDatos();

        DB::table('users')
            ->whereNotNull('caja_id')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('cajas')
                ->whereColumn('cajas.id', 'users.caja_id')
                ->whereColumn('cajas.empresa_id', 'users.empresa_id')
                ->whereColumn('cajas.sucursal_id', 'users.sucursal_id'))
            ->update(['caja_id' => null]);

        Schema::table('cajas', function (Blueprint $table) {
            $table->unique(['empresa_id', 'sucursal_id', 'id'], 'cajas_empresa_sucursal_id_unique');
        });

        Schema::table('turnos_caja', function (Blueprint $table) {
            $table->unique(['empresa_id', 'caja_id', 'id'], 'turnos_caja_empresa_caja_id_unique');
            $table->unsignedBigInteger('caja_abierta_id')->nullable()->after('estado')
                ->storedAs("CASE WHEN estado = 'abierto' THEN caja_id END");
            $table->unique('caja_abierta_id', 'turnos_caja_un_abierto_por_caja_unique');
            $table->foreign(['empresa_id', 'sucursal_id', 'caja_id'], 'turnos_caja_caja_sucursal_foreign')
                ->references(['empresa_id', 'sucursal_id', 'id'])->on('cajas');
            $table->foreign(['empresa_id', 'usuario_apertura_id'], 'turnos_caja_usuario_empresa_foreign')
                ->references(['empresa_id', 'id'])->on('users');
        });

        Schema::table('turno_usuarios', function (Blueprint $table) {
            $table->foreign(['empresa_id', 'turno_caja_id'], 'turno_usuarios_turno_empresa_foreign')
                ->references(['empresa_id', 'id'])->on('turnos_caja')->cascadeOnDelete();
            $table->foreign(['empresa_id', 'user_id'], 'turno_usuarios_usuario_empresa_foreign')
                ->references(['empresa_id', 'id'])->on('users');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreign(['empresa_id', 'sucursal_id', 'caja_id'], 'users_caja_sucursal_foreign')
                ->references(['empresa_id', 'sucursal_id', 'id'])->on('cajas');
        });

        foreach (['ventas', 'movimientos_caja'] as $tabla) {
            Schema::table($tabla, function (Blueprint $table) use ($tabla) {
                $table->foreign(['empresa_id', 'sucursal_id', 'caja_id'], $tabla.'_caja_sucursal_foreign')
                    ->references(['empresa_id', 'sucursal_id', 'id'])->on('cajas');
                $table->foreign(['empresa_id', 'caja_id', 'turno_caja_id'], $tabla.'_turno_caja_foreign')
                    ->references(['empresa_id', 'caja_id', 'id'])->on('turnos_caja');
            });
        }
    }

    public function down(): void
    {
        foreach (['ventas', 'movimientos_caja'] as $tabla) {
            Schema::table($tabla, function (Blueprint $table) use ($tabla) {
                $table->dropForeign($tabla.'_caja_sucursal_foreign');
                $table->dropForeign($tabla.'_turno_caja_foreign');
            });
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign('users_caja_sucursal_foreign');
        });

        Schema::table('turno_usuarios', function (Blueprint $table) {
            $table->dropForeign('turno_usuarios_turno_empresa_foreign');
            $table->dropForeign('turno_usuarios_usuario_empresa_foreign');
        });

        Schema::table('turnos_caja', function (Blueprint $table) {
            $table->dropForeign('turnos_caja_caja_sucursal_foreign');
            $table->dropForeign('turnos_caja_usuario_empresa_foreign');
            $table->dropUnique('turnos_caja_un_abierto_por_caja_unique');
            $table->dropColumn('caja_abierta_id');
            $table->dropUnique('turnos_caja_empresa_caja_id_unique');
        });

        Schema::table('cajas', function (Blueprint $table) {
            $table->dropUnique('cajas_empresa_sucursal_id_unique');
        });
    }

    /**
     * Detiene la migración si el historial de dinero o de turnos mezcla cajas, sucursales o
     * empresas, o si una caja tiene más de un turno abierto.
     */
    private function verificarDatos(): void
    {
        $problemas = [
            'turnos con la caja de otra sucursal o empresa' => DB::table('turnos_caja as t')->join('cajas as c', 'c.id', '=', 't.caja_id')
                ->where(fn ($q) => $q->whereColumn('t.empresa_id', '<>', 'c.empresa_id')->orWhereColumn('t.sucursal_id', '<>', 'c.sucursal_id'))->count(),
            'cajas con más de un turno abierto' => DB::query()->fromSub(DB::table('turnos_caja')->where('estado', 'abierto')
                ->select('caja_id')->groupBy('caja_id')->havingRaw('COUNT(*) > 1'), 'repetidas')->count(),
            'turnos abiertos por un usuario de otra empresa' => DB::table('turnos_caja as t')->join('users as u', 'u.id', '=', 't.usuario_apertura_id')
                ->where(fn ($q) => $q->whereNull('u.empresa_id')->orWhereColumn('u.empresa_id', '<>', 't.empresa_id'))->count(),
            'participantes de otra empresa' => DB::table('turno_usuarios as p')->join('turnos_caja as t', 't.id', '=', 'p.turno_caja_id')
                ->join('users as u', 'u.id', '=', 'p.user_id')
                ->where(fn ($q) => $q->whereColumn('p.empresa_id', '<>', 't.empresa_id')->orWhereNull('u.empresa_id')->orWhereColumn('u.empresa_id', '<>', 'p.empresa_id'))->count(),
        ];

        foreach (['ventas', 'movimientos_caja'] as $tabla) {
            $problemas[$tabla.' con la caja de otra sucursal o empresa'] = DB::table($tabla.' as x')->join('cajas as c', 'c.id', '=', 'x.caja_id')
                ->where(fn ($q) => $q->whereColumn('x.empresa_id', '<>', 'c.empresa_id')->orWhereNull('x.sucursal_id')->orWhereColumn('x.sucursal_id', '<>', 'c.sucursal_id'))->count();
            $problemas[$tabla.' con un turno de otra caja'] = DB::table($tabla.' as x')->join('turnos_caja as t', 't.id', '=', 'x.turno_caja_id')
                ->where(fn ($q) => $q->whereColumn('x.empresa_id', '<>', 't.empresa_id')->orWhereNull('x.caja_id')->orWhereColumn('x.caja_id', '<>', 't.caja_id'))->count();
        }

        $problemas = array_filter($problemas);

        if ($problemas !== []) {
            throw new RuntimeException('No se aplicaron las restricciones de cajas. Revisa estos datos: '
                .collect($problemas)->map(fn (int $n, string $que) => "{$n} {$que}")->join('; ').'.');
        }
    }
};
