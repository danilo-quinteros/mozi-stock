<?php

namespace App\Domain\Catalogo\Actions;

use App\Domain\Auditoria\Auditor;
use App\Domain\Catalogo\Variantes;
use App\Models\Modelo;
use App\Models\Producto;
use App\Models\User;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Precio propio de una variante (ESPECIFICACION_TALLAS_Y_COLORES.md § 4.4, T1):
 * - «Cambiar precio»: la variante pasa a precio propio con los importes indicados; el cambio de precio del modelo ya
 *   no la toca;
 * - «Usar precio del modelo»: vuelve a copiar los precios del modelo y deja de tener precio propio.
 *
 * Ventas y pedidos siguen leyendo el precio del producto, como siempre. Solo el administrador, con la capacidad
 * activa; bloquea la variante con FOR UPDATE y deja auditoría.
 */
final class CambiarPrecioVariante
{
    public function __construct(
        private readonly Auditor $auditor,
        private readonly Variantes $variantes,
    ) {}

    /**
     * @param  array{precio_consumidor?: string|null, precio_minorista?: string|null, precio_mayorista?: string|null}|null  $precios
     *                                                                                                                                null = usar el precio del modelo
     */
    public function ejecutar(User $actor, Producto $variante, ?array $precios): Producto
    {
        Gate::forUser($actor)->authorize('update', $variante);
        $empresaId = (int) $actor->empresa_id;
        $this->variantes->exigir($empresaId);

        if ($variante->modelo_id === null) {
            throw ValidationException::withMessages(['precio_consumidor' => __('Este producto no es una combinación de un modelo.')]);
        }

        $nuevos = $precios === null ? null : $this->validar($precios);

        return DB::transaction(function () use ($actor, $empresaId, $variante, $nuevos): Producto {
            $bloqueada = Producto::query()->whereKey($variante->id)->lockForUpdate()->firstOrFail();
            $modelo = Modelo::query()->whereKey($bloqueada->modelo_id)->firstOrFail();
            Gate::forUser($actor)->authorize('update', $modelo);
            $antes = $bloqueada->only(['precio_consumidor', 'precio_minorista', 'precio_mayorista', 'precio_propio']);

            $valores = $nuevos ?? [
                'precio_consumidor' => $modelo->precio_consumidor,
                'precio_minorista' => $modelo->precio_minorista,
                'precio_mayorista' => $modelo->precio_mayorista,
            ];

            foreach ($valores as $campo => $valor) {
                $bloqueada->setAttribute($campo, $valor);
            }

            $bloqueada->precio_propio = $nuevos !== null;

            if ($bloqueada->isDirty()) {
                $bloqueada->save();
                $this->auditor->registrar('producto.precio_variante', $bloqueada, $actor, $empresaId, $antes,
                    $bloqueada->only(['precio_consumidor', 'precio_minorista', 'precio_mayorista', 'precio_propio']) + ['modelo' => $modelo->nombre]);
            }

            $variante->setRawAttributes($bloqueada->getAttributes(), true);

            return $variante;
        });
    }

    /**
     * @param  array<string, mixed>  $precios
     * @return array{precio_consumidor: string, precio_minorista: string|null, precio_mayorista: string|null}
     */
    private function validar(array $precios): array
    {
        $errores = [];
        $valores = [];

        foreach (['precio_consumidor' => true, 'precio_minorista' => false, 'precio_mayorista' => false] as $campo => $obligatorio) {
            $valor = $precios[$campo] ?? null;

            if (! $obligatorio && ! filled($valor)) {
                $valores[$campo] = null;

                continue;
            }

            $precio = Decimal::normalizar(is_scalar($valor) ? (string) $valor : '', Decimal::ESCALA_DINERO);

            if ($precio === null || Decimal::comparar($precio, '0', Decimal::ESCALA_DINERO) < 0) {
                $errores[$campo] = __('Ingresa un precio válido en Bs, mayor o igual a 0 y con hasta 2 decimales.');
            }

            $valores[$campo] = $precio;
        }

        if ($errores !== []) {
            throw ValidationException::withMessages($errores);
        }

        /** @var array{precio_consumidor: string, precio_minorista: string|null, precio_mayorista: string|null} $valores */
        return $valores;
    }
}
