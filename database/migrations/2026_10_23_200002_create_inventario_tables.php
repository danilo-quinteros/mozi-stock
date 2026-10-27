<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Saldo materializado por producto y sucursal. Solo lo modifican las acciones de inventario.
        Schema::create('stock_niveles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->unsignedBigInteger('sucursal_id');
            $table->unsignedBigInteger('producto_id');
            $table->decimal('cantidad', 18, 3)->default(0);
            // Null: usa la alerta general del producto.
            $table->decimal('stock_minimo', 18, 3)->nullable();
            $table->timestamps();

            $table->unique(['empresa_id', 'sucursal_id', 'producto_id']);
            $table->index(['empresa_id', 'producto_id']);
            $table->foreign(['empresa_id', 'sucursal_id'], 'stock_empresa_sucursal_foreign')
                ->references(['empresa_id', 'id'])->on('sucursales')->restrictOnDelete();
            $table->foreign(['empresa_id', 'producto_id'], 'stock_empresa_producto_foreign')
                ->references(['empresa_id', 'id'])->on('productos')->restrictOnDelete();
        });

        // Cabecera de cada operación (AJU-00001, TRA-00001). Append-only.
        Schema::create('operaciones_inventario', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->string('serie', 10);
            $table->unsignedBigInteger('numero');
            $table->string('tipo', 20);
            $table->string('motivo', 40)->nullable();
            $table->unsignedBigInteger('sucursal_id');
            $table->unsignedBigInteger('sucursal_destino_id')->nullable();
            $table->string('nota', 250)->nullable();
            $table->foreignId('usuario_id')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('total_lineas');
            // Una misma confirmación repetida (doble clic, reintento) no vuelve a aplicarse.
            $table->uuid('clave_idempotencia');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['empresa_id', 'serie', 'numero']);
            $table->unique(['empresa_id', 'clave_idempotencia']);
            $table->unique(['empresa_id', 'id']);
            $table->index(['empresa_id', 'tipo', 'created_at']);
            $table->index(['empresa_id', 'created_at']);
            $table->foreign(['empresa_id', 'sucursal_id'], 'operaciones_empresa_sucursal_foreign')
                ->references(['empresa_id', 'id'])->on('sucursales')->restrictOnDelete();
            $table->foreign(['empresa_id', 'sucursal_destino_id'], 'operaciones_empresa_destino_foreign')
                ->references(['empresa_id', 'id'])->on('sucursales')->restrictOnDelete();
        });

        // Libro histórico: una fila por producto y sucursal afectados, con saldo antes y después. Append-only.
        Schema::create('movimientos_stock', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->unsignedBigInteger('operacion_id');
            $table->unsignedBigInteger('sucursal_id');
            $table->unsignedBigInteger('producto_id');
            $table->decimal('cantidad', 18, 3);
            $table->decimal('saldo_anterior', 18, 3);
            $table->decimal('saldo_posterior', 18, 3);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['empresa_id', 'sucursal_id', 'created_at']);
            $table->index(['empresa_id', 'producto_id', 'created_at']);
            $table->index('operacion_id');
            $table->foreign(['empresa_id', 'operacion_id'], 'movimientos_empresa_operacion_foreign')
                ->references(['empresa_id', 'id'])->on('operaciones_inventario')->restrictOnDelete();
            $table->foreign(['empresa_id', 'sucursal_id'], 'movimientos_empresa_sucursal_foreign')
                ->references(['empresa_id', 'id'])->on('sucursales')->restrictOnDelete();
            $table->foreign(['empresa_id', 'producto_id'], 'movimientos_empresa_producto_foreign')
                ->references(['empresa_id', 'id'])->on('productos')->restrictOnDelete();
        });

        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            // El motor también impide stock negativo y movimientos incoherentes.
            DB::statement('ALTER TABLE stock_niveles ADD CONSTRAINT stock_niveles_cantidad_check CHECK (cantidad >= 0 AND (stock_minimo IS NULL OR stock_minimo >= 0))');
            DB::statement("ALTER TABLE operaciones_inventario ADD CONSTRAINT operaciones_tipo_check CHECK (tipo IN ('entrada', 'salida', 'ajuste', 'traspaso'))");
            DB::statement('ALTER TABLE operaciones_inventario ADD CONSTRAINT operaciones_destino_check CHECK (sucursal_destino_id IS NULL OR sucursal_destino_id <> sucursal_id)');
            DB::statement('ALTER TABLE movimientos_stock ADD CONSTRAINT movimientos_saldo_check CHECK (cantidad <> 0 AND saldo_anterior >= 0 AND saldo_posterior >= 0 AND saldo_posterior = saldo_anterior + cantidad)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('movimientos_stock');
        Schema::dropIfExists('operaciones_inventario');
        Schema::dropIfExists('stock_niveles');
    }
};
