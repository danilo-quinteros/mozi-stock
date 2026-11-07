<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Auditoria\Auditor;
use App\Domain\Suscripciones\CalendarioSuscripcion;
use App\Enums\Rubro;
use App\Models\Plan;
use App\Models\Suscripcion;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Registro público con prueba gratis (solo modo SaaS). En una transacción crea la empresa lista
 * para trabajar y su suscripción con el plan elegido: el primer vencimiento es el fin de la prueba.
 * Al vencer, la empresa sigue activa: solo el superadmin la desactiva (regla T26); los avisos
 * de cobro de siempre le recuerdan que debe pagar.
 */
final class RegistrarEmpresaPrueba
{
    public function __construct(
        private readonly AprovisionarEmpresa $aprovisionar,
        private readonly Auditor $auditor,
    ) {}

    /**
     * @param  array{nombre: string, rubro: Rubro, nit?: string|null, telefono: string}  $empresa  rubro obligatorio (paso «Tu negocio»)
     * @param  array{name: string, email: string, password: string}  $administrador
     */
    public function ejecutar(array $empresa, array $administrador, string $codigoPlan): User
    {
        if (config('mozistock.deployment_mode') !== 'saas') {
            throw new AuthorizationException;
        }

        $plan = Plan::query()->where('activo', true)->where('codigo', $codigoPlan)->first();

        if ($plan === null) {
            throw ValidationException::withMessages(['plan' => __('Elige uno de los planes disponibles.')]);
        }

        try {
            return DB::transaction(function () use ($empresa, $administrador, $plan): User {
                [$nueva, $usuario] = $this->aprovisionar->ejecutar(
                    null,
                    ['nombre' => $empresa['nombre'], 'rubro' => $empresa['rubro'], 'nit' => $empresa['nit'] ?? null, 'telefono' => $empresa['telefono'], 'email' => $administrador['email']],
                    $administrador,
                    correoVerificado: false,
                    // Igual que al subir de plan: el plan con SIAT deja configurar la facturación.
                    siatHabilitado: $plan->siat_habilitado,
                );

                $finPrueba = CalendarioSuscripcion::hoy()->addDays((int) config('mozistock.prueba_gratis_dias'));

                $suscripcion = new Suscripcion;
                $suscripcion->empresa_id = $nueva->id;
                $suscripcion->plan_id = $plan->id;
                $suscripcion->precio_mensual = $plan->precio_mensual;
                $suscripcion->vencimiento = $finPrueba;
                $suscripcion->dia_vencimiento = $finPrueba->day;
                $suscripcion->prueba_hasta = $finPrueba;
                $suscripcion->save();

                $this->auditor->registrar('suscripcion.prueba_iniciada', $suscripcion, $usuario, $nueva->id, despues: [
                    'plan' => $plan->codigo,
                    'precio_mensual' => (string) $suscripcion->precio_mensual,
                    'prueba_hasta' => $finPrueba->format('Y-m-d'),
                    'origen' => 'registro_publico',
                ]);

                return $usuario;
            });
        } catch (UniqueConstraintViolationException) {
            // Dos envíos simultáneos con el mismo correo: el segundo no crea otra empresa.
            throw ValidationException::withMessages(['email' => __('Ya existe una cuenta con este correo. Inicia sesión o recupera tu contraseña.')]);
        }
    }
}
