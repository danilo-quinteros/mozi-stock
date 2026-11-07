<?php

namespace App\Domain\Egresos\Actions;

use App\Domain\Auditoria\Auditor;
use App\Domain\Caja\CajaOperacion;
use App\Domain\Caja\LibroCaja;
use App\Domain\Compras\Actions\RegistrarCompra;
use App\Domain\CuentasPagar\AbrirCuentaPagar;
use App\Domain\Tenancy\Numerador;
use App\Domain\Tenancy\ResolverSucursal;
use App\Domain\Ventas\ValidadorPago;
use App\Models\CategoriaEgreso;
use App\Models\Egreso;
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
 * Gasto operativo (especificación § 3.8 y Cuentas por pagar § 14): el egreso se reconoce por su
 * monto total; lo pagado ahora sale de caja una sola vez por medio (efectivo, electrónico o
 * mixto) y, si queda saldo (parcial o pendiente), se abre su cuenta por pagar, en la misma
 * transacción. Las compras de stock no se registran aquí.
 *
 * Beneficiario y vencimiento obligatorios cuando queda saldo (§ 14.1). Decisiones del
 * propietario: solo administradores y siempre con la fecha y hora actuales (23/10/2026); el
 * efectivo sale de un turno abierto o, si el administrador lo indica, fuera de caja (30/10/2026).
 * Repetir la clave no repite el egreso.
 */
final class RegistrarEgreso
{
    public function __construct(
        private readonly Numerador $numerador,
        private readonly LibroCaja $caja,
        private readonly CajaOperacion $cajas,
        private readonly AbrirCuentaPagar $cuentas,
        private readonly ResolverSucursal $resolverSucursal,
        private readonly Auditor $auditor,
    ) {}

