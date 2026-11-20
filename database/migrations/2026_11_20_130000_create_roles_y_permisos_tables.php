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

    public function up(): void
    {
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

        Schema::create('rol_permisos', function (Blueprint $table) {
            $table->foreignId('rol_id')->constrained('roles')->cascadeOnDelete();
            $table->string('permiso', 50);

            $table->primary(['rol_id', 'permiso']);
        });

        Schema::create('usuario_permisos', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('permiso', 50);
            $table->foreignId('otorgado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->primary(['user_id', 'permiso']);
        });

        $motor = in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);

        if ($motor) {
            DB::statement('ALTER TABLE users DROP CONSTRAINT users_vendedor_sucursal_check');
            DB::statement('ALTER TABLE users DROP CONSTRAINT users_rol_empresa_check');
            DB::statement('ALTER TABLE users DROP CONSTRAINT users_rol_check');
        }

        Schema::table('users', function (Blueprint $table) {
            $table->renameColumn('rol', 'tipo');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('rol_id')->nullable()->after('tipo');
        });

        $this->migrarDatos();

        Schema::table('users', function (Blueprint $table) {
            $table->index(['empresa_id', 'rol_id']);
            $table->foreign(['empresa_id', 'rol_id'], 'users_empresa_rol_foreign')
                ->references(['empresa_id', 'id'])->on('roles')->restrictOnDelete();
        });

        if ($motor) {
            // Defensa en profundidad (§ 7): la combinación tipo/empresa/sucursal/rol también se valida en el motor.
            DB::statement("ALTER TABLE users ADD CONSTRAINT users_tipo_check CHECK (tipo IS NULL OR tipo IN ('superadmin', 'administrador', 'usuario'))");
            DB::statement("ALTER TABLE users ADD CONSTRAINT users_tipo_empresa_check CHECK ((tipo IN ('administrador', 'usuario') AND empresa_id IS NOT NULL) OR ((tipo IS NULL OR tipo = 'superadmin') AND empresa_id IS NULL AND sucursal_id IS NULL))");
            DB::statement("ALTER TABLE users ADD CONSTRAINT users_usuario_rol_check CHECK (tipo IS NULL OR tipo <> 'usuario' OR (sucursal_id IS NOT NULL AND rol_id IS NOT NULL))");
            DB::statement("ALTER TABLE users ADD CONSTRAINT users_rol_solo_usuario_check CHECK (rol_id IS NULL OR tipo = 'usuario')");
        }
    }

    public function down(): void
    {
        $motor = in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);

        if ($motor) {
            DB::statement('ALTER TABLE users DROP CONSTRAINT users_rol_solo_usuario_check');
            DB::statement('ALTER TABLE users DROP CONSTRAINT users_usuario_rol_check');
            DB::statement('ALTER TABLE users DROP CONSTRAINT users_tipo_empresa_check');
            DB::statement('ALTER TABLE users DROP CONSTRAINT users_tipo_check');
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign('users_empresa_rol_foreign');
            $table->dropIndex(['empresa_id', 'rol_id']);
        });

        // Todo usuario con rol vuelve a ser vendedor. La marca de almacén vuelve si tenía el extra.
        if (Schema::hasColumn('users', 'puede_despachar')) {
            DB::table('users')->where('tipo', 'usuario')
                ->whereIn('id', DB::table('usuario_permisos')->where('permiso', 'despachos.autorizar')->select('user_id'))
                ->update(['puede_despachar' => true]);
        }

        DB::table('users')->where('tipo', 'usuario')->update(['tipo' => 'vendedor']);

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('rol_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->renameColumn('tipo', 'rol');
        });

        if ($motor) {
            DB::statement("ALTER TABLE users ADD CONSTRAINT users_rol_check CHECK (rol IS NULL OR rol IN ('superadmin', 'administrador', 'vendedor'))");
            DB::statement("ALTER TABLE users ADD CONSTRAINT users_rol_empresa_check CHECK ((rol IN ('administrador', 'vendedor') AND empresa_id IS NOT NULL) OR ((rol IS NULL OR rol = 'superadmin') AND empresa_id IS NULL AND sucursal_id IS NULL))");
            DB::statement("ALTER TABLE users ADD CONSTRAINT users_vendedor_sucursal_check CHECK (rol IS NULL OR rol <> 'vendedor' OR sucursal_id IS NOT NULL)");
        }

        Schema::dropIfExists('usuario_permisos');
        Schema::dropIfExists('rol_permisos');
        Schema::dropIfExists('roles');
    }

    private function migrarDatos(): void
    {
        $ahora = Carbon::now();

        foreach (DB::table('empresas')->orderBy('id')->pluck('id') as $empresaId) {
            $vendedorId = $this->crearRol((int) $empresaId, 'Vendedor', 'vendedor', self::VENDEDOR, $ahora);
            $this->crearRol((int) $empresaId, 'Encargado de sucursal', 'encargado', [...self::VENDEDOR, ...self::ENCARGADO_EXTRA], $ahora);

            DB::table('users')->where('empresa_id', $empresaId)->where('tipo', 'vendedor')
                ->update(['tipo' => 'usuario', 'rol_id' => $vendedorId]);
        }

        // Marca de almacén → extra despachos.autorizar, con su registro de auditoría.
        $almacen = DB::table('users')->where('tipo', 'usuario')->where('puede_despachar', true)->orderBy('id')->get(['id', 'empresa_id']);

        foreach ($almacen as $usuario) {
            DB::table('usuario_permisos')->insertOrIgnore([
                'user_id' => $usuario->id, 'permiso' => 'despachos.autorizar', 'otorgado_por' => null, 'created_at' => $ahora,
            ]);
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
     * @param  list<string>  $permisos
     */
    private function crearRol(int $empresaId, string $nombre, string $plantilla, array $permisos, Carbon $ahora): int
    {
        $id = DB::table('roles')->insertGetId([
            'empresa_id' => $empresaId, 'nombre' => $nombre, 'descripcion' => null, 'plantilla' => $plantilla,
            'created_at' => $ahora, 'updated_at' => $ahora,
        ]);

        DB::table('rol_permisos')->insert(array_map(fn (string $p): array => ['rol_id' => $id, 'permiso' => $p], $permisos));

        return $id;
    }
};
