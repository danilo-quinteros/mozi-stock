<?php

namespace App\Domain\Suscripciones\Actions;

use App\Domain\Auditoria\Auditor;
use App\Models\Plan;
use App\Models\Suscripcion;
use App\Models\User;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Gestión de planes por el superadministrador: editar título, descripción y precio,
 * o crear un nuevo plan (ej. el tercer plan). También qué incluye (sucursales y usuarios; vacío
 * = sin límite, como un plan Empresa negociado) y el precio de los extras. Cambiar el precio de
 * los extras afecta la próxima mensualidad de quienes los tienen, como el precio del plan.
 */
final class GuardarPlan
{
    public function __construct(private readonly Auditor $auditor) {}

    /**
     * @param  array{
     *     titulo: string,
     *     descripcion: string,
     *     precio_mensual: string,
     *     siat_habilitado?: bool,
     *     agente_ia?: bool,
     *     codigo?: string|null,
     *     sucursales_incluidas?: int|string|null,
     *     usuarios_incluidos?: int|string|null,
     *     precio_sucursal_extra?: string|null,
     *     precio_paquete_usuarios?: string|null,
     *     usuarios_por_paquete?: int|string|null
     * }  $datos
     */
    public function ejecutar(User $actor, array $datos, ?int $planId = null): Plan
    {
        Gate::forUser($actor)->authorize('gestionarPlanes', Suscripcion::class);

        $titulo = trim((string) $datos['titulo']);
        if ($titulo === '' || mb_strlen($titulo) > 150) {
            throw ValidationException::withMessages(['titulo' => __('Indica un título válido (hasta 150 caracteres).')]);
        }

        $descripcion = trim((string) $datos['descripcion']);
        if ($descripcion === '' || mb_strlen($descripcion) > 1000) {
            throw ValidationException::withMessages(['descripcion' => __('Indica una descripción válida (hasta 1000 caracteres).')]);
        }

        $precio = Decimal::normalizar($datos['precio_mensual'], Decimal::ESCALA_DINERO);
        if ($precio === null || Decimal::comparar($precio, '0', 2) <= 0) {
            throw ValidationException::withMessages(['precio_mensual' => __('Ingresa un precio mensual mayor que Bs 0,00 (hasta 2 decimales).')]);
        }

        $siatHabilitado = (bool) ($datos['siat_habilitado'] ?? false);
        $agenteIa = (bool) ($datos['agente_ia'] ?? false);
        $extras = $this->extras($datos);

        return DB::transaction(function () use ($actor, $planId, $titulo, $descripcion, $precio, $siatHabilitado, $agenteIa, $datos, $extras): Plan {
            if ($planId !== null) {
                $plan = Plan::query()->whereKey($planId)->lockForUpdate()->firstOrFail();
                $antes = [
                    'titulo' => $plan->titulo,
                    'descripcion' => $plan->descripcion,
                    'precio_mensual' => (string) $plan->precio_mensual,
                    'siat_habilitado' => $plan->siat_habilitado,
                    'agente_ia' => $plan->agente_ia,
                ] + $plan->only(self::EXTRAS);

                $plan->fill($extras);
                $plan->titulo = $titulo;
                $plan->descripcion = $descripcion;
                $plan->precio_mensual = $precio;
                $plan->siat_habilitado = $siatHabilitado;
                $plan->agente_ia = $agenteIa;

                if ($plan->isDirty()) {
                    $plan->save();
                    $this->auditor->registrar('plan.actualizado', $plan, $actor, null, $antes, [
                        'titulo' => $plan->titulo,
                        'descripcion' => $plan->descripcion,
                        'precio_mensual' => (string) $plan->precio_mensual,
                        'siat_habilitado' => $plan->siat_habilitado,
                        'agente_ia' => $plan->agente_ia,
                    ] + $plan->only(self::EXTRAS));
                }

                return $plan;
            }

            // Crear nuevo plan (ej. el tercer plan)
            $codigo = trim((string) ($datos['codigo'] ?? ''));
            if ($codigo === '') {
                $codigo = Str::slug($titulo);
            }

            // Asegurar unicidad de código
            $baseCodigo = $codigo;
            $i = 1;
            while (Plan::query()->where('codigo', $codigo)->exists()) {
                $codigo = $baseCodigo.'-'.$i++;
            }

            $maxOrden = (int) Plan::query()->max('orden');

            $plan = new Plan;
            $plan->fill($extras);
            $plan->codigo = $codigo;
            $plan->titulo = $titulo;
            $plan->descripcion = $descripcion;
            $plan->precio_mensual = $precio;
            $plan->siat_habilitado = $siatHabilitado;
            $plan->agente_ia = $agenteIa;
            $plan->activo = true;
            $plan->orden = $maxOrden + 1;
            $plan->save();

            $this->auditor->registrar('plan.creado', $plan, $actor, null, null, [
                'codigo' => $plan->codigo,
                'titulo' => $plan->titulo,
                'precio_mensual' => (string) $plan->precio_mensual,
                'siat_habilitado' => $plan->siat_habilitado,
                'agente_ia' => $plan->agente_ia,
            ] + $plan->only(self::EXTRAS));

            return $plan;
        });
    }

    private const EXTRAS = ['sucursales_incluidas', 'usuarios_incluidos', 'precio_sucursal_extra', 'precio_paquete_usuarios', 'usuarios_por_paquete'];

    /**
     * Solo los campos enviados: un llamado sin ellos conserva lo guardado (o los valores por defecto).
     *
     * @param  array<string, mixed>  $datos
     * @return array<string, int|string|null>
     */
    private function extras(array $datos): array
    {
        $extras = [];

        foreach (['sucursales_incluidas' => 'Sucursales incluidas', 'usuarios_incluidos' => 'Usuarios incluidos'] as $campo => $nombre) {
            if (! array_key_exists($campo, $datos)) {
                continue;
            }

            $valor = trim((string) $datos[$campo]);
            $numero = filter_var($valor, FILTER_VALIDATE_INT);

            if ($valor !== '' && ($numero === false || $numero < 1 || $numero > 9999)) {
                throw ValidationException::withMessages([$campo => __(':campo: escribe un número de 1 a 9999, o déjalo vacío para no poner límite.', ['campo' => __($nombre)])]);
            }

            $extras[$campo] = $valor === '' ? null : $numero;
        }

        foreach (['precio_sucursal_extra', 'precio_paquete_usuarios'] as $campo) {
            if (! array_key_exists($campo, $datos)) {
                continue;
            }

            $monto = Decimal::normalizar((string) $datos[$campo], Decimal::ESCALA_DINERO);

            if ($monto === null || Decimal::comparar($monto, '0', 2) < 0) {
                throw ValidationException::withMessages([$campo => __('Ingresa un precio de Bs 0,00 o más (hasta 2 decimales).')]);
            }

            $extras[$campo] = $monto;
        }

        if (array_key_exists('usuarios_por_paquete', $datos)) {
            $numero = filter_var($datos['usuarios_por_paquete'], FILTER_VALIDATE_INT);

            if ($numero === false || $numero < 1 || $numero > 100) {
                throw ValidationException::withMessages(['usuarios_por_paquete' => __('Un paquete trae de 1 a 100 usuarios.')]);
            }

            $extras['usuarios_por_paquete'] = $numero;
        }

        return $extras;
    }
}
