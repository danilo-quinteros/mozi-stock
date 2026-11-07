<?php

namespace App\Domain\Creditos\Actions;

use App\Domain\Auditoria\Auditor;
use App\Domain\Caja\CajaOperacion;
use App\Domain\Caja\Comprobante;
use App\Domain\Caja\LibroCaja;
use App\Domain\Creditos\Consultas\PendientesCobro;
use App\Domain\Creditos\DistribuidorCobro;
use App\Domain\Tenancy\Numerador;
use App\Domain\Tenancy\ResolverSucursal;
use App\Domain\Ventas\ValidadorPago;
use App\Models\Cliente;
use App\Models\Cobro;
use App\Models\CobroAplicacion;
use App\Models\CuentaCobrar;
use App\Models\Scopes\EmpresaScope;
use App\Models\User;
use App\Models\Venta;
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
 * Cobro posterior de un cliente (Cuentas por cobrar § 8 y § 24.1). En una transacción: bloquea
 * los saldos alcanzados (una venta, una cuenta manual o todos los del cliente), valida el importe
 * contra el saldo total, lo distribuye en modo manual o automático (vencimientos más antiguos
 * primero), guarda UN cobro con su desglose por medio y su detalle de aplicación, reduce cada
 * saldo y registra la entrada de dinero una sola vez por medio, con la fecha y hora reales.
 *
 * El efectivo que entra por caja exige un turno abierto; el administrador puede registrarlo
 * como externo (CajaOperacion). Nunca crea ventas ni egresos. Repetir la clave no vuelve a cobrar.
 */
final class RegistrarCobro
{
    public function __construct(
        private readonly ResolverSucursal $resolverSucursal,
        private readonly Numerador $numerador,
        private readonly CajaOperacion $cajas,
        private readonly LibroCaja $caja,
        private readonly Auditor $auditor,
    ) {}

