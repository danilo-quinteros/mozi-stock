<?php

namespace App\Domain\Ventas\Actions;

use App\Domain\Auditoria\Auditor;
use App\Domain\Caja\CajaOperacion;
use App\Domain\Caja\LibroCaja;
use App\Domain\Despachos\Actions\CancelarEntregaDevuelta;
use App\Domain\Despachos\HistorialEntrega;
use App\Domain\Inventario\LibroStock;
use App\Domain\Tenancy\Numerador;
use App\Domain\Ventas\CalculadoraDevolucion;
use App\Domain\Ventas\ValidadorPago;
use App\Enums\EstadoEntrega;
use App\Enums\EstadoFactura;
use App\Enums\TipoOperacionInventario;
use App\Models\Devolucion;
use App\Models\Scopes\EmpresaScope;
use App\Models\SiatFactura;
use App\Models\Sucursal;
use App\Models\User;
use App\Models\Venta;
use App\Models\VentaCuota;
use App\Models\VentaItem;
use App\Support\Decimal;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Devolución total o parcial de una venta (especificación § 3.9 y § 5.5; T21). En una
 * transacción, con la venta bloqueada: valida cantidades contra lo vendido y lo ya devuelto,
 * calcula el importe a precio neto (CalculadoraDevolucion), reduce primero el saldo del
 * crédito y solo el resto se reembolsa, con una salida de caja por medio y la fecha y hora
 * actuales. Reingresa al stock solo las líneas marcadas y acumula devuelto/reembolsado en la
 * venta sin reescribirla. Stock y dinero se mueven en la sucursal de la venta.
 * Decisiones del propietario (23/10/2026): solo administradores; importe automático. El efectivo
 * reembolsado sale, como un egreso, de un turno abierto de esa sucursal que el usuario opera o,
 * si el administrador lo indica, fuera de caja (01/11/2026, § 18.7).
 *
 * Con una venta Por despachar (ESPECIFICACION_DESPACHOS.md § 5.5) todo lo devuelto reingresa al stock y,
 * si se devuelve todo, la venta pasa a Cancelado en la misma transacción. Con una venta En cocina
 * (ESPECIFICACION_COCINA.md § 5.6) la devolución total también la cancela, pero el reingreso no se fuerza:
 * la comida pudo haberse preparado. En los demás estados es una devolución normal y el estado de entrega
 * no cambia.
 *
 * Las líneas de productos sin control de stock nunca reingresan, pida lo que pida la solicitud
 * (ESPECIFICACION_PRODUCTOS_SIN_STOCK.md § 4.3); si ninguna reingresa, la devolución queda sin operación.
 */
final class RegistrarDevolucion
{
    public function __construct(
        private readonly LibroStock $libro,
        private readonly LibroCaja $caja,
        private readonly CajaOperacion $cajas,
        private readonly Numerador $numerador,
        private readonly Auditor $auditor,
        private readonly HistorialEntrega $historial,
    ) {}

