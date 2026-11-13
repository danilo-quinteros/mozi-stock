<?php

namespace App\Domain\Catalogo\Actions;

use App\Domain\Inventario\ControlLotes;
use App\Domain\Inventario\LibroLotes;
use App\Models\Producto;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Acción masiva del administrador (ESPECIFICACION_LOTES_Y_VENCIMIENTOS.md § 9.2 y rubros § 5.1): marca «Controla lote y
 * vencimiento» en todos los productos que controlan stock y aún no la tienen (o solo los de una categoría). El stock de
 * cada uno pasa al lote de regularización de su sucursal, sin cambiar el total (ControlLotes).
 *
 * Una sola transacción: bloquea las fichas en orden de id, las marca, lleva su stock a regularización y deja una fila de
 * auditoría por producto (insertada en bloque). Un doble clic no repite nada: la segunda vez ya no quedan productos
 * sin marcar.
 */
final class MarcarProductosConLote
{
    private const BLOQUE = 500;

    public function __construct(private readonly LibroLotes $lotes, private readonly ControlLotes $control) {}

    /**
     * Productos que la acción marcaría ahora, para el texto de confirmación.
     */
    public function pendientes(int $empresaId, ?int $categoriaId): int
    {
        return $this->consulta($empresaId, $categoriaId)->count();
    }

    /**
     * @return int productos marcados
     */
    public function ejecutar(User $actor, ?int $categoriaId): int
    {
        Gate::forUser($actor)->authorize('create', Producto::class);
        $empresaId = (int) $actor->empresa_id;

        if (! $this->lotes->activo($empresaId)) {
            throw ValidationException::withMessages(['lotes' => __('Activa «Lotes y vencimientos» en Funciones del negocio para marcar productos.')]);
        }

        return DB::transaction(function () use ($actor, $empresaId, $categoriaId): int {
            $ids = array_values($this->consulta($empresaId, $categoriaId)->orderBy('id')->lockForUpdate()->pluck('id')
                ->map(fn ($id): int => (int) $id)->all());

            if ($ids === []) {
                return 0;
            }

            $ahora = Carbon::now();
            $ip = request()->ip();

            foreach (array_chunk($ids, self::BLOQUE) as $bloque) {
                DB::table('productos')->where('empresa_id', $empresaId)->whereIn('id', $bloque)
                    ->update(['controla_vencimiento' => true, 'updated_at' => $ahora]);
                DB::table('auditoria')->insert(array_map(fn (int $id): array => [
                    'empresa_id' => $empresaId, 'usuario_id' => $actor->id, 'accion' => 'producto.actualizado',
                    'entidad' => 'Producto', 'entidad_id' => $id,
                    'antes' => json_encode(['controla_vencimiento' => false]),
                    'despues' => json_encode(['controla_vencimiento' => true, 'masivo' => true]),
                    'ip' => $ip, 'created_at' => $ahora,
                ], $bloque));
            }

            $this->control->iniciar($actor, $empresaId, $ids, trans_choice(
                'Se marcó «Controla lote y vencimiento» en :count producto: su stock pasa al lote de regularización.|Se marcó «Controla lote y vencimiento» en :count productos: su stock pasa al lote de regularización.',
                count($ids),
            ));

            return count($ids);
        });
    }

    private function consulta(int $empresaId, ?int $categoriaId): Builder
    {
        return DB::table('productos')
            ->where('empresa_id', $empresaId)
            ->where('controla_stock', true)
            ->where('controla_vencimiento', false)
            ->when($categoriaId !== null, fn ($q) => $q->where('categoria_id', $categoriaId));
    }
}
