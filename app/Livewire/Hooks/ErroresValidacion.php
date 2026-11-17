<?php

namespace App\Livewire\Hooks;

use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\Features\SupportFormObjects\Form;
use Livewire\Livewire;
use Livewire\Mechanisms\HandleComponents\ComponentContext;
use Throwable;
use WeakMap;

/**
 * Aviso rojo de validación (resources/js/app.js). Livewire solo devuelve al navegador los errores cuya clave es una
 * propiedad del componente (memo.errors), así que los de otras claves —p. ej. `lineas`, el stock insuficiente de
 * LibroStock en Nuevo envasado— no llegaban al aviso. Cuando una acción termina con ValidationException, se envían
 * además todos sus mensajes como efecto `erroresValidacion`. No valida nada ni cambia el error bag.
 */
final class ErroresValidacion
{
    public const EFECTO = 'erroresValidacion';

    /** @var WeakMap<Component, list<string>>|null */
    private static ?WeakMap $pendientes = null;

    public static function registrar(): void
    {
        self::$pendientes = new WeakMap;

        Livewire::listen('exception', function (mixed $objetivo, Throwable $e): void {
            if (! $e instanceof ValidationException) {
                return;
            }

            $componente = $objetivo instanceof Form ? $objetivo->getComponent() : $objetivo;

            if ($componente instanceof Component && self::$pendientes !== null) {
                self::$pendientes[$componente] = self::mensajes($e->errors());
            }
        });

        Livewire::listen('dehydrate', function (Component $componente, ComponentContext $contexto): void {
            if (self::$pendientes === null || ! isset(self::$pendientes[$componente])) {
                return;
            }

            $mensajes = self::$pendientes[$componente];
            unset(self::$pendientes[$componente]);

            if ($mensajes !== []) {
                $contexto->addEffect(self::EFECTO, $mensajes);
            }
        });
    }

    /**
     * @param  array<string, array<int, mixed>>  $errores
     * @return list<string>
     */
    private static function mensajes(array $errores): array
    {
        $mensajes = [];

        foreach ($errores as $lista) {
            foreach ($lista as $mensaje) {
                if (is_string($mensaje) && $mensaje !== '') {
                    $mensajes[] = $mensaje;
                }
            }
        }

        return $mensajes;
    }
}
