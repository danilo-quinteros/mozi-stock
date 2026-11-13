<?php

namespace App\Domain\Suscripciones\Actions;

use App\Domain\Auditoria\Auditor;
use App\Domain\Suscripciones\CalculadoraExtras;
use App\Domain\Suscripciones\CupoSuscripcion;
use App\Enums\EstadoPagoSaas;
use App\Models\PagoSaas;
use App\Models\Suscripcion;
use App\Models\User;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * La empresa elige cuántas sucursales extra y paquetes de usuarios suma a su plan.
 * - Si no hay nada que pagar hoy (quita extras, está en prueba gratis o con la suscripción
 *   vencida), el cambio se aplica al instante y el próximo pago ya trae la mensualidad nueva.
 *   Quitar extras no devuelve lo ya pagado.
 * - Si agregar cuesta algo hoy, se paga con un comprobante de extras (EnviarComprobantePago::extras)
 *   y se activa cuando MoziStudios lo aprueba.
 * No se quitan lugares que están en uso: primero hay que desactivar la sucursal o el usuario.
 */
final class CambiarExtrasSuscripcion
{
    public function __construct(private readonly Auditor $auditor) {}

    public function ejecutar(User $actor, int $sucursales, int $paquetes): Suscripcion
    {
        Gate::forUser($actor)->authorize('cambiarPlan', Suscripcion::class);

        return DB::transaction(function () use ($actor, $sucursales, $paquetes): Suscripcion {
            $empresaId = (int) $actor->empresa_id;
            $suscripcion = Suscripcion::query()->where('empresa_id', $empresaId)->with('plan')->lockForUpdate()->firstOrFail();
            self::validar($suscripcion, $sucursales, $paquetes);

            if ($suscripcion->sucursales_extra === $sucursales && $suscripcion->paquetes_usuarios === $paquetes) {
                return $suscripcion;
            }

            $diferencia = CalculadoraExtras::diferencia($suscripcion, $sucursales, $paquetes);

            if (Decimal::comparar($diferencia['importe'], '0', 2) > 0) {
                throw ValidationException::withMessages(['extras' => __('Para sumar estos extras hoy debes pagar :importe. Envía el comprobante y se activarán cuando MoziStudios lo apruebe.', [
                    'importe' => Decimal::bs($diferencia['importe']),
                ])]);
            }

            $antes = ['sucursales_extra' => $suscripcion->sucursales_extra, 'paquetes_usuarios' => $suscripcion->paquetes_usuarios];
            $suscripcion->sucursales_extra = $sucursales;
            $suscripcion->paquetes_usuarios = $paquetes;
            $suscripcion->save();

            $this->auditor->registrar('suscripcion.extras_actualizados', $suscripcion, $actor, $empresaId, $antes, [
                'sucursales_extra' => $sucursales,
                'paquetes_usuarios' => $paquetes,
                'sin_cobro' => $diferencia['motivo_gratis'] ?? 'sin_aumento',
                'mensualidad_proxima' => $suscripcion->precioRenovacion(),
            ]);

            return $suscripcion;
        });
    }

    /**
     * Reglas comunes al cambio inmediato y al comprobante de extras. Llamar con la suscripción bloqueada.
     */
    public static function validar(Suscripcion $suscripcion, int $sucursales, int $paquetes): void
    {
        $plan = $suscripcion->plan;

        if ($plan === null) {
            throw ValidationException::withMessages(['extras' => __('Tu plan todavía no está configurado. Escribe a MoziStudios.')]);
        }

        if ($sucursales < 0 || $paquetes < 0 || $sucursales > CalculadoraExtras::MAXIMO || $paquetes > CalculadoraExtras::MAXIMO) {
            throw ValidationException::withMessages(['extras' => __('Elige entre 0 y :max extras. Para más, consulta el plan Empresa.', ['max' => CalculadoraExtras::MAXIMO])]);
        }

        if (($plan->sucursales_incluidas === null && $sucursales > 0) || ($plan->usuarios_incluidos === null && $paquetes > 0)) {
            throw ValidationException::withMessages(['extras' => __('Tu plan ya no tiene límite en eso: no necesitas extras.')]);
        }

        if (PagoSaas::query()->where('empresa_id', $suscripcion->empresa_id)->where('estado', EstadoPagoSaas::Pendiente)->exists()) {
            throw ValidationException::withMessages(['extras' => __('Tienes un comprobante en revisión. Podrás cambiar tus extras cuando MoziStudios responda.')]);
        }

        $limites = CupoSuscripcion::limites($suscripcion, $sucursales, $paquetes);
        $uso = CupoSuscripcion::uso($suscripcion->empresa_id);

        // Solo al quitar: una empresa que ya superaba el cupo puede sumar extras igual.
        if ($sucursales < $suscripcion->sucursales_extra && $limites['sucursales'] !== null && $uso['sucursales'] > $limites['sucursales']) {
            throw ValidationException::withMessages(['extras' => __('Tienes :uso sucursales activas y así te quedarían :limite. Desactiva una sucursal antes de quitar el extra.', [
                'uso' => $uso['sucursales'], 'limite' => $limites['sucursales'],
            ])]);
        }

        if ($paquetes < $suscripcion->paquetes_usuarios && $limites['usuarios'] !== null && $uso['usuarios'] > $limites['usuarios']) {
            throw ValidationException::withMessages(['extras' => __('Tienes :uso usuarios activos y así te quedarían :limite. Desactiva usuarios antes de quitar el paquete.', [
                'uso' => $uso['usuarios'], 'limite' => $limites['usuarios'],
            ])]);
        }
    }
}
