<?php

namespace App\Domain\Compras\Actions;

use App\Domain\Auditoria\Auditor;
use App\Domain\Caja\CajaOperacion;
use App\Domain\Caja\LibroCaja;
use App\Domain\Compras\CalculadoraCompra;
use App\Domain\Creditos\Actions\RegistrarCuentaCobrar;
use App\Domain\CuentasPagar\AbrirCuentaPagar;
use App\Domain\Inventario\LibroStock;
use App\Domain\Tenancy\ResolverSucursal;
use App\Domain\Ventas\ValidadorPago;
use App\Enums\TipoOperacionInventario;
use App\Models\Compra;
use App\Models\Proveedor;
use App\Models\Scopes\EmpresaScope;
use App\Models\User;
use App\Support\Decimal;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Compra (especificación § 3.7, V2 § 2, V3 § 1 y Cuentas por pagar § 13). En una transacción:
 * ingresa el stock en la sucursal (operación tipo compra con correlativo COM), guarda la compra
 * con su detalle, registra una sola salida de caja por lo pagado al confirmar, por medio, y si
 * queda saldo (crédito o parcial) abre su cuenta por pagar. No crea egresos ni ajustes.
 *
 * | Condición | Pago al confirmar     | Cuenta por pagar      |
 * | Contado   | el total              | ninguna               |
 * | Crédito   | Bs 0                  | por el total          |
 * | Parcial   | 0 < pagado < total    | por total − pagado    |
 *
 * Proveedor obligatorio (§ 13.1); vencimiento obligatorio con saldo (§ 13.3). Decisiones del
 * propietario: solo administradores y siempre con la fecha y hora actuales (23/10/2026); el
 * efectivo sale de un turno abierto o, si el administrador lo indica, fuera de caja (30/10/2026).
 * Repetir la clave no repite la compra.
 */
final class RegistrarCompra
{
    public function __construct(
        private readonly LibroStock $libro,
        private readonly LibroCaja $caja,
        private readonly CajaOperacion $cajas,
        private readonly AbrirCuentaPagar $cuentas,
        private readonly ResolverSucursal $resolverSucursal,
        private readonly Auditor $auditor,
    ) {}

