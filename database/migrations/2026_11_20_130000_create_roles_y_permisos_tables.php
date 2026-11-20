<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Roles y permisos por empresa (ESPECIFICACION_PERMISOS.md § 7 y § 9):
 *
 * - roles, rol_permisos y usuario_permisos (extras).
 * - users.rol pasa a users.tipo ('superadmin' | 'administrador' | 'usuario'; 'vendedor' → 'usuario') y se
 *   agrega rol_id con FK compuesta (empresa_id, rol_id): un usuario nunca tiene un rol de otra empresa.
 * - Cada empresa recibe los roles Vendedor y Encargado de sucursal; los vendedores pasan al rol Vendedor
 *   y la marca puede_despachar se convierte en el extra despachos.autorizar (con auditoría).
 *
 * Reanudable: en MySQL y MariaDB cada cambio de estructura se confirma solo, así que si una ejecución anterior
 * falló a mitad (tablas creadas, columna ya renombrada, roles a medias), volver a migrar continúa desde donde
 * quedó sin duplicar roles, permisos ni auditoría.
 *
 * Las claves de permisos se escriben literales para que la migración no dependa del enum de la aplicación.
 */
return new class extends Migration
{
    private const VENDEDOR = ['ventas.ver', 'ventas.vender', 'creditos.ver', 'creditos.cobrar', 'caja.operar', 'clientes.gestionar'];

    private const ENCARGADO_EXTRA = [
        'ventas.asignar_vendedor', 'ventas.devolver', 'despachos.autorizar', 'entregas.corregir', 'cobros.anular',
        'caja.supervisar', 'egresos.gestionar', 'compras.gestionar', 'inventario.historial', 'inventario.ajustar',
        'inventario.traspasar', 'inventario.vencimientos', 'clientes.desactivar', 'reportes.ver',
    ];

    private const CHECKS_ANTERIORES = [
        'users_vendedor_sucursal_check' => "rol IS NULL OR rol <> 'vendedor' OR sucursal_id IS NOT NULL",
        'users_rol_empresa_check' => "(rol IN ('administrador', 'vendedor') AND empresa_id IS NOT NULL) OR ((rol IS NULL OR rol = 'superadmin') AND empresa_id IS NULL AND sucursal_id IS NULL)",
        'users_rol_check' => "rol IS NULL OR rol IN ('superadmin', 'administrador', 'vendedor')",
    ];

    private const CHECKS_NUEVOS = [
        'users_tipo_check' => "tipo IS NULL OR tipo IN ('superadmin', 'administrador', 'usuario')",
        'users_tipo_empresa_check' => "(tipo IN ('administrador', 'usuario') AND empresa_id IS NOT NULL) OR ((tipo IS NULL OR tipo = 'superadmin') AND empresa_id IS NULL AND sucursal_id IS NULL)",
        'users_usuario_rol_check' => "tipo IS NULL OR tipo <> 'usuario' OR (sucursal_id IS NOT NULL AND rol_id IS NOT NULL)",
        'users_rol_solo_usuario_check' => "rol_id IS NULL OR tipo = 'usuario'",
    ];

    public function up(): void
    {
        if (! Schema::hasTable('roles')) {
            Schema::create('roles', function (Blueprint $table) {
                $table->id();
                $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
                $table->string('nombre', 40);
                $table->string('descripcion', 160)->nullable();
                $table->string('plantilla', 20)->nullable();
                $table->timestamps();

                $table->unique(['empresa_id', 'nombre']);
                $table->unique(['empresa_id', 'id']);
                // Un rol por defecto de cada clase por empresa: crearlos dos veces no los duplica.
                $table->unique(['empresa_id', 'plantilla']);
            });
        }

        if (! Schema::hasTable('rol_permisos')) {
            Schema::create('rol_permisos', function (Blueprint $table) {
                $table->foreignId('rol_id')->constrained('roles')->cascadeOnDelete();
                $table->string('permiso', 50);

                $table->primary(['rol_id', 'permiso']);
            });
        }

        if (! Schema::hasTable('usuario_permisos')) {
            Schema::create('usuario_permisos', function (Blueprint $table) {
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('permiso', 50);
                $table->foreignId('otorgado_por')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('created_at')->useCurrent();

                $table->primary(['user_id', 'permiso']);
            });
        }

        $this->quitarChecks(array_keys(self::CHECKS_ANTERIORES));

        if (Schema::hasColumn('users', 'rol') && ! Schema::hasColumn('users', 'tipo')) {
            Schema::table('users', function (Blueprint $table) {
                $table->renameColumn('rol', 'tipo');
            });
        }

        if (! Schema::hasColumn('users', 'rol_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->unsignedBigInteger('rol_id')->nullable()->after('tipo');
            });
        }

        $this->migrarDatos();

        if (! Schema::hasIndex('users', ['empresa_id', 'rol_id'])) {
            Schema::table('users', function (Blueprint $table) {
                $table->index(['empresa_id', 'rol_id']);
            });
        }

        if (! $this->existeRestriccion('users_empresa_rol_foreign', 'FOREIGN KEY')) {
            Schema::table('users', function (Blueprint $table) {
                $table->foreign(['empresa_id', 'rol_id'], 'users_empresa_rol_foreign')
                    ->references(['empresa_id', 'id'])->on('roles')->restrictOnDelete();
            });
        }

        // Defensa en profundidad (§ 7): la combinación tipo/empresa/sucursal/rol también se valida en el motor.
        $this->agregarChecks(self::CHECKS_NUEVOS);
    }

    public function down(): void
    {
        $this->quitarChecks(array_keys(self::CHECKS_NUEVOS));

        if ($this->existeRestriccion('users_empresa_rol_foreign', 'FOREIGN KEY')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropForeign('users_empresa_rol_foreign');
            });
        }

        if (Schema::hasIndex('users', ['empresa_id', 'rol_id'])) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropIndex(['empresa_id', 'rol_id']);
            });
        }

        if (Schema::hasColumn('users', 'tipo')) {
            // Todo usuario con rol vuelve a ser vendedor. La marca de almacén vuelve si tenía el extra.
            if (Schema::hasColumn('users', 'puede_despachar') && Schema::hasTable('usuario_permisos')) {
                DB::table('users')->where('tipo', 'usuario')
                    ->whereIn('id', DB::table('usuario_permisos')->where('permiso', 'despachos.autorizar')->select('user_id'))
                    ->update(['puede_despachar' => true]);
            }

            DB::table('users')->where('tipo', 'usuario')->update(['tipo' => 'vendedor']);
        }

        if (Schema::hasColumn('users', 'rol_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('rol_id');
            });
        }

        if (Schema::hasColumn('users', 'tipo') && ! Schema::hasColumn('users', 'rol')) {
            Schema::table('users', function (Blueprint $table) {
                $table->renameColumn('tipo', 'rol');
            });
        }

        $this->agregarChecks(self::CHECKS_ANTERIORES);

        Schema::dropIfExists('usuario_permisos');
        Schema::dropIfExists('rol_permisos');
        Schema::dropIfExists('roles');
    }

    private function migrarDatos(): void
    {
        $ahora = Carbon::now();

        foreach (DB::table('empresas')->orderBy('id')->pluck('id') as $empresaId) {
            $vendedorId = $this->rolPorDefecto((int) $empresaId, 'Vendedor', 'vendedor', self::VENDEDOR, $ahora);
            $this->rolPorDefecto((int) $empresaId, 'Encargado de sucursal', 'encargado', [...self::VENDEDOR, ...self::ENCARGADO_EXTRA], $ahora);

            DB::table('users')->where('empresa_id', $empresaId)->where('tipo', 'vendedor')
                ->update(['tipo' => 'usuario', 'rol_id' => $vendedorId]);
        }

        if (! Schema::hasColumn('users', 'puede_despachar')) {
            return;
        }

        // Marca de almacén → extra despachos.autorizar, con su registro de auditoría (una sola vez por usuario).
        $almacen = DB::table('users')->where('tipo', 'usuario')->where('puede_despachar', true)->orderBy('id')->get(['id', 'empresa_id']);

        foreach ($almacen as $usuario) {
            $nuevo = DB::table('usuario_permisos')->insertOrIgnore([
                'user_id' => $usuario->id, 'permiso' => 'despachos.autorizar', 'otorgado_por' => null, 'created_at' => $ahora,
            ]);

            if ($nuevo === 0) {
                continue;
            }

            DB::table('auditoria')->insert([
                'empresa_id' => $usuario->empresa_id,
                'usuario_id' => null,
                'accion' => 'usuario.permisos_extra',
                'entidad' => 'User',
                'entidad_id' => $usuario->id,
                'antes' => json_encode(['permisos_extra' => []]),
                'despues' => json_encode(['permisos_extra' => ['despachos.autorizar'], 'motivo' => 'migracion']),
                'ip' => null,
                'created_at' => $ahora,
            ]);
        }
    }

    /**
     * Rol por defecto de la empresa: lo reutiliza si una ejecución anterior ya lo creó y completa sus permisos.
     *
     * @param  list<string>  $permisos
     */
    private function rolPorDefecto(int $empresaId, string $nombre, string $plantilla, array $permisos, Carbon $ahora): int
    {
        $id = DB::table('roles')->where('empresa_id', $empresaId)->where('plantilla', $plantilla)->value('id')
            ?? DB::table('roles')->insertGetId([
                'empresa_id' => $empresaId, 'nombre' => $nombre, 'descripcion' => null, 'plantilla' => $plantilla,
                'created_at' => $ahora, 'updated_at' => $ahora,
            ]);

        DB::table('rol_permisos')->insertOrIgnore(array_map(fn (string $p): array => ['rol_id' => (int) $id, 'permiso' => $p], $permisos));

        return (int) $id;
    }

    private function motorConChecks(): bool
    {
        return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    }

    private function existeRestriccion(string $nombre, string $tipo): bool
    {
        if (! $this->motorConChecks()) {
            return $tipo === 'FOREIGN KEY' && collect(Schema::getForeignKeys('users'))->contains('name', $nombre);
        }

        return DB::table('information_schema.TABLE_CONSTRAINTS')
            ->where('TABLE_SCHEMA', DB::raw('DATABASE()'))
            ->where('TABLE_NAME', 'users')
            ->where('CONSTRAINT_TYPE', $tipo)
            ->where('CONSTRAINT_NAME', $nombre)
            ->exists();
    }

    /**
     * @param  list<string>  $nombres
     */
    private function quitarChecks(array $nombres): void
    {
        if (! $this->motorConChecks()) {
            return;
        }

        foreach ($nombres as $nombre) {
            if ($this->existeRestriccion($nombre, 'CHECK')) {
                DB::statement("ALTER TABLE users DROP CONSTRAINT {$nombre}");
            }
        }
    }

    /**
     * @param  array<string, string>  $checks
     */
    private function agregarChecks(array $checks): void
    {
        if (! $this->motorConChecks()) {
            return;
        }

        foreach ($checks as $nombre => $condicion) {
            if (! $this->existeRestriccion($nombre, 'CHECK')) {
                DB::statement("ALTER TABLE users ADD CONSTRAINT {$nombre} CHECK ({$condicion})");
            }
        }
    }
};
