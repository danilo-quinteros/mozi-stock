<?php

namespace App\Domain\CuentasPagar\Actions;

use App\Domain\Auditoria\Auditor;
use App\Domain\Caja\CajaOperacion;
use App\Domain\Caja\Comprobante;
use App\Domain\Caja\LibroCaja;
use App\Domain\Tenancy\ResolverSucursal;
use App\Domain\Ventas\ValidadorPago;
use App\Models\CuentaPagar;
use App\Models\PagoCuentaPagar;
use App\Models\Scopes\EmpresaScope;
use App\Models\User;
use App\Support\Decimal;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Pago posterior de una cuenta por pagar (§ 18 y § 24.2). En una transacción: bloquea la
 * cuenta, valida que el pago sea mayor que 0 y no supere el saldo, guarda un único pago de
 * esa sola cuenta con su desglose y registra la salida de dinero una vez por medio, con la
 * fecha y hora reales. No crea egresos ni compras. El efectivo que sale de caja exige turno
 * abierto, salvo que el administrador lo registre como externo. Repetir la clave no paga dos veces.
 */
final class RegistrarPagoCuenta
{
    public function __construct(
        private readonly ResolverSucursal $resolverSucursal,
        private readonly CajaOperacion $cajas,
        private readonly LibroCaja $caja,
        private readonly Auditor $auditor,
    ) {}

    /**
     * @param  array{sucursal_id: int|null, metodo: string|null, monto: string|null, efectivo: string|null, electronico: string|null, referencia?: string|null, nota?: string|null, externo?: bool, turno_id?: int|null, comprobante?: UploadedFile|null, clave: string}  $datos
     */
    public function ejecutar(User $actor, CuentaPagar $cuenta, array $datos): PagoCuentaPagar
    {
        Gate::forUser($actor)->authorize('pagar', $cuenta);
        $empresaId = (int) $actor->empresa_id;

        if (! Str::isUuid($datos['clave'])) {
            throw ValidationException::withMessages(['monto' => __('El pago no tiene una clave válida. Recarga la página.')]);
        }

        if (($previo = $this->existente($empresaId, $datos['clave'])) !== null) {
            return $previo;
        }

        $sucursal = $this->resolverSucursal->paraOperacion($actor, $datos['sucursal_id']);
        $referencia = trim((string) ($datos['referencia'] ?? ''));
        $nota = trim((string) ($datos['nota'] ?? ''));

        if (mb_strlen($referencia) > 100 || mb_strlen($nota) > 150) {
            throw ValidationException::withMessages(['nota' => __('La referencia admite hasta 100 caracteres y la observación hasta 150.')]);
        }

        $archivo = Comprobante::guardar($datos['comprobante'] ?? null, $empresaId, 'comprobantes/pagos');

        try {
            return DB::transaction(function () use ($actor, $cuenta, $datos, $sucursal, $referencia, $nota, $archivo): PagoCuentaPagar {
                $bloqueada = CuentaPagar::query()->whereKey($cuenta->id)->lockForUpdate()->firstOrFail();

                if ($bloqueada->estaAnulado()) {
                    throw ValidationException::withMessages(['monto' => __('Esta obligación está anulada.')]);
                }

                if (Decimal::comparar($bloqueada->saldo_pendiente, '0', 2) <= 0) {
                    throw ValidationException::withMessages(['monto' => __('Esta cuenta ya está pagada.')]);
                }

                $pago = ValidadorPago::abono($bloqueada->saldo_pendiente, $datos['metodo'], $datos['monto'], $datos['efectivo'], $datos['electronico'], esPago: true);
                $caja = $this->cajas->resolver($actor, $sucursal->id, $pago['efectivo'], (bool) ($datos['externo'] ?? false), 'externo', isset($datos['turno_id']) ? (int) $datos['turno_id'] : null);
                $antes = $bloqueada->saldo_pendiente;
                $ahora = Carbon::now();

                $registro = new PagoCuentaPagar;
                $registro->empresa_id = $bloqueada->empresa_id;
                $registro->cuenta_pagar_id = $bloqueada->id;
                $registro->sucursal_id = $sucursal->id;
                $registro->pagado_por = $actor->id;
                $registro->metodo = $pago['metodo'];
                $registro->monto = $pago['monto'];
                $registro->monto_efectivo = $pago['efectivo'];
                $registro->monto_electronico = $pago['electronico'];
                $registro->fecha_pago = $ahora;
                $registro->caja_id = $caja['caja_id'];
                $registro->turno_caja_id = $caja['turno_id'];
                $registro->externo = $caja['externo'];
                $registro->referencia = $referencia === '' ? null : $referencia;
                $registro->nota = $nota === '' ? null : $nota;
                $registro->comprobante_path = $archivo['path'] ?? null;
                $registro->comprobante_mime = $archivo['mime'] ?? null;
                $registro->clave_idempotencia = $datos['clave'];
                $registro->save();

                $bloqueada->saldo_pendiente = Decimal::restar($antes, $pago['monto'], Decimal::ESCALA_DINERO);
                $bloqueada->save();

                $this->caja->registrar($actor, $sucursal->id, 'egreso',
                    ['efectivo' => $pago['efectivo'], 'electronico' => $pago['electronico']], 'pago_cxp', $registro->id, $ahora,
                    $caja['caja_id'], $caja['turno_id']);

                $this->auditor->registrar('cuenta_pagar.pago', $bloqueada, $actor, $bloqueada->empresa_id,
                    ['saldo_pendiente' => $antes],
                    ['saldo_pendiente' => $bloqueada->saldo_pendiente, 'pago' => $pago['monto'], 'metodo' => $pago['metodo']->value,
                        'efectivo' => $pago['efectivo'], 'electronico' => $pago['electronico'], 'sucursal_id' => $sucursal->id,
                        'externo' => $caja['externo'], 'turno_caja_id' => $caja['turno_id']]);

                return $registro;
            }, attempts: 3);
        } catch (UniqueConstraintViolationException $e) {
            Comprobante::descartar($archivo);

            return $this->existente($empresaId, $datos['clave']) ?? throw $e;
        } catch (Throwable $e) {
            Comprobante::descartar($archivo);

            throw $e;
        }
    }

    /**
     * @phpstan-impure
     */
    private function existente(int $empresaId, string $clave): ?PagoCuentaPagar
    {
        return PagoCuentaPagar::withoutGlobalScope(EmpresaScope::class)
            ->where('empresa_id', $empresaId)->where('clave_idempotencia', $clave)->first();
    }
}
