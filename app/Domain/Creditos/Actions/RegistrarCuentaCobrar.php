<?php

namespace App\Domain\Creditos\Actions;

use App\Domain\Auditoria\Auditor;
use App\Domain\Tenancy\Numerador;
use App\Domain\Tenancy\ResolverSucursal;
use App\Models\Cliente;
use App\Models\CuentaCobrar;
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
 * Cuenta por cobrar manual (decisión 1): saldo inicial de un cliente u otra deuda que no
 * proviene de una venta de MoziStock. No crea una venta ficticia, no mueve stock ni dinero; la
 * fecha de origen puede ser anterior a hoy y el vencimiento es opcional. Solo administradores.
 * Repetir la clave no repite la cuenta.
 */
final class RegistrarCuentaCobrar
{
    public function __construct(
        private readonly Numerador $numerador,
        private readonly ResolverSucursal $resolverSucursal,
        private readonly Auditor $auditor,
    ) {}

    /**
     * @param  array{cliente_id: int|null, sucursal_id: int|null, concepto: string, fecha: string|null, vencimiento: string|null, importe: string|null, nota: string|null, clave: string}  $datos
     */
    public function ejecutar(User $actor, array $datos): CuentaCobrar
    {
        Gate::forUser($actor)->authorize('create', CuentaCobrar::class);
        $empresaId = (int) $actor->empresa_id;

        if (! Str::isUuid($datos['clave'])) {
            throw ValidationException::withMessages(['importe' => __('La cuenta no tiene una clave válida. Recarga la página.')]);
        }

        if (($previa = $this->existente($empresaId, $datos['clave'])) !== null) {
            return $previa;
        }

        $cliente = $datos['cliente_id'] === null ? null : Cliente::query()->where('activo', true)->find($datos['cliente_id']);

        if ($cliente === null) {
            throw ValidationException::withMessages(['cliente_id' => __('Selecciona un cliente activo de tu empresa.')]);
        }

        $sucursal = $this->resolverSucursal->paraOperacion($actor, $datos['sucursal_id']);
        $concepto = trim($datos['concepto']);

        if ($concepto === '' || mb_strlen($concepto) > 150) {
            throw ValidationException::withMessages(['concepto' => __('Describe la deuda en hasta 150 caracteres.')]);
        }

        $hoy = Carbon::now(config('mozistock.business_timezone'))->format('Y-m-d');
        $fecha = self::fecha($datos['fecha'], 'fecha') ?? throw ValidationException::withMessages(['fecha' => __('Indica la fecha de origen de la deuda.')]);

        if ($fecha > $hoy) {
            throw ValidationException::withMessages(['fecha' => __('La fecha de origen no puede ser futura.')]);
        }

        $vencimiento = self::fecha($datos['vencimiento'], 'vencimiento');

        if ($vencimiento !== null && $vencimiento < $fecha) {
            throw ValidationException::withMessages(['vencimiento' => __('El vencimiento no puede ser anterior a la fecha de origen.')]);
        }

        $importe = Decimal::normalizar($datos['importe'] ?? '', Decimal::ESCALA_DINERO);

        if ($importe === null || Decimal::comparar($importe, '0', 2) <= 0) {
            throw ValidationException::withMessages(['importe' => __('Ingresa un importe mayor que Bs 0,00 (hasta 2 decimales).')]);
        }

        $nota = trim((string) $datos['nota']);

        if (mb_strlen($nota) > 250) {
            throw ValidationException::withMessages(['nota' => __('La observación admite hasta 250 caracteres.')]);
        }

        try {
            return DB::transaction(function () use ($actor, $datos, $cliente, $sucursal, $concepto, $fecha, $vencimiento, $importe, $nota): CuentaCobrar {
                $cuenta = new CuentaCobrar;
                $cuenta->empresa_id = (int) $actor->empresa_id;
                $cuenta->numero = $this->numerador->siguiente($cuenta->empresa_id, 'CXC');
                $cuenta->sucursal_id = $sucursal->id;
                $cuenta->cliente_id = $cliente->id;
                $cuenta->usuario_id = $actor->id;
                $cuenta->concepto = $concepto;
                $cuenta->fecha = Carbon::parse($fecha);
                $cuenta->vencimiento = $vencimiento === null ? null : Carbon::parse($vencimiento);
                $cuenta->importe = $importe;
                $cuenta->saldo_pendiente = $importe;
                $cuenta->nota = $nota === '' ? null : $nota;
                $cuenta->clave_idempotencia = $datos['clave'];
                $cuenta->save();

                $this->auditor->registrar('cuenta_cobrar.registrada', $cuenta, $actor, $cuenta->empresa_id, despues: [
                    'cuenta' => $cuenta->codigo(), 'cliente_id' => $cliente->id, 'sucursal_id' => $sucursal->id,
                    'importe' => $importe, 'fecha' => $fecha, 'vencimiento' => $vencimiento,
                ]);

                return $cuenta;
            }, attempts: 3);
        } catch (UniqueConstraintViolationException $e) {
            return $this->existente((int) $actor->empresa_id, $datos['clave']) ?? throw $e;
        }
    }

    /**
     * Fecha Y-m-d válida o null si está vacía.
     *
     * @throws ValidationException
     */
    public static function fecha(?string $valor, string $campo): ?string
    {
        $texto = trim((string) $valor);

        if ($texto === '') {
            return null;
        }

        $fecha = preg_match('/^\d{4}-\d{2}-\d{2}$/', $texto) === 1 ? Carbon::createFromFormat('!Y-m-d', $texto) : null;

        if ($fecha === null || $fecha->format('Y-m-d') !== $texto) {
            throw ValidationException::withMessages([$campo => __('Indica una fecha válida.')]);
        }

        return $texto;
    }

    /**
     * @phpstan-impure
     */
    private function existente(int $empresaId, string $clave): ?CuentaCobrar
    {
        return CuentaCobrar::withoutGlobalScope(EmpresaScope::class)
            ->where('empresa_id', $empresaId)->where('clave_idempotencia', $clave)->first();
    }
}
