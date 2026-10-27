<?php

namespace App\Domain\Catalogo\Actions;

use App\Domain\Auditoria\Auditor;
use App\Models\Producto;
use App\Models\User;
use App\Support\Decimal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Alerta de stock mínimo propia de cada sucursal. Vacío = usa la alerta general del
 * producto. Solo cambia stock_minimo; la cantidad no se toca.
 */
final class ConfigurarMinimosSucursal
{
    public function __construct(private readonly Auditor $auditor) {}

    /**
     * @param  array<int|string, string|null>  $minimos  sucursal_id => mínimo o vacío
     */
    public function ejecutar(User $actor, Producto $producto, array $minimos): void
    {
        Gate::forUser($actor)->authorize('update', $producto);

        $empresaId = (int) $actor->empresa_id;
        $validas = DB::table('sucursales')->where('empresa_id', $empresaId)
            ->whereIn('id', array_map('intval', array_keys($minimos)))->pluck('id')->all();
        $valores = [];

        foreach ($minimos as $sucursalId => $valor) {
            $texto = trim((string) $valor);
            $minimo = $texto === '' ? null : Decimal::normalizar($texto, Decimal::ESCALA_CANTIDAD);

            if (! in_array((int) $sucursalId, $validas, true) || ($texto !== '' && ($minimo === null || Decimal::comparar($minimo, '0') < 0))) {
                throw ValidationException::withMessages(["minimos.{$sucursalId}" => __('Ingresa un mínimo mayor o igual a 0, o déjalo vacío.')]);
            }

            $valores[(int) $sucursalId] = $minimo;
        }

        DB::transaction(function () use ($actor, $producto, $empresaId, $valores): void {
            $ahora = Carbon::now();

            foreach ($valores as $sucursalId => $minimo) {
                DB::table('stock_niveles')->upsert(
                    [['empresa_id' => $empresaId, 'sucursal_id' => $sucursalId, 'producto_id' => $producto->id,
                        'cantidad' => '0.000', 'stock_minimo' => $minimo, 'created_at' => $ahora, 'updated_at' => $ahora]],
                    ['empresa_id', 'sucursal_id', 'producto_id'],
                    ['stock_minimo', 'updated_at'],
                );
            }

            $this->auditor->registrar('producto.minimos_sucursal', $producto, $actor, $empresaId, despues: ['minimos_por_sucursal' => $valores]);
        });
    }
}
