<?php

namespace App\Models;

use App\Domain\AgenteMozi\AccesoPorPlan;
use App\Domain\Suscripciones\CalculadoraExtras;
use App\Domain\Suscripciones\CalendarioSuscripcion;
use App\Enums\EstadoSuscripcion;
use App\Models\Concerns\BelongsToEmpresa;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Plan mensual de una empresa con MoziStudios. Su estado de cobro es informativo y nunca
 * cambia el acceso (T26). Solo se modifica con ConfigurarSuscripcion y RevisarComprobante.
 *
 * @property int $id
 * @property int $empresa_id
 * @property int|null $plan_id
 * @property int|null $plan_siguiente_id
 * @property string $precio_mensual precio del plan, sin extras
 * @property int $sucursales_extra
 * @property int $paquetes_usuarios
 * @property int $dia_vencimiento
 * @property CarbonImmutable $vencimiento
 * @property CarbonImmutable|null $prueba_hasta
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Suscripcion extends Model
{
    use BelongsToEmpresa;

    protected $table = 'suscripciones';

    /** @var array<string, int> */
    protected $attributes = ['sucursales_extra' => 0, 'paquetes_usuarios' => 0];

    protected static function booted(): void
    {
        // Cambió el plan (o su programación): el acceso a Agente Mozi se vuelve a leer.
        static::saved(fn (self $suscripcion) => app(AccesoPorPlan::class)->olvidar($suscripcion->empresa_id));
    }

    protected function casts(): array
    {
        return [
            'plan_id' => 'integer',
            'plan_siguiente_id' => 'integer',
            'precio_mensual' => 'decimal:2',
            'sucursales_extra' => 'integer',
            'paquetes_usuarios' => 'integer',
            'dia_vencimiento' => 'integer',
            'vencimiento' => 'date',
            'prueba_hasta' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'plan_id');
    }

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function planSiguiente(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'plan_siguiente_id');
    }

    /**
     * @return HasMany<CambioPlan, $this>
     */
    public function cambiosPlan(): HasMany
    {
        return $this->hasMany(CambioPlan::class);
    }

    public function estado(?CarbonImmutable $hoy = null): EstadoSuscripcion
    {
        return CalendarioSuscripcion::estado($this->vencimiento, $hoy);
    }

    /** Prueba gratis vigente (registro desde el sitio). Informativo: no cambia el acceso. */
    public function enPrueba(?CarbonImmutable $hoy = null): bool
    {
        return $this->prueba_hasta !== null && CalendarioSuscripcion::diasHasta($this->prueba_hasta, $hoy) >= 0;
    }

    public function diasHastaVencimiento(?CarbonImmutable $hoy = null): int
    {
        return CalendarioSuscripcion::diasHasta($this->vencimiento, $hoy);
    }

    /**
     * Vencimiento resultante si se aprobaran ahora $meses pagados.
     */
    public function vencimientoTras(int $meses): CarbonImmutable
    {
        return CalendarioSuscripcion::avanzar($this->vencimiento, $this->dia_vencimiento, $meses);
    }

    /** Plan con el que se renueva: el programado (downgrade) o el actual. */
    public function planRenovacion(): ?Plan
    {
        return $this->plan_siguiente_id !== null && $this->planSiguiente !== null ? $this->planSiguiente : $this->plan;
    }

    /**
     * Mensualidad de hoy: plan + extras vigentes.
     *
     * @return array{plan: string, sucursales: int, precio_sucursal: string, subtotal_sucursales: string, paquetes: int, precio_paquete: string, subtotal_paquetes: string, extras: string, total: string}
     */
    public function desgloseActual(): array
    {
        return CalculadoraExtras::desglose((string) $this->precio_mensual, $this->plan, $this->sucursales_extra, $this->paquetes_usuarios);
    }

    /**
     * Lo que se cobra en la próxima renovación: precio del plan (el reducido si hay un cambio
     * programado) más los extras con los precios de ese plan.
     *
     * @return array{plan: string, sucursales: int, precio_sucursal: string, subtotal_sucursales: string, paquetes: int, precio_paquete: string, subtotal_paquetes: string, extras: string, total: string}
     */
    public function desgloseRenovacion(): array
    {
        $programado = $this->plan_siguiente_id !== null && $this->planSiguiente !== null;
        $precioPlan = $programado ? (string) $this->planSiguiente->precio_mensual : (string) $this->precio_mensual;

        return CalculadoraExtras::desglose($precioPlan, $this->planRenovacion(), $this->sucursales_extra, $this->paquetes_usuarios);
    }

    /**
     * Precio mensual que se cobrará en la próxima renovación (plan + extras).
     */
    public function precioRenovacion(): string
    {
        return $this->desgloseRenovacion()['total'];
    }

    /**
     * Determina si la suscripción conserva el beneficio de facturación SIAT.
     * Si el plan actual incluye SIAT, conserva el beneficio hasta la fecha de vencimiento.
     */
    public function tieneBeneficioSiat(?CarbonImmutable $hoy = null): bool
    {
        $hoy = $hoy ?? CalendarioSuscripcion::hoy();

        if ($this->plan !== null && $this->plan->siat_habilitado) {
            // Si hay un downgrade programado y ya venció, el beneficio termina
            if ($this->plan_siguiente_id !== null && $this->vencimiento->lessThan($hoy)) {
                return false;
            }

            return true;
        }

        return false;
    }

    /**
     * El plan actual incluye Agente Mozi. Igual que el SIAT: con un cambio a un plan menor programado,
     * el agente sigue hasta el vencimiento ya pagado y se pierde cuando esa fecha pasa.
     */
    public function incluyeAgenteIa(?CarbonImmutable $hoy = null): bool
    {
        if ($this->plan === null || ! $this->plan->agente_ia) {
            return false;
        }

        return $this->plan_siguiente_id === null
            || ! $this->vencimiento->lessThan($hoy ?? CalendarioSuscripcion::hoy());
    }

    /**
     * Aplica el nuevo plan si había un downgrade programado y la fecha de vencimiento ya pasó.
     */
    public function aplicarPlanSiguienteSiVencio(?CarbonImmutable $hoy = null): bool
    {
        $hoy = $hoy ?? CalendarioSuscripcion::hoy();

        if ($this->plan_siguiente_id !== null && $this->vencimiento->lessThanOrEqualTo($hoy)) {
            $nuevoPlan = $this->planSiguiente;

            if ($nuevoPlan !== null) {
                $this->plan_id = $nuevoPlan->id;
                $this->precio_mensual = $nuevoPlan->precio_mensual;
                $this->plan_siguiente_id = null;
                $this->save();

                $empresa = $this->empresa;
                if ($empresa !== null) {
                    $empresa->siat_habilitado = $nuevoPlan->siat_habilitado;
                    $empresa->save();
                }

                return true;
            }
        }

        return false;
    }
}