    /**
     * @param  array{lineas: array<int|string, array{cantidad?: mixed, reingresa?: bool}>, motivo: string, metodo: string|null, efectivo: string|null, electronico: string|null, externo?: bool, turno_id?: int|null, clave: string}  $datos  lineas por id de línea de venta
     */
    public function ejecutar(User $actor, Venta $venta, array $datos): Devolucion
    {
        Gate::forUser($actor)->authorize('devolver', $venta);
        $empresaId = (int) $actor->empresa_id;

        if (! Str::isUuid($datos['clave'])) {
            throw ValidationException::withMessages(['lineas' => __('La devolución no tiene una clave válida. Recarga la página.')]);
        }

        if (($previa = $this->existente($empresaId, $datos['clave'])) !== null) {
            return $previa;
        }

        $motivo = trim($datos['motivo']);

        if ($motivo === '' || mb_strlen($motivo) > 250) {
            throw ValidationException::withMessages(['motivo' => __('Escribe el motivo de la devolución (hasta 250 caracteres).')]);
        }

        $items = VentaItem::query()->where('venta_id', $venta->id)->orderBy('orden')->orderBy('id')->get()->keyBy('id');
        $sinControl = DB::table('productos')->where('empresa_id', $empresaId)->where('controla_stock', false)
            ->whereIn('id', $items->pluck('producto_id')->unique()->values()->all())->pluck('id')->map(fn ($id) => (int) $id)->all();
        // Por despachar, todo lo devuelto reingresa (se vuelve a comprobar con la venta bloqueada), salvo lo que no controla stock.
        $reingresan = $items->filter(fn (VentaItem $item) => ! in_array($item->producto_id, $sinControl, true)
            && ($venta->estado_entrega === EstadoEntrega::PorDespachar || (bool) ($datos['lineas'][$item->id]['reingresa'] ?? false)))
            ->pluck('producto_id')->unique();
        // Con lotes y vencimientos, lo devuelto vuelve a los lotes de los que salió la venta (§ 5.5): sus saldos ya
        // existen en la sucursal, así que aquí solo hacen falta las filas de stock_niveles.
        $this->libro->asegurarNiveles($empresaId, array_values($reingresan->map(fn ($productoId) => [
            'sucursal_id' => $venta->sucursal_id, 'producto_id' => (int) $productoId,
        ])->all()));

        try {
            return DB::transaction(fn (): Devolucion => $this->registrar($actor, $venta->id, $items, $datos, $motivo), attempts: 3);
        } catch (UniqueConstraintViolationException $e) {
            return $this->existente($empresaId, $datos['clave']) ?? throw $e;
        }
    }

    /**
     * Cantidad ya devuelta de cada línea de una venta.
     *
     * @param  list<int>  $itemIds
     * @return array<int, string>
     */
    public static function devueltoPorLinea(int $empresaId, array $itemIds): array
    {
        return DB::table('devolucion_items')
            ->where('empresa_id', $empresaId)
            ->whereIn('venta_item_id', $itemIds)
            ->groupBy('venta_item_id')
            ->selectRaw('venta_item_id, SUM(cantidad) AS cantidad')
            ->pluck('cantidad', 'venta_item_id')
            ->map(fn ($cantidad) => (string) $cantidad)
            ->all();
    }

