<?php

namespace App\Domain\CuentasPagar\Actions;

use App\Domain\Auditoria\Auditor;
use App\Domain\Caja\Comprobante;
use App\Domain\Creditos\Actions\RegistrarCuentaCobrar;
use App\Domain\Tenancy\Numerador;
use App\Domain\Tenancy\ResolverSucursal;
use App\Models\CuentaPagar;
use App\Models\Proveedor;
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
 * Obligación manual por pagar (§ 16, decisiones 4 y 9): deuda anterior a MoziStock u otra que
 * no proviene de Compras ni de Egresos. Admite lo pagado antes (sin mover dinero ahora), exige
 * vencimiento porque siempre queda saldo, no toca inventario ni crea egresos. Solo administradores.
 */
final class RegistrarObligacion
{
    public function __construct(
        private readonly Numerador $numerador,
        private readonly ResolverSucursal $resolverSucursal,
        private readonly Auditor $auditor,
    ) {}

    /**
     * @param  array{proveedor_id: int|null, sucursal_id: int|null, concepto: string, fecha: string|null, vencimiento: string|null, importe: string|null, pagado: string|null, nota: string|null, respaldo?: UploadedFile|null, clave: string}  $datos
     */
    public function ejecutar(User $actor, array $datos): CuentaPagar
    {
        Gate::forUser($actor)->authorize('create', CuentaPagar::class);
        $empresaId = (int) $actor->empresa_id;

        if (! Str::isUuid($datos['clave'])) {
            throw ValidationException::withMessages(['importe' => __('La obligación no tiene una clave válida. Recarga la página.')]);
        }

        if (($previa = $this->existente($empresaId, $datos['clave'])) !== null) {
            return $previa;
        }

        $proveedor = $datos['proveedor_id'] === null ? null : Proveedor::query()->where('activo', true)->find($datos['proveedor_id']);

        if ($proveedor === null) {
            throw ValidationException::withMessages(['proveedor_id' => __('Selecciona el acreedor (proveedor activo de tu empresa).')]);
        }

        $sucursal = $this->resolverSucursal->paraOperacion($actor, $datos['sucursal_id']);
        $concepto = trim($datos['concepto']);

        if ($concepto === '' || mb_strlen($concepto) > 150) {
            throw ValidationException::withMessages(['concepto' => __('Describe la obligación en hasta 150 caracteres.')]);
        }

        $hoy = Carbon::now(config('mozistock.business_timezone'))->format('Y-m-d');
        $fecha = RegistrarCuentaCobrar::fecha($datos['fecha'], 'fecha') ?? throw ValidationException::withMessages(['fecha' => __('Indica la fecha de registro de la obligación.')]);

        if ($fecha > $hoy) {
            throw ValidationException::withMessages(['fecha' => __('La fecha de registro no puede ser futura.')]);
        }

        $vencimiento = RegistrarCuentaCobrar::fecha($datos['vencimiento'], 'vencimiento')
            ?? throw ValidationException::withMessages(['vencimiento' => __('La fecha de vencimiento es obligatoria cuando queda saldo pendiente.')]);

        if ($vencimiento < $fecha) {
            throw ValidationException::withMessages(['vencimiento' => __('El vencimiento no puede ser anterior a la fecha de registro.')]);
        }

        $importe = Decimal::normalizar($datos['importe'] ?? '', Decimal::ESCALA_DINERO);

        if ($importe === null || Decimal::comparar($importe, '0', 2) <= 0) {
            throw ValidationException::withMessages(['importe' => __('Ingresa un importe original mayor que Bs 0,00 (hasta 2 decimales).')]);
        }

        $pagado = Decimal::normalizar(blank($datos['pagado']) ? '0' : (string) $datos['pagado'], Decimal::ESCALA_DINERO);

        if ($pagado === null || Decimal::comparar($pagado, '0', 2) < 0 || Decimal::comparar($pagado, $importe, 2) >= 0) {
            throw ValidationException::withMessages(['pagado' => __('Lo ya pagado debe ser menor que el importe original: debe quedar saldo pendiente.')]);
        }

        $nota = trim((string) $datos['nota']);

        if (mb_strlen($nota) > 250) {
            throw ValidationException::withMessages(['nota' => __('La observación admite hasta 250 caracteres.')]);
        }

        $archivo = Comprobante::guardar($datos['respaldo'] ?? null, $empresaId, 'comprobantes/obligaciones', 'respaldo');

        try {
            return DB::transaction(function () use ($actor, $datos, $proveedor, $sucursal, $concepto, $fecha, $vencimiento, $importe, $pagado, $nota, $archivo): CuentaPagar {
                $cuenta = new CuentaPagar;
                $cuenta->empresa_id = (int) $actor->empresa_id;
                $cuenta->numero = $this->numerador->siguiente($cuenta->empresa_id, 'CXP');
                $cuenta->origen = 'manual';
                $cuenta->sucursal_id = $sucursal->id;
                $cuenta->proveedor_id = $proveedor->id;
                $cuenta->usuario_id = $actor->id;
                $cuenta->concepto = $concepto;
                $cuenta->fecha = Carbon::parse($fecha);
                $cuenta->vencimiento = Carbon::parse($vencimiento);
                $cuenta->total_documento = $importe;
                $cuenta->pagado_origen = $pagado;
                $cuenta->saldo_pendiente = Decimal::restar($importe, $pagado, Decimal::ESCALA_DINERO);
                $cuenta->nota = $nota === '' ? null : $nota;
                $cuenta->respaldo_path = $archivo['path'] ?? null;
                $cuenta->respaldo_mime = $archivo['mime'] ?? null;
                $cuenta->clave_idempotencia = $datos['clave'];
                $cuenta->save();

                $this->auditor->registrar('cuenta_pagar.manual', $cuenta, $actor, $cuenta->empresa_id, despues: [
                    'cuenta' => $cuenta->codigo(), 'proveedor_id' => $proveedor->id, 'sucursal_id' => $sucursal->id,
                    'importe' => $importe, 'pagado' => $pagado, 'saldo' => $cuenta->saldo_pendiente, 'fecha' => $fecha, 'vencimiento' => $vencimiento,
                ]);

                return $cuenta;
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
    private function existente(int $empresaId, string $clave): ?CuentaPagar
    {
        return CuentaPagar::withoutGlobalScope(EmpresaScope::class)
            ->where('empresa_id', $empresaId)->where('clave_idempotencia', $clave)->first();
    }
}
