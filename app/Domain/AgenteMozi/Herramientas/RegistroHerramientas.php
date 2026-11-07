<?php

namespace App\Domain\AgenteMozi\Herramientas;

use App\Domain\AgenteMozi\ContextoAgente;
use App\Domain\AgenteMozi\DTOs\DatasetPropuesto;
use App\Domain\AgenteMozi\Ia\DTOs\LlamadaHerramienta;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * AiToolRegistry de § 4.2: catálogo cerrado de herramientas. Solo se ofrecen al modelo las que el
 * usuario puede usar, y al ejecutar se vuelve a autorizar y validar: nombre conocido, argumentos
 * dentro del esquema (ninguna clave extra, como empresa_id o sucursal_id) y reglas de Laravel. El
 * modelo nunca ejecuta nada por sí mismo; un fallo vuelve a él como error, sin datos.
 *
 * Solo herramientas con datos reales de la empresa (decisión del propietario del 03/11/2026): las
 * sintéticas de la Fase 2 se retiraron; el motor demo conserva sus escenarios. Cada herramienta se
 * crea con el rol del usuario para describir solo lo que ese rol puede consultar.
 */
final class RegistroHerramientas
{
    /** @var list<class-string<Herramienta>> */
    private const CATALOGO = [
        ConsultarDatos::class,
        AnalizarCobranza::class,
        CalendarioPagos::class,
        ProyeccionFlujoCaja::class,
        RevisarCajas::class,
        GastosOperativos::class,
        ComparativaSucursales::class,
        PedidosPendientes::class,
        AnalizarDevoluciones::class,
    ];

    /**
     * @return array<string, Herramienta>
     */
    public function disponibles(User $usuario): array
    {
        $disponibles = [];

        foreach (self::CATALOGO as $clase) {
            $herramienta = app()->makeWith($clase, ['administrador' => $usuario->esAdministrador()]);

            if ($this->permitida($herramienta, $usuario)) {
                $disponibles[$herramienta->nombre()] = $herramienta;
            }
        }

        return $disponibles;
    }

    /**
     * @param  array<string, Herramienta>  $disponibles
     * @return array{error: string}|array{datasets: list<array{dataset: DatasetPropuesto, descripcion: string}>}
     */
    public function ejecutar(LlamadaHerramienta $llamada, array $disponibles, User $usuario, ContextoAgente $contexto): array
    {
        $herramienta = $disponibles[$llamada->nombre] ?? null;

        if ($herramienta === null || ! $this->permitida($herramienta, $usuario)) {
            return ['error' => "La herramienta {$llamada->nombre} no existe o el usuario no tiene permiso para usarla."];
        }

        $permitidas = array_keys((array) ($herramienta->parametros()['properties'] ?? []));
        $extra = array_diff(array_map('strval', array_keys($llamada->argumentos)), $permitidas);

        if ($extra !== []) {
            return ['error' => 'Argumentos no permitidos: '.implode(', ', $extra).'. La empresa y la sucursal salen de la sesión del usuario.'];
        }

        $validador = Validator::make($llamada->argumentos, $herramienta->reglas());

        if ($validador->fails()) {
            return ['error' => 'Argumentos inválidos: '.implode(' ', $validador->errors()->all())];
        }

        try {
            return ['datasets' => $herramienta->ejecutar($contexto, $validador->validated())];
        } catch (ArgumentoInvalido $e) {
            return ['error' => 'Argumentos inválidos: '.$e->getMessage()];
        } catch (Throwable $e) {
            report($e);

            return ['error' => 'La herramienta no pudo completarse.'];
        }
    }

    private function permitida(Herramienta $herramienta, User $usuario): bool
    {
        return $herramienta->permiso() === null || Gate::forUser($usuario)->allows($herramienta->permiso());
    }
}