    /**
     * @param  array{venta_id?: int|null, cuenta_id?: int|null, cliente_id?: int|null, modo?: string|null, asignaciones?: array<array-key, mixed>, sucursal_id: int|null, metodo: string|null, monto: string|null, efectivo: string|null, electronico: string|null, referencia?: string|null, nota?: string|null, externo?: bool, turno_id?: int|null, comprobante?: UploadedFile|null, clave: string}  $datos
     */
    public function ejecutar(User $actor, array $datos): Cobro
    {
        $empresaId = (int) $actor->empresa_id;

        if (! Str::isUuid($datos['clave'])) {
            throw ValidationException::withMessages(['monto' => __('El cobro no tiene una clave válida. Recarga la página.')]);
        }

        [$alcance, $id, $clienteId] = $this->alcance($actor, $datos);

        if (($previo = $this->existente($empresaId, $datos['clave'])) !== null) {
            return $previo;
        }

        $modo = (string) ($datos['modo'] ?? 'automatico');

        if (! in_array($modo, ['automatico', 'manual'], true)) {
            throw ValidationException::withMessages(['modo' => __('Elige aplicación automática o manual.')]);
        }

        $sucursal = $this->resolverSucursal->paraOperacion($actor, $datos['sucursal_id']);
        $referencia = $this->texto($datos['referencia'] ?? null, 100, 'referencia');
        $nota = $this->texto($datos['nota'] ?? null, 150, 'nota');
        $archivo = Comprobante::guardar($datos['comprobante'] ?? null, $empresaId, 'comprobantes/cobros');

        try {
            return DB::transaction(function () use ($actor, $datos, $alcance, $id, $clienteId, $modo, $sucursal, $referencia, $nota, $archivo): Cobro {
                $pendientes = PendientesCobro::cargar($alcance, $id, bloquear: true);
                $items = $pendientes['items'];

                if ($items === []) {
                    throw ValidationException::withMessages(['monto' => $alcance === 'cliente'
                        ? __('El cliente no tiene saldo pendiente.')
                        : __('Este crédito ya está pagado.')]);
                }

                $saldoTotal = array_reduce($items, fn (string $suma, array $i) => Decimal::sumar($suma, $i['saldo'], 2), '0.00');
                $pago = ValidadorPago::abono($saldoTotal, $datos['metodo'], $datos['monto'], $datos['efectivo'], $datos['electronico']);
                $aplicado = $modo === 'automatico'
                    ? DistribuidorCobro::automatico($items, $pago['monto'], $pago['metodo']->value === 'mixto' ? 'efectivo' : 'monto')
                    : DistribuidorCobro::manual(array_column($items, 'saldo', 'clave'), $datos['asignaciones'] ?? [], $pago['monto']);
                $medios = DistribuidorCobro::medios($aplicado, $pago['efectivo']);
                $caja = $this->cajas->resolver($actor, $sucursal->id, $pago['efectivo'], (bool) ($datos['externo'] ?? false), 'externo', isset($datos['turno_id']) ? (int) $datos['turno_id'] : null);
                $ahora = Carbon::now();

                $cobro = new Cobro;
                $cobro->empresa_id = (int) $actor->empresa_id;
                $cobro->numero = $this->numerador->siguiente($cobro->empresa_id, 'COB');
                $cobro->cliente_id = $clienteId;
                $cobro->sucursal_id = $sucursal->id;
                $cobro->recibido_por = $actor->id;
                $cobro->metodo = $pago['metodo'];
                $cobro->monto = $pago['monto'];
                $cobro->monto_efectivo = $pago['efectivo'];
                $cobro->monto_electronico = $pago['electronico'];
                $cobro->fecha_cobro = $ahora;
                $cobro->caja_id = $caja['caja_id'];
                $cobro->turno_caja_id = $caja['turno_id'];
                $cobro->externo = $caja['externo'];
                $cobro->modo = $modo;
                $cobro->referencia = $referencia;
                $cobro->nota = $nota;
                $cobro->comprobante_path = $archivo['path'] ?? null;
                $cobro->comprobante_mime = $archivo['mime'] ?? null;
                $cobro->clave_idempotencia = $datos['clave'];
                $cobro->save();

                $porClave = array_column($items, null, 'clave');
                $detalle = [];

                foreach ($aplicado as $clave => $importe) {
                    $item = $porClave[$clave];
                    $aplicacion = new CobroAplicacion;
                    $aplicacion->empresa_id = $cobro->empresa_id;
                    $aplicacion->cobro_id = $cobro->id;
                    $aplicacion->venta_id = $item['venta_id'];
                    $aplicacion->venta_cuota_id = $item['tipo'] === 'cuota' ? $item['id'] : null;
                    $aplicacion->cuenta_cobrar_id = $item['tipo'] === 'cuenta' ? $item['id'] : null;
                    $aplicacion->monto = $importe;
                    $aplicacion->monto_efectivo = $medios[$clave]['efectivo'];
                    $aplicacion->monto_electronico = $medios[$clave]['electronico'];
                    $aplicacion->save();

                    if ($item['tipo'] === 'cuota') {
                        $cuota = $pendientes['cuotas'][$item['id']];
                        $cuota->saldo = Decimal::restar($cuota->saldo, $importe, Decimal::ESCALA_DINERO);
                        $cuota->save();
                    }

                    if ($item['tipo'] === 'cuenta') {
                        $cuenta = $pendientes['cuentas'][$item['id']];
                        $cuenta->saldo_pendiente = Decimal::restar($cuenta->saldo_pendiente, $importe, Decimal::ESCALA_DINERO);
                        $cuenta->save();
                    } else {
                        $venta = $pendientes['ventas'][$item['venta_id']];
                        $venta->saldo_pendiente = Decimal::restar($venta->saldo_pendiente, $importe, Decimal::ESCALA_DINERO);
                    }

                    $detalle[] = ['documento' => $item['documento'], 'detalle' => $item['detalle'], 'importe' => $importe, 'saldo_antes' => $item['saldo']];
                }

                foreach ($pendientes['ventas'] as $venta) {
                    if ($venta->isDirty('saldo_pendiente')) {
                        $venta->save();
                    }
                }

                $this->caja->registrar($actor, $sucursal->id, 'ingreso',
                    ['efectivo' => $pago['efectivo'], 'electronico' => $pago['electronico']], 'cobro', $cobro->id, $ahora,
                    $caja['caja_id'], $caja['turno_id']);

                $this->auditor->registrar('cobro.registrado', $cobro, $actor, $cobro->empresa_id, despues: [
                    'cobro' => $cobro->codigo(), 'cliente_id' => $clienteId, 'sucursal_id' => $sucursal->id, 'monto' => $pago['monto'],
                    'metodo' => $pago['metodo']->value, 'efectivo' => $pago['efectivo'], 'electronico' => $pago['electronico'],
                    'modo' => $modo, 'externo' => $caja['externo'], 'turno_caja_id' => $caja['turno_id'], 'aplicaciones' => $detalle,
                ]);

                return $cobro;
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
     * Qué saldos alcanza el cobro, autorizado contra la empresa del usuario. El scope de empresa
     * responde 404 para IDs de otra empresa.
     *
     * @param  array<string, mixed>  $datos
     * @return array{0: 'venta'|'cuenta'|'cliente', 1: int, 2: int|null}
     */
    private function alcance(User $actor, array $datos): array
    {
        if (! empty($datos['venta_id'])) {
            $venta = Venta::query()->findOrFail((int) $datos['venta_id']);
            Gate::forUser($actor)->authorize('cobrar', $venta);

            return ['venta', $venta->id, $venta->cliente_id];
        }

        if (! empty($datos['cuenta_id'])) {
            $cuenta = CuentaCobrar::query()->findOrFail((int) $datos['cuenta_id']);
            Gate::forUser($actor)->authorize('cobrar', $cuenta);

            if ($cuenta->estaAnulado()) {
                throw ValidationException::withMessages(['monto' => __('La cuenta :codigo está anulada.', ['codigo' => $cuenta->codigo()])]);
            }

            return ['cuenta', $cuenta->id, $cuenta->cliente_id];
        }

        if (! empty($datos['cliente_id'])) {
            $cliente = Cliente::query()->findOrFail((int) $datos['cliente_id']);
            Gate::forUser($actor)->authorize('view', $cliente);

            return ['cliente', $cliente->id, $cliente->id];
        }

        throw ValidationException::withMessages(['cliente_id' => __('Elige el cliente o la cuenta que se cobra.')]);
    }

    private function texto(?string $valor, int $maximo, string $campo): ?string
    {
        $valor = trim((string) $valor);

        if (mb_strlen($valor) > $maximo) {
            throw ValidationException::withMessages([$campo => __('Este dato admite hasta :max caracteres.', ['max' => $maximo])]);
        }

        return $valor === '' ? null : $valor;
    }

    /**
     * Lee la base en cada llamada: tras un choque de clave el cobro ya existe.
     *
     * @phpstan-impure
     */
    private function existente(int $empresaId, string $clave): ?Cobro
    {
        return Cobro::withoutGlobalScope(EmpresaScope::class)
            ->where('empresa_id', $empresaId)->where('clave_idempotencia', $clave)->first();
    }
}