    /**
     * @param  array{sucursal_id: int|null, categoria_id: int|null, descripcion: string, metodo: string|null, monto: string|null, proveedor_id?: int|null, condicion?: string|null, pagado?: string|null, efectivo?: string|null, electronico?: string|null, vencimiento?: string|null, externo?: bool, turno_id?: int|null, clave: string}  $datos
     */
    public function ejecutar(User $actor, array $datos): Egreso
    {
        Gate::forUser($actor)->authorize('create', Egreso::class);
        $empresaId = (int) $actor->empresa_id;

        if (! Str::isUuid($datos['clave'])) {
            throw ValidationException::withMessages(['monto' => __('El egreso no tiene una clave válida. Recarga la página.')]);
        }

        if (($previo = $this->existente($empresaId, $datos['clave'])) !== null) {
            return $previo;
        }

        $sucursal = $this->resolverSucursal->paraOperacion($actor, $datos['sucursal_id']);
        $categoria = $datos['categoria_id'] === null ? null : CategoriaEgreso::query()->where('activa', true)->find($datos['categoria_id']);

        if ($categoria === null) {
            throw ValidationException::withMessages(['categoria_id' => __('Selecciona una categoría de egreso activa.')]);
        }

        $descripcion = trim($datos['descripcion']);

        if ($descripcion === '' || mb_strlen($descripcion) > 150) {
            throw ValidationException::withMessages(['descripcion' => __('Describe el gasto en hasta 150 caracteres.')]);
        }

        $monto = Decimal::normalizar($datos['monto'] ?? '', Decimal::ESCALA_DINERO);

        if ($monto === null || Decimal::comparar($monto, '0', 2) <= 0) {
            throw ValidationException::withMessages(['monto' => __('Ingresa un monto mayor que Bs 0,00 (hasta 2 decimales).')]);
        }

        [$condicion, $pagado, $vencimiento] = RegistrarCompra::condicion($datos, $monto, ['pagado', 'pendiente', 'parcial']);
        // El scope de empresa descarta proveedores de otra empresa.
        $proveedor = empty($datos['proveedor_id']) ? null : Proveedor::query()->where('activo', true)->find($datos['proveedor_id']);

        if ($proveedor === null && (! empty($datos['proveedor_id']) || $condicion !== 'pagado')) {
            throw ValidationException::withMessages(['proveedor_id' => __('Selecciona el beneficiario (proveedor activo de tu empresa): es obligatorio si queda saldo.')]);
        }

        $pago = Decimal::comparar($pagado, '0', 2) > 0
            ? ValidadorPago::exacto($pagado, $datos['metodo'], $datos['efectivo'] ?? null, $datos['electronico'] ?? null,
                $condicion === 'pagado' ? 'monto del egreso' : 'pago inicial del egreso')
            : null;

        try {
            return DB::transaction(function () use ($actor, $datos, $sucursal, $categoria, $proveedor, $descripcion, $monto, $condicion, $pagado, $vencimiento, $pago): Egreso {
                $caja = $pago === null ? ['caja_id' => null, 'turno_id' => null, 'externo' => false]
                    : $this->cajas->resolver($actor, $sucursal->id, $pago['efectivo'], (bool) ($datos['externo'] ?? false), 'externo', isset($datos['turno_id']) ? (int) $datos['turno_id'] : null);

                $egreso = new Egreso;
                $egreso->empresa_id = (int) $actor->empresa_id;
                $egreso->numero = $this->numerador->siguiente($egreso->empresa_id, 'EGR');
                $egreso->sucursal_id = $sucursal->id;
                $egreso->caja_id = $caja['caja_id'];
                $egreso->turno_caja_id = $caja['turno_id'];
                $egreso->categoria_egreso_id = $categoria->id;
                $egreso->proveedor_id = $proveedor?->id;
                $egreso->usuario_id = $actor->id;
                $egreso->descripcion = $descripcion;
                $egreso->metodo = $pago['metodo']->value ?? null;
                $egreso->monto = $monto;
                $egreso->condicion = $condicion;
                $egreso->pagado = $pagado;
                $egreso->monto_efectivo = $pago['efectivo'] ?? '0.00';
                $egreso->monto_electronico = $pago['electronico'] ?? '0.00';
                $egreso->vencimiento = $vencimiento === null ? null : Carbon::parse($vencimiento);
                $egreso->pago_externo = $caja['externo'];
                $egreso->fecha = Carbon::now();
                $egreso->clave_idempotencia = $datos['clave'];
                $egreso->save();

                if ($pago !== null) {
                    $this->caja->registrar($actor, $sucursal->id, 'egreso',
                        ['efectivo' => $pago['efectivo'], 'electronico' => $pago['electronico']], 'egreso', $egreso->id, $egreso->fecha,
                        $caja['caja_id'], $caja['turno_id']);
                }

                $cuenta = $this->cuentas->desdeEgreso($actor, $egreso);

                $this->auditor->registrar('egreso.registrado', $egreso, $actor, $egreso->empresa_id, despues: [
                    'egreso' => $egreso->codigo(), 'categoria_id' => $categoria->id, 'sucursal_id' => $sucursal->id,
                    'proveedor_id' => $proveedor?->id, 'monto' => $monto, 'condicion' => $condicion, 'pagado' => $pagado,
                    'metodo' => $egreso->metodo, 'externo' => $caja['externo'], 'turno_caja_id' => $caja['turno_id'],
                    'cuenta_pagar' => $cuenta?->codigo(),
                ]);

                return $egreso;
            }, attempts: 3);
        } catch (UniqueConstraintViolationException $e) {
            return $this->existente($empresaId, $datos['clave']) ?? throw $e;
        }
    }

    /**
     * Lee la base en cada llamada: tras un choque de clave el egreso ya existe.
     *
     * @phpstan-impure
     */
    private function existente(int $empresaId, string $clave): ?Egreso
    {
        return Egreso::withoutGlobalScope(EmpresaScope::class)
            ->where('empresa_id', $empresaId)->where('clave_idempotencia', $clave)->first();
    }
}