    /**
     * @param  Collection<int, VentaItem>  $items
     * @param  array{lineas: array<int|string, array{cantidad?: mixed, reingresa?: bool}>, motivo: string, metodo: string|null, efectivo: string|null, electronico: string|null, externo?: bool, turno_id?: int|null, clave: string}  $datos
     */
    private function registrar(User $actor, int $ventaId, Collection $items, array $datos, string $motivo): Devolucion
    {
        $venta = Venta::query()->whereKey($ventaId)->lockForUpdate()->firstOrFail();

        // Decisión D4: con una factura que existe o puede existir en el SIN no se devuelve. Total: se anula
        // primero la factura; parcial: requiere la nota de crédito-débito, que todavía no está disponible.
        if (SiatFactura::query()->where('venta_vigente', $venta->id)
            ->whereIn('estado', [EstadoFactura::Pendiente, EstadoFactura::Validada, EstadoFactura::Observada])->exists()) {
            throw ValidationException::withMessages(['lineas' => __('La venta tiene una factura vigente. Para una devolución total, anula primero la factura; las devoluciones parciales de ventas facturadas esperan la nota de crédito-débito.')]);
        }

        if (! Sucursal::query()->whereKey($venta->sucursal_id)->where('activa', true)->exists()) {
            throw ValidationException::withMessages(['lineas' => __('La sucursal de la venta está inactiva; actívala para registrar la devolución.')]);
        }

        // Despachos § 5.5: antes del despacho la mercadería nunca salió del almacén, así que siempre reingresa.
        $porDespachar = $venta->estado_entrega === EstadoEntrega::PorDespachar;
        // Despachos § 5.5 y cocina § 5.6: devuelta por completo antes de llegar al cliente, se cancela.
        $sinEntregar = $porDespachar || $venta->estado_entrega === EstadoEntrega::EnCocina;
        $estadoAnterior = $venta->estado_entrega;
        $lineas = $this->prepararLineas($venta, $items, $datos['lineas'], $porDespachar);
        $importe = array_reduce($lineas, fn (string $suma, array $l) => Decimal::sumar($suma, $l['importe'], 2), '0.00');
        $deuda = Decimal::comparar($importe, $venta->saldo_pendiente, 2) <= 0 ? $importe : $venta->saldo_pendiente;
        $reembolso = Decimal::restar($importe, $deuda, Decimal::ESCALA_DINERO);
        $pago = Decimal::comparar($reembolso, '0', 2) > 0
            ? ValidadorPago::exacto($reembolso, $datos['metodo'], $datos['efectivo'], $datos['electronico'], 'el reembolso')
            : null;
        $caja = $pago === null ? ['caja_id' => null, 'turno_id' => null, 'externo' => false]
            : $this->cajas->resolver($actor, $venta->sucursal_id, $pago['efectivo'], (bool) ($datos['externo'] ?? false), 'externo', isset($datos['turno_id']) ? (int) $datos['turno_id'] : null);
        $fecha = Carbon::now();
        $cambios = [];
        // Lotes (§ 5.5): LibroLotes lee el reparto de la venta y lo ya devuelto antes, con la venta bloqueada.
        $origen = [
            'salida' => (int) $venta->operacion_inventario_id,
            'previas' => array_values(Devolucion::withoutGlobalScope(EmpresaScope::class)->where('empresa_id', $venta->empresa_id)
                ->where('venta_id', $venta->id)->whereNotNull('operacion_inventario_id')
                ->pluck('operacion_inventario_id')->map(fn ($id): int => (int) $id)->all()),
        ];

        foreach (array_filter($lineas, fn (array $l) => $l['reingresa']) as $linea) {
            $clave = $linea['producto_id'];
            $cambios[$clave] = [
                'sucursal_id' => $venta->sucursal_id,
                'producto_id' => $linea['producto_id'],
                'cantidad' => Decimal::sumar($cambios[$clave]['cantidad'] ?? '0', $linea['cantidad']),
                'nombre' => $linea['descripcion'],
                'devolucion' => $origen,
            ];
        }

        $operacion = $cambios === [] ? null : $this->libro->aplicar($actor, [
            'tipo' => TipoOperacionInventario::Devolucion,
            'motivo' => null,
            'sucursal_id' => $venta->sucursal_id,
            'sucursal_destino_id' => null,
            'nota' => mb_substr('Venta '.$venta->codigo().' · '.$motivo, 0, 250),
            'clave' => $datos['clave'],
        ], array_values($cambios));

        $devolucion = new Devolucion;
        $devolucion->empresa_id = $venta->empresa_id;
        $devolucion->numero = $operacion !== null
            ? $operacion->numero
            : $this->numerador->siguiente($venta->empresa_id, TipoOperacionInventario::Devolucion->serie());
        $devolucion->venta_id = $venta->id;
        $devolucion->sucursal_id = $venta->sucursal_id;
        $devolucion->usuario_id = $actor->id;
        $devolucion->operacion_inventario_id = $operacion?->id;
        $devolucion->fecha = $fecha;
        $devolucion->motivo = $motivo;
        $devolucion->importe = $importe;
        $devolucion->deuda_reducida = $deuda;
        $devolucion->reembolso = $reembolso;
        $devolucion->reembolso_metodo = $pago === null ? null : $pago['metodo'];
        $devolucion->reembolso_efectivo = $pago === null ? '0.00' : $pago['efectivo'];
        $devolucion->reembolso_electronico = $pago === null ? '0.00' : $pago['electronico'];
        $devolucion->clave_idempotencia = $datos['clave'];
        $devolucion->save();

        DB::table('devolucion_items')->insert(array_map(fn (array $l) => [
            'empresa_id' => $venta->empresa_id,
            'devolucion_id' => $devolucion->id,
            'venta_item_id' => $l['venta_item_id'],
            'producto_id' => $l['producto_id'],
            'cantidad' => $l['cantidad'],
            'importe' => $l['importe'],
            'reingresa_stock' => $l['reingresa'],
            'created_at' => $fecha,
        ], $lineas));

        $antes = $venta->only(['saldo_pendiente', 'devuelto', 'reembolsado']);
        $venta->saldo_pendiente = Decimal::restar($venta->saldo_pendiente, $deuda, Decimal::ESCALA_DINERO);
        $venta->devuelto = Decimal::sumar($venta->devuelto, $importe, Decimal::ESCALA_DINERO);
        $venta->reembolsado = Decimal::sumar($venta->reembolsado, $reembolso, Decimal::ESCALA_DINERO);
        // Devuelta por completo antes del despacho o de salir de cocina: queda Cancelado y el despacho o la
        // entrega que espera el bloqueo la encontrará cancelada. Una devolución parcial no cambia el estado.
        $cancelada = $sinEntregar && CancelarEntregaDevuelta::devueltaPorCompleto($venta);

        if ($cancelada) {
            $venta->estado_entrega = EstadoEntrega::Cancelado;
        }

        $venta->save();
        $this->reducirCuotas($venta, $deuda);

        if ($cancelada) {
            $this->historial->registrar($venta, $estadoAnterior, EstadoEntrega::Cancelado, $actor, $fecha,
                mb_substr(__('Devolución :codigo', ['codigo' => $devolucion->codigo()]), 0, 250));
        }

        if ($pago !== null) {
            $this->caja->registrar($actor, $venta->sucursal_id, 'egreso',
                ['efectivo' => $pago['efectivo'], 'electronico' => $pago['electronico']], 'devolucion', $devolucion->id, $fecha,
                $caja['caja_id'], $caja['turno_id']);
        }

        $this->auditor->registrar('venta.devolucion', $venta, $actor, $venta->empresa_id, $antes, [
            ...$venta->only(['saldo_pendiente', 'devuelto', 'reembolsado']),
            'devolucion' => $devolucion->codigo(), 'importe' => $importe, 'deuda_reducida' => $deuda,
            'reembolso' => $reembolso, 'metodo' => $pago === null ? null : $pago['metodo']->value, 'lineas' => count($lineas),
            'turno_caja_id' => $caja['turno_id'], 'externo' => $caja['externo'],
        ] + ($cancelada ? ['estado_entrega' => EstadoEntrega::Cancelado->value] : []));

        return $devolucion;
    }

