<?php

namespace App\Domain\Suscripciones\Actions;

use App\Domain\Auditoria\Auditor;
use App\Domain\Suscripciones\CalendarioSuscripcion;
use App\Models\Empresa;
use App\Models\Plan;
use App\Models\Scopes\EmpresaScope;
use App\Models\Suscripcion;
use App\Models\User;
use App\Support\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Precio mensual y vencimiento de una empresa, solo por el superadministrador. El precio
 * nuevo no cambia comprobantes ya enviados. Corregir el vencimiento a mano exige un motivo
 * y redefine el día de vencimiento del ciclo. Nunca toca el acceso de la empresa.
 */
final class ConfigurarSuscripcion
{
    public function __construct(private readonly Auditor $auditor) {}

    public function ejecutar(User $actor, Empresa $empresa, ?string $precio, ?string $vencimiento, ?string $motivo = null, ?int $planId = null): Suscripcion
    {
        Gate::forUser($actor)->authorize('configurar', Suscripcion::class);

        $precio = Decimal::normalizar($precio ?? '', Decimal::ESCALA_DINERO);

        if ($precio === null || Decimal::comparar($precio, '0', 2) <= 0) {
            throw ValidationException::withMessages(['precio_mensual' => __('Ingresa un precio mensual mayor que Bs 0,00 (hasta 2 decimales).')]);
        }

        $fecha = $this->fecha($vencimiento);
        $motivo = trim((string) $motivo);

        if (mb_strlen($motivo) > 300) {
            throw ValidationException::withMessages(['motivo_vencimiento' => __('El motivo admite hasta 300 caracteres.')]);
        }

        return DB::transaction(function () use ($actor, $empresa, $precio, $fecha, $motivo, $planId): Suscripcion {
            $suscripcion = Suscripcion::withoutGlobalScope(EmpresaScope::class)
                ->where('empresa_id', $empresa->id)->lockForUpdate()->first();

            $plan = $planId !== null
                ? Plan::query()->find($planId)
                : (Plan::query()->where('siat_habilitado', $empresa->siat_habilitado)->first() ?? Plan::query()->first());

            if ($suscripcion === null) {
                $suscripcion = new Suscripcion;
                $suscripcion->empresa_id = $empresa->id;
                $suscripcion->plan_id = $plan?->id;
                $suscripcion->precio_mensual = $precio;
                $suscripcion->vencimiento = $fecha;
                $suscripcion->dia_vencimiento = $fecha->day;
                $suscripcion->save();
                $this->auditor->registrar('suscripcion.configurada', $suscripcion, $actor, $empresa->id, despues: $this->resumen($suscripcion));

                return $suscripcion;
            }

            $antes = $this->resumen($suscripcion);
            $suscripcion->precio_mensual = $precio;

            if ($planId !== null || $suscripcion->plan_id === null) {
                $suscripcion->plan_id = $plan?->id;
            }

            if (! $suscripcion->vencimiento->equalTo($fecha)) {
                if ($motivo === '') {
                    throw ValidationException::withMessages(['motivo_vencimiento' => __('Explica por qué cambias el vencimiento.')]);
                }

                $suscripcion->vencimiento = $fecha;
                $suscripcion->dia_vencimiento = $fecha->day;
            }

            if ($suscripcion->isDirty()) {
                $suscripcion->save();
                $this->auditor->registrar('suscripcion.actualizada', $suscripcion, $actor, $empresa->id, $antes,
                    $this->resumen($suscripcion) + ($suscripcion->wasChanged('vencimiento') ? ['motivo' => $motivo] : []));
            }

            return $suscripcion;
        });
    }

    private function fecha(?string $valor): CarbonImmutable
    {
        try {
            $fecha = CalendarioSuscripcion::fecha((string) $valor);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['vencimiento' => __('Indica una fecha de vencimiento válida.')]);
        }

        if ($fecha->year < 2020 || $fecha->greaterThan(CalendarioSuscripcion::hoy()->addYears(2))) {
            throw ValidationException::withMessages(['vencimiento' => __('El vencimiento debe estar entre 2020 y los próximos dos años.')]);
        }

        return $fecha;
    }

    /**
     * @return array<string, string|int>
     */
    private function resumen(Suscripcion $suscripcion): array
    {
        return [
            'plan_id' => (int) ($suscripcion->plan_id ?? 0),
            'precio_mensual' => (string) $suscripcion->precio_mensual,
            'vencimiento' => $suscripcion->vencimiento->format('Y-m-d'),
            'dia_vencimiento' => $suscripcion->dia_vencimiento,
        ];
    }
}