    /**
     * @param  array{sucursal_id: int|null, proveedor_id: int|null, referencia: string|null, lineas: array<int|string, array{cantidad?: mixed, costo?: mixed}>, condicion?: string|null, pagado?: string|null, vencimiento?: string|null, metodo: string|null, efectivo: string|null, electronico: string|null, externo?: bool, turno_id?: int|null, clave: string}  $datos
     */
    public function ejecutar(User $actor, array $datos): Compra
    {
        Gate::forUser($actor)->authorize('create', Compra::class);
        $empresaId = (int) $actor->empresa_id;

        if (! Str::isUuid($datos['clave'])) {
            throw ValidationException::withMessages(['lineas' => __('La compra no tiene una clave válida. Recarga la página.')]);
        }

        if (($previa = $this->existente($empresaId, $datos['clave'])) !== null) {
            return $previa;
        }

        $sucursal = $this->resolverSucursal->paraOperacion($actor, $datos['sucursal_id']);
        // El scope de empresa descarta proveedores de otra empresa.
        $proveedor = ($datos['proveedor_id'] === null ? null : Proveedor::query()->where('activo', true)->find($datos['proveedor_id']))
            ?? throw ValidationException::withMessages(['proveedor_id' => __('Selecciona un proveedor activo de tu empresa.')]);

        $referencia = trim((string) $datos['referencia']);

        if (mb_strlen($referencia) > 150) {
            throw ValidationException::withMessages(['referencia' => __('La referencia admite hasta 150 caracteres.')]);
        }

        $lineas = $this->prepararLineas($empresaId, $datos['lineas']);
        $total = array_reduce($lineas, fn (string $suma, array $l) => Decimal::sumar($suma, $l['subtotal'], 2), '0.00');

        if (Decimal::comparar($total, '0', 2) <= 0) {
            throw ValidationException::withMessages(['lineas' => __('El total de la compra debe ser mayor que Bs 0,00. Para ingresar stock sin costo usa Ajustes → Entrada.')]);
        }

        [$condicion, $pagado, $vencimiento] = self::condicion($datos, $total);
        $pago = Decimal::comparar($pagado, '0', 2) > 0
            ? ValidadorPago::exacto($pagado, $datos['metodo'], $datos['efectivo'], $datos['electronico'],
                $condicion === 'contado' ? 'total de la compra' : 'pago inicial de la compra')
            : null;
        $this->libro->asegurarNiveles($empresaId, array_map(
            fn (int $productoId) => ['sucursal_id' => $sucursal->id, 'producto_id' => $productoId], array_keys($lineas),
        ));

        try {
            return DB::transaction(function () use ($actor, $datos, $sucursal, $proveedor, $referencia, $lineas, $total, $condicion, $pagado, $vencimiento, $pago): Compra {
                $caja = $pago === null ? ['caja_id' => null, 'turno_id' => null, 'externo' => false]
                    : $this->cajas->resolver($actor, $sucursal->id, $pago['efectivo'], (bool) ($datos['externo'] ?? false), 'externo', isset($datos['turno_id']) ? (int) $datos['turno_id'] : null);
                $nota = 'Compra · '.$proveedor->nombre.($referencia !== '' ? ' · '.$referencia : '');
                $operacion = $this->libro->aplicar($actor, [
                    'tipo' => TipoOperacionInventario::Compra,
                    'motivo' => null,
                    'sucursal_id' => $sucursal->id,
                    'sucursal_destino_id' => null,
                    'nota' => mb_substr($nota, 0, 250),
                    'clave' => $datos['clave'],
                ], array_values(array_map(fn (array $l) => [
                    'sucursal_id' => $sucursal->id, 'producto_id' => $l['producto_id'], 'cantidad' => $l['cantidad'], 'nombre' => $l['nombre'],
                ], $lineas)));

                $compra = new Compra;
                $compra->empresa_id = (int) $actor->empresa_id;
                $compra->numero = $operacion->numero;
                $compra->sucursal_id = $sucursal->id;
                $compra->caja_id = $caja['caja_id'];
                $compra->turno_caja_id = $caja['turno_id'];
                $compra->proveedor_id = $proveedor->id;
                $compra->usuario_id = $actor->id;
                $compra->operacion_inventario_id = $operacion->id;
                $compra->fecha = Carbon::now();
                $compra->referencia = $referencia === '' ? null : $referencia;
                $compra->total = $total;
                $compra->condicion = $condicion;
                $compra->pagado = $pagado;
                $compra->vencimiento = $vencimiento === null ? null : Carbon::parse($vencimiento);
                $compra->metodo = $pago['metodo'] ?? null;
                $compra->monto_efectivo = $pago['efectivo'] ?? '0.00';
                $compra->monto_electronico = $pago['electronico'] ?? '0.00';
                $compra->pago_externo = $caja['externo'];
                $compra->clave_idempotencia = $datos['clave'];
                $compra->save();

                $orden = 0;
                foreach (array_chunk($lineas, 500) as $lote) {
                    DB::table('compra_items')->insert(array_map(fn (array $l) => [
                        'empresa_id' => $compra->empresa_id,
                        'compra_id' => $compra->id,
                        'producto_id' => $l['producto_id'],
                        'orden' => ++$orden,
                        'descripcion' => $l['nombre'],
                        'sku' => $l['sku'],
                        'unidad' => $l['unidad'],
                        'cantidad' => $l['cantidad'],
                        'costo_unitario' => $l['costo'],
                        'subtotal' => $l['subtotal'],
                        'created_at' => $compra->fecha,
                    ], $lote));
                }

                if ($pago !== null) {
                    $this->caja->registrar($actor, $sucursal->id, 'egreso',
                        ['efectivo' => $pago['efectivo'], 'electronico' => $pago['electronico']], 'compra', $compra->id, $compra->fecha,
                        $caja['caja_id'], $caja['turno_id']);
                }

                $cuenta = $this->cuentas->desdeCompra($actor, $compra, $compra->codigo().($referencia !== '' ? ' · '.$referencia : ''));

                $this->auditor->registrar('compra.registrada', $compra, $actor, $compra->empresa_id, despues: [
                    'compra' => $compra->codigo(), 'sucursal_id' => $sucursal->id, 'proveedor_id' => $proveedor->id,
                    'total' => $total, 'condicion' => $condicion, 'pagado' => $pagado, 'metodo' => $pago['metodo']->value ?? null,
                    'externo' => $caja['externo'], 'turno_caja_id' => $caja['turno_id'], 'cuenta_pagar' => $cuenta?->codigo(),
                    'lineas' => count($lineas),
                ]);

                return $compra;
            }, attempts: 3);
        } catch (UniqueConstraintViolationException $e) {
            return $this->existente($empresaId, $datos['clave']) ?? throw $e;
        }
    }