    /**
     * Valida cada cantidad contra lo vendido menos lo ya devuelto (leído con la venta
     * bloqueada) y calcula su importe neto. Las líneas sin cantidad se ignoran.
     *
     * @param  Collection<int, VentaItem>  $items
     * @param  array<int|string, array{cantidad?: mixed, reingresa?: bool}>  $solicitadas
     * @param  bool  $reingresoForzado  venta Por despachar: todas las líneas que controlan stock reingresan, pida lo que pida la solicitud
     * @return list<array{venta_item_id: int, producto_id: int, descripcion: string, cantidad: string, importe: string, reingresa: bool}>
     */
    private function prepararLineas(Venta $venta, Collection $items, array $solicitadas, bool $reingresoForzado = false): array
    {
        $previas = self::devueltoPorLinea($venta->empresa_id, array_values($items->keys()->all()));
        $netos = CalculadoraDevolucion::netosPorLinea($items->map(fn (VentaItem $i) => $i->total)->all(), $venta->descuento_global);
        $enteras = DB::table('productos')
            ->join('unidades_medida', 'unidades_medida.id', '=', 'productos.unidad_medida_id')
            ->where('productos.empresa_id', $venta->empresa_id)
            ->whereIn('productos.id', $items->pluck('producto_id')->all())
            ->where(function ($query) {
                $query->where('productos.tipo_venta', 'unidad')
                    ->orWhereNull('productos.tipo_venta')
                    ->orWhere('unidades_medida.admite_decimales', false);
            })
            ->pluck('productos.id')->map(fn ($id) => (int) $id)->all();
        // Marca leída con bloqueo compartido, como en la venta: no cambia mientras se registra la devolución.
        $controlan = DB::table('productos')
            ->where('empresa_id', $venta->empresa_id)
            ->whereIn('id', $items->pluck('producto_id')->unique()->values()->all())
            ->orderBy('id')
            ->sharedLock()
            ->pluck('controla_stock', 'id')
            ->mapWithKeys(fn ($controla, $id) => [(int) $id => (bool) $controla])
            ->all();
        $lineas = [];
        $errores = [];

        foreach ($solicitadas as $itemId => $solicitud) {
            $valor = $solicitud['cantidad'] ?? '';
            $texto = is_scalar($valor) ? trim((string) $valor) : '';

            if ($texto === '') {
                continue;
            }

            $item = $items->get((int) $itemId);

            if ($item === null) {
                $errores[] = __('Una de las líneas no pertenece a esta venta.');

                continue;
            }

            $cantidad = Decimal::normalizar($texto, Decimal::ESCALA_CANTIDAD);
            $devueltas = $previas[$item->id] ?? '0';
            $disponible = Decimal::restar($item->cantidad, $devueltas);

            if ($cantidad === null || Decimal::comparar($cantidad, '0') < 0) {
                $errores[] = __(':producto: la cantidad no es válida (hasta 3 decimales).', ['producto' => $item->descripcion]);
            } elseif (Decimal::comparar($cantidad, '0') === 0) {
                continue;
            } elseif (Decimal::comparar($cantidad, $disponible) > 0) {
                $errores[] = __(':producto: puedes devolver hasta :cantidad.', ['producto' => $item->descripcion, 'cantidad' => Decimal::formatear($disponible)]);
            } elseif (in_array($item->producto_id, $enteras, true) && ! Decimal::esEntero($cantidad)) {
                $errores[] = __(':producto: se mide en unidades enteras.', ['producto' => $item->descripcion]);
            } else {
                $lineas[] = [
                    'venta_item_id' => $item->id,
                    'producto_id' => $item->producto_id,
                    'descripcion' => $item->descripcion,
                    'cantidad' => $cantidad,
                    'importe' => CalculadoraDevolucion::importe($netos[$item->id], $item->cantidad, $devueltas, $cantidad),
                    // Sin control de stock no hay saldo al que volver (§ 4.3).
                    'reingresa' => ($controlan[$item->producto_id] ?? true) && ($reingresoForzado || (bool) ($solicitud['reingresa'] ?? false)),
                ];
            }
        }

        if ($errores !== []) {
            throw ValidationException::withMessages(['lineas' => array_values(array_unique($errores))]);
        }

        if ($lineas === []) {
            throw ValidationException::withMessages(['lineas' => __('Indica la cantidad devuelta de al menos un producto.')]);
        }

        return $lineas;
    }

