<?php

namespace App\Domain\Egresos\Actions;

use App\Domain\Auditoria\Auditor;
use App\Domain\Caja\LibroCaja;
use App\Domain\Tenancy\Numerador;
use App\Domain\Tenancy\ResolverSucursal;
use App\Models\CategoriaEgreso;
use App\Models\Egreso;
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
 * Gasto operativo pagado (especificación § 3.8): un egreso y su única salida de caja, en
 * efectivo o electrónico, en la misma transacción. Las compras de stock no se registran aquí.
 * Decisiones del propietario (23/10/2026): solo administradores y siempre con la fecha y
 * hora actuales. Repetir la clave no repite el egreso.
 */
final class RegistrarEgreso
{
    public function __construct(
        private readonly Numerador $numerador,
        private readonly LibroCaja $caja,
        private readonly ResolverSucursal $resolverSucursal,
        private readonly Auditor $auditor,
    ) {}

    /**
     * @param  array{sucursal_id: int|null, categoria_id: int|null, descripcion: string, metodo: string|null, monto: string|null, clave: string}  $datos
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

        if (! in_array($datos['metodo'], ['efectivo', 'electronico'], true)) {
            throw ValidationException::withMessages(['metodo' => __('Elige Efectivo o Electrónico.')]);
        }

        $monto = Decimal::normalizar($datos['monto'] ?? '', Decimal::ESCALA_DINERO);

        if ($monto === null || Decimal::comparar($monto, '0', 2) <= 0) {
            throw ValidationException::withMessages(['monto' => __('Ingresa un monto mayor que Bs 0,00 (hasta 2 decimales).')]);
        }

        try {
            return DB::transaction(function () use ($actor, $datos, $sucursal, $categoria, $descripcion, $monto): Egreso {
                $egreso = new Egreso;
                $egreso->empresa_id = (int) $actor->empresa_id;
                $egreso->numero = $this->numerador->siguiente($egreso->empresa_id, 'EGR');
                $egreso->sucursal_id = $sucursal->id;
                $egreso->categoria_egreso_id = $categoria->id;
                $egreso->usuario_id = $actor->id;
                $egreso->descripcion = $descripcion;
                $egreso->metodo = (string) $datos['metodo'];
                $egreso->monto = $monto;
                $egreso->fecha = Carbon::now();
                $egreso->clave_idempotencia = $datos['clave'];
                $egreso->save();

                $this->caja->registrar($actor, $sucursal->id, 'egreso', [
                    'efectivo' => $egreso->metodo === 'efectivo' ? $monto : '0.00',
                    'electronico' => $egreso->metodo === 'electronico' ? $monto : '0.00',
                ], 'egreso', $egreso->id, $egreso->fecha);

                $this->auditor->registrar('egreso.registrado', $egreso, $actor, $egreso->empresa_id, despues: [
                    'egreso' => $egreso->codigo(), 'categoria_id' => $categoria->id, 'sucursal_id' => $sucursal->id,
                    'monto' => $monto, 'metodo' => $egreso->metodo,
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
