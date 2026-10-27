<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            $table->string('correo', 150)->nullable()->after('telefono');
        });

        Schema::create('proveedores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->string('nombre', 150);
            // NIT o CI opcional; si se registra no se repite dentro de la empresa.
            $table->string('documento', 30)->nullable();
            $table->string('telefono', 40)->nullable();
            $table->string('correo', 150)->nullable();
            $table->string('ciudad', 80)->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();

            $table->unique(['empresa_id', 'documento']);
            $table->unique(['empresa_id', 'id']);
            $table->index(['empresa_id', 'nombre']);
        });

        // Compra pagada (sin cuentas por pagar): entra stock y sale dinero una sola vez.
        // Comparte el correlativo COM con su operación de inventario. Inmutable.
        Schema::create('compras', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->unsignedBigInteger('numero');
            $table->unsignedBigInteger('sucursal_id');
            $table->unsignedBigInteger('proveedor_id')->nullable();
            $table->foreignId('usuario_id')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('operacion_inventario_id');
            // DATETIME: nunca se reescribe sola (ver migración 2026_10_23_400001).
            $table->dateTime('fecha');
            $table->string('referencia', 150)->nullable();
            $table->decimal('total', 18, 2);
            $table->string('metodo', 12);
            $table->decimal('monto_efectivo', 18, 2);
            $table->decimal('monto_electronico', 18, 2);
            $table->uuid('clave_idempotencia');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['empresa_id', 'numero']);
            $table->unique(['empresa_id', 'clave_idempotencia']);
            $table->unique(['empresa_id', 'operacion_inventario_id']);
            $table->unique(['empresa_id', 'id']);
            $table->index(['empresa_id', 'fecha']);
            $table->index(['empresa_id', 'sucursal_id', 'fecha']);
            $table->index(['empresa_id', 'proveedor_id', 'fecha']);
            $table->foreign(['empresa_id', 'sucursal_id'], 'compras_empresa_sucursal_foreign')
                ->references(['empresa_id', 'id'])->on('sucursales')->restrictOnDelete();
            $table->foreign(['empresa_id', 'proveedor_id'], 'compras_empresa_proveedor_foreign')
                ->references(['empresa_id', 'id'])->on('proveedores')->restrictOnDelete();
            $table->foreign(['empresa_id', 'operacion_inventario_id'], 'compras_empresa_operacion_foreign')
                ->references(['empresa_id', 'id'])->on('operaciones_inventario')->restrictOnDelete();
        });

        Schema::create('compra_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->unsignedBigInteger('compra_id');
            $table->unsignedBigInteger('producto_id');
            $table->unsignedInteger('orden');
            $table->string('descripcion', 150);
            $table->string('sku', 40);
            $table->string('unidad', 15);
            $table->decimal('cantidad', 18, 3);
            $table->decimal('costo_unitario', 18, 2);
            $table->decimal('subtotal', 18, 2);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['compra_id', 'orden']);
            $table->index(['empresa_id', 'producto_id']);
            $table->foreign(['empresa_id', 'compra_id'], 'compra_items_empresa_compra_foreign')
                ->references(['empresa_id', 'id'])->on('compras')->restrictOnDelete();
            $table->foreign(['empresa_id', 'producto_id'], 'compra_items_empresa_producto_foreign')
                ->references(['empresa_id', 'id'])->on('productos')->restrictOnDelete();
        });

        Schema::create('categorias_egreso', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->string('nombre', 80);
            $table->string('descripcion', 150)->nullable();
            $table->boolean('activa')->default(true);
            $table->timestamps();

            $table->unique(['empresa_id', 'nombre']);
            $table->unique(['empresa_id', 'id']);
        });

        // Gasto operativo pagado. Las compras de stock nunca se registran aquí. Inmutable.
        Schema::create('egresos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->unsignedBigInteger('numero');
            $table->unsignedBigInteger('sucursal_id');
            $table->unsignedBigInteger('categoria_egreso_id');
            $table->foreignId('usuario_id')->constrained('users')->restrictOnDelete();
            $table->string('descripcion', 150);
            $table->string('metodo', 12);
            $table->decimal('monto', 18, 2);
            $table->dateTime('fecha');
            $table->uuid('clave_idempotencia');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['empresa_id', 'numero']);
            $table->unique(['empresa_id', 'clave_idempotencia']);
            $table->unique(['empresa_id', 'id']);
            $table->index(['empresa_id', 'fecha']);
            $table->index(['empresa_id', 'sucursal_id', 'fecha']);
            $table->index(['empresa_id', 'categoria_egreso_id', 'fecha']);
            $table->foreign(['empresa_id', 'sucursal_id'], 'egresos_empresa_sucursal_foreign')
                ->references(['empresa_id', 'id'])->on('sucursales')->restrictOnDelete();
            $table->foreign(['empresa_id', 'categoria_egreso_id'], 'egresos_empresa_categoria_foreign')
                ->references(['empresa_id', 'id'])->on('categorias_egreso')->restrictOnDelete();
        });

        // Destino de la FK compuesta de devolucion_items.
        Schema::table('venta_items', function (Blueprint $table) {
            $table->unique(['empresa_id', 'id']);
        });

        // Acumulados que solo modifican las devoluciones (la venta original no se reescribe).
        Schema::table('ventas', function (Blueprint $table) {
            $table->decimal('devuelto', 18, 2)->default(0)->after('saldo_pendiente');
            $table->decimal('reembolsado', 18, 2)->default(0)->after('devuelto');
        });

        // Devolución de una venta: reduce primero el saldo del crédito y solo el resto sale
        // de caja como reembolso. El stock vuelve únicamente en las líneas marcadas.
        Schema::create('devoluciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->unsignedBigInteger('numero');
            $table->unsignedBigInteger('venta_id');
            $table->unsignedBigInteger('sucursal_id');
            $table->foreignId('usuario_id')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('operacion_inventario_id')->nullable();
            $table->dateTime('fecha');
            $table->string('motivo', 250);
            $table->decimal('importe', 18, 2);
            $table->decimal('deuda_reducida', 18, 2);
            $table->decimal('reembolso', 18, 2);
            $table->string('reembolso_metodo', 12)->nullable();
            $table->decimal('reembolso_efectivo', 18, 2);
            $table->decimal('reembolso_electronico', 18, 2);
            $table->uuid('clave_idempotencia');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['empresa_id', 'numero']);
            $table->unique(['empresa_id', 'clave_idempotencia']);
            $table->unique(['empresa_id', 'id']);
            $table->index(['empresa_id', 'venta_id']);
            $table->index(['empresa_id', 'fecha']);
            $table->foreign(['empresa_id', 'venta_id'], 'devoluciones_empresa_venta_foreign')
                ->references(['empresa_id', 'id'])->on('ventas')->restrictOnDelete();
            $table->foreign(['empresa_id', 'sucursal_id'], 'devoluciones_empresa_sucursal_foreign')
                ->references(['empresa_id', 'id'])->on('sucursales')->restrictOnDelete();
            $table->foreign(['empresa_id', 'operacion_inventario_id'], 'devoluciones_empresa_operacion_foreign')
                ->references(['empresa_id', 'id'])->on('operaciones_inventario')->restrictOnDelete();
        });

        Schema::create('devolucion_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->unsignedBigInteger('devolucion_id');
            $table->unsignedBigInteger('venta_item_id');
            $table->unsignedBigInteger('producto_id');
            $table->decimal('cantidad', 18, 3);
            $table->decimal('importe', 18, 2);
            $table->boolean('reingresa_stock');
            $table->timestamp('created_at')->useCurrent();

            $table->index('devolucion_id');
            $table->index(['empresa_id', 'venta_item_id']);
            $table->foreign(['empresa_id', 'devolucion_id'], 'devolucion_items_empresa_devolucion_foreign')
                ->references(['empresa_id', 'id'])->on('devoluciones')->restrictOnDelete();
            $table->foreign(['empresa_id', 'venta_item_id'], 'devolucion_items_empresa_item_foreign')
                ->references(['empresa_id', 'id'])->on('venta_items')->restrictOnDelete();
            $table->foreign(['empresa_id', 'producto_id'], 'devolucion_items_empresa_producto_foreign')
                ->references(['empresa_id', 'id'])->on('productos')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE operaciones_inventario DROP CONSTRAINT operaciones_tipo_check');
        DB::statement("ALTER TABLE operaciones_inventario ADD CONSTRAINT operaciones_tipo_check CHECK (tipo IN ('entrada', 'salida', 'ajuste', 'traspaso', 'venta', 'compra', 'devolucion'))");
        DB::statement('ALTER TABLE ventas DROP CONSTRAINT ventas_check');
        DB::statement('ALTER TABLE ventas ADD CONSTRAINT ventas_check CHECK (total >= 0 AND total = subtotal - descuento_lineas - descuento_global AND cobrado_inicial >= 0 AND cobrado_inicial <= total AND devuelto >= 0 AND devuelto <= total AND reembolsado >= 0 AND reembolsado <= devuelto AND saldo_pendiente >= 0 AND saldo_pendiente <= total - devuelto)');
        DB::statement("ALTER TABLE compras ADD CONSTRAINT compras_check CHECK (total > 0 AND monto_efectivo >= 0 AND monto_electronico >= 0 AND total = monto_efectivo + monto_electronico AND metodo IN ('efectivo', 'electronico', 'mixto'))");
        DB::statement('ALTER TABLE compra_items ADD CONSTRAINT compra_items_check CHECK (cantidad > 0 AND costo_unitario >= 0 AND subtotal >= 0)');
        DB::statement("ALTER TABLE egresos ADD CONSTRAINT egresos_check CHECK (monto > 0 AND metodo IN ('efectivo', 'electronico'))");
        DB::statement("ALTER TABLE devoluciones ADD CONSTRAINT devoluciones_check CHECK (importe >= 0 AND deuda_reducida >= 0 AND reembolso >= 0 AND importe = deuda_reducida + reembolso AND reembolso_efectivo >= 0 AND reembolso_electronico >= 0 AND reembolso = reembolso_efectivo + reembolso_electronico AND ((reembolso = 0 AND reembolso_metodo IS NULL) OR (reembolso > 0 AND reembolso_metodo IN ('efectivo', 'electronico', 'mixto'))))");
        DB::statement('ALTER TABLE devolucion_items ADD CONSTRAINT devolucion_items_check CHECK (cantidad > 0 AND importe >= 0)');

        // Categorías base de egresos para las empresas existentes; las nuevas las reciben al crearse.
        $ahora = now();
        foreach (DB::table('empresas')->pluck('id') as $empresaId) {
            DB::table('categorias_egreso')->insertOrIgnore(array_map(fn (array $categoria) => $categoria + [
                'empresa_id' => $empresaId, 'activa' => true, 'created_at' => $ahora, 'updated_at' => $ahora,
            ], [
                ['nombre' => 'Sueldos', 'descripcion' => 'Personal'],
                ['nombre' => 'Alquiler', 'descripcion' => 'Locales'],
                ['nombre' => 'Servicios', 'descripcion' => 'Luz, internet, agua'],
                ['nombre' => 'Transporte', 'descripcion' => 'Logística'],
                ['nombre' => 'Otros', 'descripcion' => 'Varios'],
            ]));
        }
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE ventas DROP CONSTRAINT ventas_check');
        DB::statement('ALTER TABLE ventas ADD CONSTRAINT ventas_check CHECK (total >= 0 AND total = subtotal - descuento_lineas - descuento_global AND cobrado_inicial >= 0 AND cobrado_inicial <= total AND saldo_pendiente >= 0 AND saldo_pendiente <= total)');
        DB::statement('ALTER TABLE operaciones_inventario DROP CONSTRAINT operaciones_tipo_check');
        DB::statement("ALTER TABLE operaciones_inventario ADD CONSTRAINT operaciones_tipo_check CHECK (tipo IN ('entrada', 'salida', 'ajuste', 'traspaso', 'venta'))");

        Schema::dropIfExists('devolucion_items');
        Schema::dropIfExists('devoluciones');
        Schema::table('ventas', function (Blueprint $table) {
            $table->dropColumn(['devuelto', 'reembolsado']);
        });
        Schema::table('venta_items', function (Blueprint $table) {
            $table->dropUnique(['empresa_id', 'id']);
        });
        Schema::dropIfExists('egresos');
        Schema::dropIfExists('categorias_egreso');
        Schema::dropIfExists('compra_items');
        Schema::dropIfExists('compras');
        Schema::dropIfExists('proveedores');
        Schema::table('clientes', function (Blueprint $table) {
            $table->dropColumn('correo');
        });
    }
};
