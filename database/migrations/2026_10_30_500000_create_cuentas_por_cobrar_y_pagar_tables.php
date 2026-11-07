<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cuentas por cobrar y por pagar (docs/MoziStock_Cuentas_por_Cobrar_y_Pagar.md v1.1).
 *
 * - El saldo de una venta a crédito sigue viviendo solo en ventas.saldo_pendiente; las cuotas
 *   reparten ese mismo saldo (la suma de sus saldos es siempre el saldo de la venta).
 * - Un cobro posterior es un único registro de dinero (cobros) y su detalle de aplicación
 *   (cobro_aplicaciones). pagos_venta queda para la cuota inicial cobrada al confirmar.
 * - Una cuenta por pagar nace de una compra o egreso con saldo, o de una obligación manual,
 *   y es la única fuente de su saldo. Cada pago posterior se aplica a una sola cuenta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ventas', function (Blueprint $table) {
            // Vencimiento único del crédito. Excluyente con un plan de cuotas.
            $table->date('vencimiento')->nullable()->after('saldo_pendiente');
            $table->index(['empresa_id', 'vencimiento']);
        });

        Schema::create('venta_cuotas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->unsignedBigInteger('venta_id');
            $table->unsignedSmallInteger('numero');
            $table->date('vencimiento');
            $table->decimal('monto', 18, 2);
            // Solo lo reducen los cobros aplicados y las devoluciones.
            $table->decimal('saldo', 18, 2);
            $table->timestamps();

            $table->unique(['empresa_id', 'id']);
            $table->unique(['venta_id', 'numero']);
            $table->index(['empresa_id', 'venta_id']);
            $table->index(['empresa_id', 'vencimiento']);
            $table->foreign(['empresa_id', 'venta_id'], 'venta_cuotas_empresa_venta_foreign')
                ->references(['empresa_id', 'id'])->on('ventas')->restrictOnDelete();
        });

        // Cuenta por cobrar que no proviene de una venta de MoziStock (saldo inicial de cliente).
        Schema::create('cuentas_cobrar', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->unsignedBigInteger('numero');
            $table->unsignedBigInteger('sucursal_id');
            $table->unsignedBigInteger('cliente_id');
            $table->foreignId('usuario_id')->constrained('users')->restrictOnDelete();
            $table->string('concepto', 150);
            // Fecha de origen de la deuda: puede ser anterior a MoziStock (no mueve dinero).
            $table->date('fecha');
            $table->date('vencimiento')->nullable();
            $table->decimal('importe', 18, 2);
            $table->decimal('saldo_pendiente', 18, 2);
            $table->string('nota', 250)->nullable();
            $table->uuid('clave_idempotencia');
            $table->timestamps();

            $table->unique(['empresa_id', 'numero']);
            $table->unique(['empresa_id', 'clave_idempotencia']);
            $table->unique(['empresa_id', 'id']);
            $table->index(['empresa_id', 'cliente_id']);
            $table->index(['empresa_id', 'saldo_pendiente']);
            $table->foreign(['empresa_id', 'sucursal_id'], 'cuentas_cobrar_empresa_sucursal_foreign')
                ->references(['empresa_id', 'id'])->on('sucursales')->restrictOnDelete();
            $table->foreign(['empresa_id', 'cliente_id'], 'cuentas_cobrar_empresa_cliente_foreign')
                ->references(['empresa_id', 'id'])->on('clientes')->restrictOnDelete();
        });

        // Dinero recibido de un cliente después de la venta: un solo registro con su desglose
        // por medio y una entrada en movimientos_caja por medio (origen «cobro»).
        Schema::create('cobros', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->unsignedBigInteger('numero');
            $table->unsignedBigInteger('cliente_id')->nullable();
            $table->unsignedBigInteger('sucursal_id');
            $table->foreignId('recibido_por')->constrained('users')->restrictOnDelete();
            $table->string('metodo', 12);
            $table->decimal('monto', 18, 2);
            $table->decimal('monto_efectivo', 18, 2);
            $table->decimal('monto_electronico', 18, 2);
            $table->timestamp('fecha_cobro');
            $table->foreignId('caja_id')->nullable()->constrained('cajas')->nullOnDelete();
            $table->foreignId('turno_caja_id')->nullable()->constrained('turnos_caja')->nullOnDelete();
            // Efectivo recibido fuera de una caja de MoziStock (sin turno).
            $table->boolean('externo')->default(false);
            $table->string('modo', 10);
            $table->string('referencia', 100)->nullable();
            $table->string('nota', 150)->nullable();
            $table->string('comprobante_path')->nullable();
            $table->string('comprobante_mime', 60)->nullable();
            $table->uuid('clave_idempotencia');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['empresa_id', 'numero']);
            $table->unique(['empresa_id', 'clave_idempotencia']);
            $table->unique(['empresa_id', 'id']);
            $table->index(['empresa_id', 'cliente_id']);
            $table->index(['empresa_id', 'sucursal_id', 'fecha_cobro']);
            $table->foreign(['empresa_id', 'sucursal_id'], 'cobros_empresa_sucursal_foreign')
                ->references(['empresa_id', 'id'])->on('sucursales')->restrictOnDelete();
            $table->foreign(['empresa_id', 'cliente_id'], 'cobros_empresa_cliente_foreign')
                ->references(['empresa_id', 'id'])->on('clientes')->restrictOnDelete();
        });

        // Cómo se distribuyó un cobro. Los importes por medio son una asignación informativa
        // (efectivo primero); el dinero real está en el cobro y en movimientos_caja.
        Schema::create('cobro_aplicaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->unsignedBigInteger('cobro_id');
            $table->unsignedBigInteger('venta_id')->nullable();
            $table->unsignedBigInteger('venta_cuota_id')->nullable();
            $table->unsignedBigInteger('cuenta_cobrar_id')->nullable();
            $table->decimal('monto', 18, 2);
            $table->decimal('monto_efectivo', 18, 2);
            $table->decimal('monto_electronico', 18, 2);
            $table->timestamp('created_at')->useCurrent();

            $table->index('cobro_id');
            $table->index(['empresa_id', 'venta_id']);
            $table->index(['empresa_id', 'cuenta_cobrar_id']);
            $table->foreign(['empresa_id', 'cobro_id'], 'cobro_aplicaciones_empresa_cobro_foreign')
                ->references(['empresa_id', 'id'])->on('cobros')->restrictOnDelete();
            $table->foreign(['empresa_id', 'venta_id'], 'cobro_aplicaciones_empresa_venta_foreign')
                ->references(['empresa_id', 'id'])->on('ventas')->restrictOnDelete();
            $table->foreign(['empresa_id', 'venta_cuota_id'], 'cobro_aplicaciones_empresa_cuota_foreign')
                ->references(['empresa_id', 'id'])->on('venta_cuotas')->restrictOnDelete();
            $table->foreign(['empresa_id', 'cuenta_cobrar_id'], 'cobro_aplicaciones_empresa_cuenta_foreign')
                ->references(['empresa_id', 'id'])->on('cuentas_cobrar')->restrictOnDelete();
        });

        // Compras con condición de pago: contado, crédito o parcial. Lo pagado al confirmar.
        Schema::table('compras', function (Blueprint $table) {
            $table->string('condicion', 8)->default('contado')->after('total');
            $table->decimal('pagado', 18, 2)->default(0)->after('condicion');
            $table->date('vencimiento')->nullable()->after('pagado');
            $table->foreignId('caja_id')->nullable()->after('sucursal_id')->constrained('cajas')->nullOnDelete();
            $table->foreignId('turno_caja_id')->nullable()->after('caja_id')->constrained('turnos_caja')->nullOnDelete();
            $table->boolean('pago_externo')->default(false)->after('monto_electronico');
        });
        DB::statement('ALTER TABLE compras DROP CONSTRAINT compras_check');
        DB::statement('ALTER TABLE compras MODIFY metodo VARCHAR(12) NULL');
        DB::table('compras')->update(['pagado' => DB::raw('total'), 'condicion' => 'contado']);

        // Egresos con condición de pago, pago mixto y beneficiario.
        Schema::table('egresos', function (Blueprint $table) {
            $table->unsignedBigInteger('proveedor_id')->nullable()->after('categoria_egreso_id');
            $table->string('condicion', 9)->default('pagado')->after('monto');
            $table->decimal('pagado', 18, 2)->default(0)->after('condicion');
            $table->decimal('monto_efectivo', 18, 2)->default(0)->after('pagado');
            $table->decimal('monto_electronico', 18, 2)->default(0)->after('monto_efectivo');
            $table->date('vencimiento')->nullable()->after('monto_electronico');
            $table->foreignId('caja_id')->nullable()->after('sucursal_id')->constrained('cajas')->nullOnDelete();
            $table->foreignId('turno_caja_id')->nullable()->after('caja_id')->constrained('turnos_caja')->nullOnDelete();
            $table->boolean('pago_externo')->default(false)->after('vencimiento');

            $table->index(['empresa_id', 'proveedor_id']);
            $table->foreign(['empresa_id', 'proveedor_id'], 'egresos_empresa_proveedor_foreign')
                ->references(['empresa_id', 'id'])->on('proveedores')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE egresos DROP CONSTRAINT egresos_check');
        DB::statement('ALTER TABLE egresos MODIFY metodo VARCHAR(12) NULL');
        DB::table('egresos')->update([
            'condicion' => 'pagado',
            'pagado' => DB::raw('monto'),
            'monto_efectivo' => DB::raw("CASE WHEN metodo = 'efectivo' THEN monto ELSE 0 END"),
            'monto_electronico' => DB::raw("CASE WHEN metodo = 'electronico' THEN monto ELSE 0 END"),
        ]);

        // Obligación pendiente de pago. Su saldo solo lo reducen los pagos de esta cuenta.
        Schema::create('cuentas_pagar', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->unsignedBigInteger('numero');
            $table->string('origen', 7);
            $table->unsignedBigInteger('compra_id')->nullable();
            $table->unsignedBigInteger('egreso_id')->nullable();
            $table->unsignedBigInteger('sucursal_id');
            $table->unsignedBigInteger('proveedor_id');
            $table->foreignId('usuario_id')->constrained('users')->restrictOnDelete();
            $table->string('concepto', 150);
            // Solo obligaciones manuales: préstamo, impuesto, servicio, proveedor u otro.
            $table->string('tipo', 12)->nullable();
            $table->date('fecha');
            $table->date('vencimiento');
            // Total del documento de origen y lo que se pagó antes de nacer la deuda.
            $table->decimal('total_documento', 18, 2);
            $table->decimal('pagado_origen', 18, 2);
            $table->decimal('saldo_pendiente', 18, 2);
            $table->string('nota', 250)->nullable();
            $table->string('respaldo_path')->nullable();
            $table->string('respaldo_mime', 60)->nullable();
            $table->uuid('clave_idempotencia')->nullable();
            $table->timestamps();

            $table->unique(['empresa_id', 'numero']);
            $table->unique(['empresa_id', 'compra_id']);
            $table->unique(['empresa_id', 'egreso_id']);
            $table->unique(['empresa_id', 'clave_idempotencia']);
            $table->unique(['empresa_id', 'id']);
            $table->index(['empresa_id', 'proveedor_id']);
            $table->index(['empresa_id', 'saldo_pendiente', 'vencimiento']);
            $table->foreign(['empresa_id', 'compra_id'], 'cuentas_pagar_empresa_compra_foreign')
                ->references(['empresa_id', 'id'])->on('compras')->restrictOnDelete();
            $table->foreign(['empresa_id', 'egreso_id'], 'cuentas_pagar_empresa_egreso_foreign')
                ->references(['empresa_id', 'id'])->on('egresos')->restrictOnDelete();
            $table->foreign(['empresa_id', 'sucursal_id'], 'cuentas_pagar_empresa_sucursal_foreign')
                ->references(['empresa_id', 'id'])->on('sucursales')->restrictOnDelete();
            $table->foreign(['empresa_id', 'proveedor_id'], 'cuentas_pagar_empresa_proveedor_foreign')
                ->references(['empresa_id', 'id'])->on('proveedores')->restrictOnDelete();
        });

        // Pago posterior de una sola cuenta por pagar: una salida de caja por medio (origen «pago_cxp»).
        Schema::create('pagos_cuenta_pagar', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->unsignedBigInteger('cuenta_pagar_id');
            $table->unsignedBigInteger('sucursal_id');
            $table->foreignId('pagado_por')->constrained('users')->restrictOnDelete();
            $table->string('metodo', 12);
            $table->decimal('monto', 18, 2);
            $table->decimal('monto_efectivo', 18, 2);
            $table->decimal('monto_electronico', 18, 2);
            $table->timestamp('fecha_pago');
            $table->foreignId('caja_id')->nullable()->constrained('cajas')->nullOnDelete();
            $table->foreignId('turno_caja_id')->nullable()->constrained('turnos_caja')->nullOnDelete();
            $table->boolean('externo')->default(false);
            $table->string('referencia', 100)->nullable();
            $table->string('nota', 150)->nullable();
            $table->string('comprobante_path')->nullable();
            $table->string('comprobante_mime', 60)->nullable();
            $table->uuid('clave_idempotencia');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['empresa_id', 'clave_idempotencia']);
            $table->unique(['empresa_id', 'id']);
            $table->index(['empresa_id', 'cuenta_pagar_id']);
            $table->index(['empresa_id', 'sucursal_id', 'fecha_pago']);
            $table->foreign(['empresa_id', 'cuenta_pagar_id'], 'pagos_cxp_empresa_cuenta_foreign')
                ->references(['empresa_id', 'id'])->on('cuentas_pagar')->restrictOnDelete();
            $table->foreign(['empresa_id', 'sucursal_id'], 'pagos_cxp_empresa_sucursal_foreign')
                ->references(['empresa_id', 'id'])->on('sucursales')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE venta_cuotas ADD CONSTRAINT venta_cuotas_check CHECK (monto > 0 AND saldo >= 0 AND saldo <= monto)');
        DB::statement('ALTER TABLE cuentas_cobrar ADD CONSTRAINT cuentas_cobrar_check CHECK (importe > 0 AND saldo_pendiente >= 0 AND saldo_pendiente <= importe)');
        DB::statement("ALTER TABLE cobros ADD CONSTRAINT cobros_check CHECK (monto > 0 AND monto_efectivo >= 0 AND monto_electronico >= 0 AND monto = monto_efectivo + monto_electronico AND metodo IN ('efectivo', 'electronico', 'mixto') AND modo IN ('manual', 'automatico') AND (externo = 0 OR (monto_efectivo > 0 AND turno_caja_id IS NULL)))");
        DB::statement('ALTER TABLE cobro_aplicaciones ADD CONSTRAINT cobro_aplicaciones_check CHECK (monto > 0 AND monto_efectivo >= 0 AND monto_electronico >= 0 AND monto = monto_efectivo + monto_electronico AND ((venta_id IS NOT NULL AND cuenta_cobrar_id IS NULL) OR (venta_id IS NULL AND venta_cuota_id IS NULL AND cuenta_cobrar_id IS NOT NULL)))');
        DB::statement("ALTER TABLE compras ADD CONSTRAINT compras_check CHECK (total > 0 AND monto_efectivo >= 0 AND monto_electronico >= 0 AND pagado = monto_efectivo + monto_electronico AND pagado <= total AND ((condicion = 'contado' AND pagado = total) OR (condicion = 'credito' AND pagado = 0) OR (condicion = 'parcial' AND pagado > 0 AND pagado < total)) AND ((pagado = 0 AND metodo IS NULL) OR (pagado > 0 AND metodo IN ('efectivo', 'electronico', 'mixto'))) AND (condicion = 'contado' OR vencimiento IS NOT NULL))");
        DB::statement("ALTER TABLE egresos ADD CONSTRAINT egresos_check CHECK (monto > 0 AND monto_efectivo >= 0 AND monto_electronico >= 0 AND pagado = monto_efectivo + monto_electronico AND pagado <= monto AND ((condicion = 'pagado' AND pagado = monto) OR (condicion = 'pendiente' AND pagado = 0) OR (condicion = 'parcial' AND pagado > 0 AND pagado < monto)) AND ((pagado = 0 AND metodo IS NULL) OR (pagado > 0 AND metodo IN ('efectivo', 'electronico', 'mixto'))) AND (condicion = 'pagado' OR (vencimiento IS NOT NULL AND proveedor_id IS NOT NULL)))");
        DB::statement("ALTER TABLE cuentas_pagar ADD CONSTRAINT cuentas_pagar_check CHECK (total_documento > 0 AND pagado_origen >= 0 AND saldo_pendiente >= 0 AND pagado_origen + saldo_pendiente <= total_documento AND ((origen = 'compra' AND compra_id IS NOT NULL AND egreso_id IS NULL) OR (origen = 'egreso' AND egreso_id IS NOT NULL AND compra_id IS NULL) OR (origen = 'manual' AND compra_id IS NULL AND egreso_id IS NULL AND tipo IN ('proveedor', 'prestamo', 'impuesto', 'servicio', 'otro'))))");
        DB::statement("ALTER TABLE pagos_cuenta_pagar ADD CONSTRAINT pagos_cxp_check CHECK (monto > 0 AND monto_efectivo >= 0 AND monto_electronico >= 0 AND monto = monto_efectivo + monto_electronico AND metodo IN ('efectivo', 'electronico', 'mixto') AND (externo = 0 OR (monto_efectivo > 0 AND turno_caja_id IS NULL)))");
    }

    public function down(): void
    {
        Schema::dropIfExists('pagos_cuenta_pagar');
        Schema::dropIfExists('cuentas_pagar');

        DB::statement('ALTER TABLE egresos DROP CONSTRAINT egresos_check');
        Schema::table('egresos', function (Blueprint $table) {
            $table->dropForeign('egresos_empresa_proveedor_foreign');
            $table->dropIndex(['empresa_id', 'proveedor_id']);
            $table->dropConstrainedForeignId('turno_caja_id');
            $table->dropConstrainedForeignId('caja_id');
            $table->dropColumn(['proveedor_id', 'condicion', 'pagado', 'monto_efectivo', 'monto_electronico', 'vencimiento', 'pago_externo']);
        });
        DB::statement("ALTER TABLE egresos ADD CONSTRAINT egresos_check CHECK (monto > 0 AND metodo IN ('efectivo', 'electronico'))");

        DB::statement('ALTER TABLE compras DROP CONSTRAINT compras_check');
        Schema::table('compras', function (Blueprint $table) {
            $table->dropConstrainedForeignId('turno_caja_id');
            $table->dropConstrainedForeignId('caja_id');
            $table->dropColumn(['condicion', 'pagado', 'vencimiento', 'pago_externo']);
        });
        DB::statement("ALTER TABLE compras ADD CONSTRAINT compras_check CHECK (total > 0 AND monto_efectivo >= 0 AND monto_electronico >= 0 AND total = monto_efectivo + monto_electronico AND metodo IN ('efectivo', 'electronico', 'mixto'))");

        Schema::dropIfExists('cobro_aplicaciones');
        Schema::dropIfExists('cobros');
        Schema::dropIfExists('cuentas_cobrar');
        Schema::dropIfExists('venta_cuotas');

        Schema::table('ventas', function (Blueprint $table) {
            $table->dropIndex(['empresa_id', 'vencimiento']);
            $table->dropColumn('vencimiento');
        });
    }
};
