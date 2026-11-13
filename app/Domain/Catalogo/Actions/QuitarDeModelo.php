<?php

namespace App\Domain\Catalogo\Actions;

use App\Domain\Auditoria\Auditor;
use App\Domain\Catalogo\Variantes;
use App\Models\Modelo;
use App\Models\Producto;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * «Quitar del modelo» (ESPECIFICACION_TALLAS_Y_COLORES.md § 9, deshacer): la combinación vuelve a ser un producto
 * suelto con su nombre actual, sin tocar su id, stock, historial, SKU, código de barras ni precios. Deja de seguir el
 * precio del modelo (precio_propio = false no aplica a un suelto).
 *
 * No se quita la última combinación activa de un modelo activo (regla § 8.1: un modelo tiene al menos una). Solo el
 * administrador, con la capacidad; bloquea el modelo y después la variante (mismo orden que GuardarModelo) y audita.
 */
final class QuitarDeModelo
{
    public function __construct(
        private readonly Auditor $auditor,
        private readonly Variantes $variantes,
    ) {}

    public function ejecutar(User $actor, Producto $variante): Producto
    {
        $empresaId = (int) $actor->empresa_id;
        abort_if($variante->empresa_id !== $empresaId, 404);
        Gate::forUser($actor)->authorize('update', $variante);
        $this->variantes->exigir($empresaId);

        if ($variante->modelo_id === null) {
            throw ValidationException::withMessages(['variante' => __('Este producto ya es suelto.')]);
        }

        return DB::transaction(function () use ($actor, $empresaId, $variante): Producto {
            $modelo = Modelo::query()->where('empresa_id', $empresaId)->whereKey($variante->modelo_id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('update', $modelo);
            $bloqueada = Producto::query()->where('empresa_id', $empresaId)->whereKey($variante->id)->lockForUpdate()->firstOrFail();

            // Otra petición ya la quitó (doble clic): no hay nada más que hacer.
            if ($bloqueada->modelo_id !== $modelo->id) {
                $variante->setRawAttributes($bloqueada->getAttributes(), true);

                return $variante;
            }

            $otrasActivas = DB::table('productos')->where('empresa_id', $empresaId)->where('modelo_id', $modelo->id)
                ->where('id', '!=', $bloqueada->id)->where('activo', true)->count();

            if ($modelo->activo && $bloqueada->activo && $otrasActivas === 0) {
                throw ValidationException::withMessages(['variante' => __('Es la única combinación activa del modelo: un modelo necesita al menos una. Desactiva el modelo o agrega otra combinación antes.')]);
            }

            $antes = $bloqueada->only(['modelo_id', 'talla_id', 'color_id', 'nombre', 'precio_propio']);
            $bloqueada->modelo_id = null;
            $bloqueada->talla_id = null;
            $bloqueada->color_id = null;
            $bloqueada->precio_propio = false;
            $bloqueada->save();

            $this->auditor->registrar('producto.quitado_de_modelo', $bloqueada, $actor, $empresaId, $antes + ['modelo' => $modelo->nombre],
                $bloqueada->only(['modelo_id', 'talla_id', 'color_id', 'nombre', 'precio_propio']));

            $variante->setRawAttributes($bloqueada->getAttributes(), true);

            return $variante;
        });
    }
}