    /**
     * Condición de pago (§ 13.2 y § 13.3, § 14.1 para egresos): lo pagado al confirmar y el
     * vencimiento de la deuda. $condiciones nombra, en orden, el pago total, el pago nulo y el parcial.
     *
     * @param  array<string, mixed>  $datos
     * @param  array{0: string, 1: string, 2: string}  $condiciones
     * @return array{0: string, 1: string, 2: string|null}
     *
     * @throws ValidationException
     */
    public static function condicion(array $datos, string $total, array $condiciones = ['contado', 'credito', 'parcial']): array
    {
        $condicion = (string) ($datos['condicion'] ?? $condiciones[0]);

        if (! in_array($condicion, $condiciones, true)) {
            throw ValidationException::withMessages(['condicion' => __('Elige la condición de pago.')]);
        }

        $pagado = match ($condicion) {
            $condiciones[0] => Decimal::redondear($total),
            $condiciones[1] => '0.00',
            default => Decimal::normalizar(is_scalar($datos['pagado'] ?? null) ? (string) $datos['pagado'] : '', Decimal::ESCALA_DINERO),
        };

        if ($pagado === null || ($condicion === $condiciones[2]
            && (Decimal::comparar($pagado, '0', 2) <= 0 || Decimal::comparar($pagado, $total, 2) >= 0))) {
            throw ValidationException::withMessages(['pagado' => __('En un pago parcial lo pagado ahora debe ser mayor que Bs 0,00 y menor que el total (:total).', ['total' => Decimal::bs($total)])]);
        }

        if ($condicion === $condiciones[0]) {
            return [$condicion, $pagado, null];
        }

        $hoy = Carbon::now(config('mozistock.business_timezone'))->format('Y-m-d');
        $vencimiento = RegistrarCuentaCobrar::fecha(is_string($datos['vencimiento'] ?? null) ? $datos['vencimiento'] : null, 'vencimiento')
            ?? throw ValidationException::withMessages(['vencimiento' => __('La fecha de vencimiento es obligatoria cuando queda saldo pendiente.')]);

        if ($vencimiento < $hoy) {
            throw ValidationException::withMessages(['vencimiento' => __('El vencimiento no puede ser anterior a hoy.')]);
        }

        return [$condicion, $pagado, $vencimiento];
    }

    /**
     * Cantidades (reglas de stock) y costos unitarios de cada línea, con su snapshot.
     *
     * @param  array<int|string, array{cantidad?: mixed, costo?: mixed}>  $lineas
     * @return array<int, array{producto_id: int, nombre: string, sku: string, unidad: string, cantidad: string, costo: string, subtotal: string}>
     */
    private function prepararLineas(int $empresaId, array $lineas): array
    {
        $cantidades = $this->libro->prepararLineas($empresaId, array_map(fn (array $l) => $l['cantidad'] ?? '', $lineas));
        $productos = DB::table('productos')
            ->join('unidades_medida', 'unidades_medida.id', '=', 'productos.unidad_medida_id')
            ->where('productos.empresa_id', $empresaId)
            ->whereIn('productos.id', array_keys($cantidades))
            ->get(['productos.id', 'productos.sku', 'unidades_medida.abreviatura'])
            ->keyBy('id');
        $preparadas = [];
        $errores = [];

        foreach ($cantidades as $productoId => $linea) {
            $valor = $lineas[$productoId]['costo'] ?? '';
            $costo = Decimal::normalizar(is_scalar($valor) ? (string) $valor : '', Decimal::ESCALA_DINERO);

            if ($costo === null || Decimal::comparar($costo, '0', 2) < 0) {
                $errores[] = __(':linea: el costo unitario debe ser un importe válido (hasta 2 decimales).', ['linea' => $linea['nombre']]);

                continue;
            }

            $preparadas[$productoId] = [
                'producto_id' => $productoId,
                'nombre' => $linea['nombre'],
                'sku' => (string) $productos->get($productoId)?->sku,
                'unidad' => (string) $productos->get($productoId)?->abreviatura,
                'cantidad' => $linea['cantidad'],
                'costo' => $costo,
                'subtotal' => CalculadoraCompra::subtotal($linea['cantidad'], $costo),
            ];
        }

        if ($errores !== []) {
            throw ValidationException::withMessages(['lineas' => $errores]);
        }

        return $preparadas;
    }

    /**
     * Lee la base en cada llamada: tras un choque de clave la compra ya existe.
     *
     * @phpstan-impure
     */
    private function existente(int $empresaId, string $clave): ?Compra
    {
        return Compra::withoutGlobalScope(EmpresaScope::class)
            ->where('empresa_id', $empresaId)->where('clave_idempotencia', $clave)->first();
    }
}