    /**
     * Lee la base en cada llamada: tras un choque de clave la devolución ya existe.
     *
     * @phpstan-impure
     */
    private function existente(int $empresaId, string $clave): ?Devolucion
    {
        return Devolucion::withoutGlobalScope(EmpresaScope::class)
            ->where('empresa_id', $empresaId)->where('clave_idempotencia', $clave)->first();
    }

    /**
     * Con plan de cuotas, la deuda reducida sale de las cuotas desde la última hacia la primera,
     * para que la suma de sus saldos siga siendo el saldo de la venta.
     */
    private function reducirCuotas(Venta $venta, string $deuda): void
    {
        $restante = $deuda;
        $cuotas = VentaCuota::query()->where('venta_id', $venta->id)->where('saldo', '>', 0)
            ->orderByDesc('numero')->lockForUpdate()->get();

        foreach ($cuotas as $cuota) {
            if (Decimal::comparar($restante, '0', 2) <= 0) {
                break;
            }

            $parte = Decimal::comparar($cuota->saldo, $restante, 2) < 0 ? $cuota->saldo : $restante;
            $cuota->saldo = Decimal::restar($cuota->saldo, $parte, Decimal::ESCALA_DINERO);
            $cuota->save();
            $restante = Decimal::restar($restante, $parte, Decimal::ESCALA_DINERO);
        }
    }
}
