<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Destino de FK compuestas (empresa_id, vendedor_id): el vendedor siempre es de la misma empresa.
        Schema::table('users', function (Blueprint $table) {
            $table->unique(['empresa_id', 'id']);
        });

        Schema::create('clientes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->string('nombre', 150);
            // CI o NIT; identifica al cliente en ventas a crédito.
            $table->string('documento', 30)->nullable();
            $table->string('telefono', 40)->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();

            $table->unique(['empresa_id', 'documento']);
            $table->unique(['empresa_id', 'id']);
            $table->index(['empresa_id', 'nombre']);
        });

        // Cotización o pedido pendiente. Nunca mueve stock ni dinero.
        Schema::create('pedidos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->unsignedBigInteger('numero');
            $table->unsignedBigInteger('sucursal_id');
            $table->unsignedBigInteger('cliente_id')->nullable();
            $table->unsignedBigInteger('vendedor_id');
            $table->foreignId('registrado_por')->constrained('users')->restrictOnDelete();
            $table->string('estado', 20)->default('pendiente');
            $table->decimal('subtotal', 18, 2);
            $table->decimal('descuento_lineas', 18, 2);
            $table->string('descuento_global_tipo', 3)->default('bs');
            $table->decimal('descuento_global_valor', 18, 2)->default(0);
            $table->decimal('descuento_global', 18, 2);
            $table->decimal('total', 18, 2);
            $table->text('nota')->nullable();
            // Intención de pago: solo precarga la confirmación. No es un cobro.
            $table->string('pago_metodo', 12)->nullable();
            $table->boolean('pago_en_cuotas')->default(false);
            $table->decimal('pago_cuota_inicial', 18, 2)->nullable();
            $table->decimal('pago_efectivo', 18, 2)->nullable();
            $table->decimal('pago_electronico', 18, 2)->nullable();
            $table->uuid('clave_idempotencia');
            $table->timestamp('cancelado_en')->nullable();
            $table->foreignId('cancelado_por')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['empresa_id', 'numero']);
            $table->unique(['empresa_id', 'clave_idempotencia']);
            $table->unique(['empresa_id', 'id']);
            $table->index(['empresa_id', 'estado', 'created_at']);
            $table->index(['empresa_id', 'sucursal_id', 'created_at']);
            $table->foreign(['empresa_id', 'sucursal_id'], 'pedidos_empresa_sucursal_foreign')
                ->references(['empresa_id', 'id'])->on('sucursales')->restrictOnDelete();
            $table->foreign(['empresa_id', 'cliente_id'], 'pedidos_empresa_cliente_foreign')
                ->references(['empresa_id', 'id'])->on('clientes')->restrictOnDelete();
            $table->foreign(['empresa_id', 'vendedor_id'], 'pedidos_empresa_vendedor_foreign')
                ->references(['empresa_id', 'id'])->on('users')->restrictOnDelete();
        });

        Schema::create('pedido_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->unsignedBigInteger('pedido_id');
            $table->unsignedBigInteger('producto_id');
            $table->unsignedInteger('orden');
            $table->decimal('cantidad', 18, 3);
            $table->string('tipo_precio', 12);
            // Precio de lista tomado al cotizar: se conserva aunque cambie el catálogo.
            $table->decimal('precio_unitario', 18, 2);
            $table->string('descuento_tipo', 3)->default('bs');
            $table->decimal('descuento_valor', 18, 2)->default(0);
            $table->decimal('descuento', 18, 2);
            $table->decimal('subtotal', 18, 2);
            $table->decimal('total', 18, 2);
            $table->timestamps();

            $table->index(['pedido_id', 'orden']);
            $table->foreign(['empresa_id', 'pedido_id'], 'pedido_items_empresa_pedido_foreign')
                ->references(['empresa_id', 'id'])->on('pedidos')->restrictOnDelete();
            $table->foreign(['empresa_id', 'producto_id'], 'pedido_items_empresa_producto_foreign')
                ->references(['empresa_id', 'id'])->on('productos')->restrictOnDelete();
        });

        // Venta confirmada e inmutable: snapshot del pedido. Una sola venta por pedido.
        Schema::create('ventas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->unsignedBigInteger('pedido_id');
            $table->unsignedBigInteger('numero');
            $table->unsignedBigInteger('sucursal_id');
            $table->unsignedBigInteger('cliente_id')->nullable();
            $table->unsignedBigInteger('vendedor_id');
            $table->foreignId('confirmado_por')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('operacion_inventario_id');
            $table->timestamp('fecha');
            $table->decimal('subtotal', 18, 2);
            $table->decimal('descuento_lineas', 18, 2);
            $table->decimal('descuento_global', 18, 2);
            $table->decimal('total', 18, 2);
            $table->decimal('cobrado_inicial', 18, 2);
            // Total menos cobros aplicados. Solo lo modifican la confirmación y los abonos.
            $table->decimal('saldo_pendiente', 18, 2);
            $table->timestamps();

            $table->unique(['empresa_id', 'pedido_id']);
            $table->unique(['empresa_id', 'numero']);
            $table->unique(['empresa_id', 'id']);
            $table->index(['empresa_id', 'sucursal_id', 'fecha']);
            $table->index(['empresa_id', 'vendedor_id', 'fecha']);
            $table->index(['empresa_id', 'saldo_pendiente']);
            $table->foreign(['empresa_id', 'pedido_id'], 'ventas_empresa_pedido_foreign')
                ->references(['empresa_id', 'id'])->on('pedidos')->restrictOnDelete();
            $table->foreign(['empresa_id', 'sucursal_id'], 'ventas_empresa_sucursal_foreign')
                ->references(['empresa_id', 'id'])->on('sucursales')->restrictOnDelete();
            $table->foreign(['empresa_id', 'cliente_id'], 'ventas_empresa_cliente_foreign')
                ->references(['empresa_id', 'id'])->on('clientes')->restrictOnDelete();
            $table->foreign(['empresa_id', 'vendedor_id'], 'ventas_empresa_vendedor_foreign')
                ->references(['empresa_id', 'id'])->on('users')->restrictOnDelete();
            $table->foreign(['empresa_id', 'operacion_inventario_id'], 'ventas_empresa_operacion_foreign')
                ->references(['empresa_id', 'id'])->on('operaciones_inventario')->restrictOnDelete();
        });

        Schema::create('venta_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->unsignedBigInteger('venta_id');
            $table->unsignedBigInteger('producto_id');
            $table->unsignedInteger('orden');
            $table->string('descripcion', 150);
            $table->string('sku', 40);
            $table->string('unidad', 15);
            $table->decimal('cantidad', 18, 3);
            $table->string('tipo_precio', 12);
            $table->decimal('precio_unitario', 18, 2);
            $table->string('descuento_tipo', 3);
            $table->decimal('descuento_valor', 18, 2);
            $table->decimal('descuento', 18, 2);
            $table->decimal('subtotal', 18, 2);
            $table->decimal('total', 18, 2);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['venta_id', 'orden']);
            $table->index(['empresa_id', 'producto_id']);
            $table->foreign(['empresa_id', 'venta_id'], 'venta_items_empresa_venta_foreign')
                ->references(['empresa_id', 'id'])->on('ventas')->restrictOnDelete();
            $table->foreign(['empresa_id', 'producto_id'], 'venta_items_empresa_producto_foreign')
                ->references(['empresa_id', 'id'])->on('productos')->restrictOnDelete();
        });

        // Cobro aplicado a una venta: cuota inicial o abono. Un solo registro con el total y su
        // desglose por medio (V3). Un pago de Bs 0 no se registra.
        Schema::create('pagos_venta', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->unsignedBigInteger('venta_id');
            $table->unsignedBigInteger('sucursal_id');
            $table->foreignId('recibido_por')->constrained('users')->restrictOnDelete();
            $table->string('tipo', 10);
            $table->string('metodo', 12);
            $table->decimal('monto', 18, 2);
            $table->decimal('monto_efectivo', 18, 2);
            $table->decimal('monto_electronico', 18, 2);
            $table->timestamp('fecha_cobro');
            $table->uuid('clave_idempotencia');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['empresa_id', 'clave_idempotencia']);
            $table->unique(['empresa_id', 'id']);
            $table->index(['empresa_id', 'venta_id']);
            $table->index(['empresa_id', 'sucursal_id', 'fecha_cobro']);
            $table->foreign(['empresa_id', 'venta_id'], 'pagos_empresa_venta_foreign')
                ->references(['empresa_id', 'id'])->on('ventas')->restrictOnDelete();
            $table->foreign(['empresa_id', 'sucursal_id'], 'pagos_empresa_sucursal_foreign')
                ->references(['empresa_id', 'id'])->on('sucursales')->restrictOnDelete();
        });

        // Libro monetario: ÚNICA fuente de los reportes de dinero. Una fila por medio de pago
        // y evento de origen; nunca se suma pagos_venta junto con esta tabla.
        Schema::create('movimientos_caja', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->unsignedBigInteger('sucursal_id');
            $table->string('sentido', 7);
            $table->string('metodo', 12);
            $table->decimal('monto', 18, 2);
            $table->string('origen_tipo', 30);
            $table->unsignedBigInteger('origen_id');
            $table->timestamp('fecha_operacion');
            $table->foreignId('usuario_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['empresa_id', 'origen_tipo', 'origen_id', 'metodo'], 'movimientos_caja_origen_unique');
            $table->index(['empresa_id', 'sucursal_id', 'fecha_operacion']);
            $table->index(['empresa_id', 'fecha_operacion']);
            $table->foreign(['empresa_id', 'sucursal_id'], 'caja_empresa_sucursal_foreign')
                ->references(['empresa_id', 'id'])->on('sucursales')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE operaciones_inventario DROP CONSTRAINT operaciones_tipo_check');
        DB::statement("ALTER TABLE operaciones_inventario ADD CONSTRAINT operaciones_tipo_check CHECK (tipo IN ('entrada', 'salida', 'ajuste', 'traspaso', 'venta'))");
        DB::statement("ALTER TABLE pedidos ADD CONSTRAINT pedidos_check CHECK (estado IN ('pendiente', 'vendido', 'cancelado') AND subtotal >= 0 AND descuento_lineas >= 0 AND descuento_global >= 0 AND total >= 0 AND total = subtotal - descuento_lineas - descuento_global AND descuento_global_tipo IN ('bs', 'pct'))");
        DB::statement("ALTER TABLE pedido_items ADD CONSTRAINT pedido_items_check CHECK (cantidad > 0 AND precio_unitario >= 0 AND descuento >= 0 AND descuento <= subtotal AND total = subtotal - descuento AND tipo_precio IN ('consumidor', 'minorista', 'mayorista') AND descuento_tipo IN ('bs', 'pct'))");
        DB::statement('ALTER TABLE ventas ADD CONSTRAINT ventas_check CHECK (total >= 0 AND total = subtotal - descuento_lineas - descuento_global AND cobrado_inicial >= 0 AND cobrado_inicial <= total AND saldo_pendiente >= 0 AND saldo_pendiente <= total)');
        DB::statement('ALTER TABLE venta_items ADD CONSTRAINT venta_items_check CHECK (cantidad > 0 AND descuento >= 0 AND total = subtotal - descuento)');
        DB::statement("ALTER TABLE pagos_venta ADD CONSTRAINT pagos_venta_check CHECK (monto > 0 AND monto_efectivo >= 0 AND monto_electronico >= 0 AND monto = monto_efectivo + monto_electronico AND tipo IN ('inicial', 'abono') AND metodo IN ('efectivo', 'electronico', 'mixto'))");
        DB::statement("ALTER TABLE movimientos_caja ADD CONSTRAINT movimientos_caja_check CHECK (monto > 0 AND sentido IN ('ingreso', 'egreso') AND metodo IN ('efectivo', 'electronico'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('movimientos_caja');
        Schema::dropIfExists('pagos_venta');
        Schema::dropIfExists('venta_items');
        Schema::dropIfExists('ventas');
        Schema::dropIfExists('pedido_items');
        Schema::dropIfExists('pedidos');
        Schema::dropIfExists('clientes');

        DB::statement('ALTER TABLE operaciones_inventario DROP CONSTRAINT operaciones_tipo_check');
        DB::statement("ALTER TABLE operaciones_inventario ADD CONSTRAINT operaciones_tipo_check CHECK (tipo IN ('entrada', 'salida', 'ajuste', 'traspaso'))");

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['empresa_id', 'id']);
        });
    }
};
