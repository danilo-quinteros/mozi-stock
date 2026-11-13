<?php

namespace App\Domain\Despachos\Consultas;

use App\Domain\Reportes\Periodo;
use App\Enums\Capacidad;
use App\Enums\EstadoEntrega;
use App\Models\User;
use App\Models\Venta;
use Illuminate\Database\Eloquent\Builder;

/**
 * Ventas para la pestaña Despachos de Pedidos y ventas (ESPECIFICACION_DESPACHOS.md § 4), filtradas por
 * estado de entrega, sucursal, cliente y fecha de la venta. Siempre de la empresa del usuario: el vendedor
 * ve solo su sucursal asignada aunque pida otra; el administrador, la que elija (null = todas) o, si no
 * pasa el filtro, la fijada en el menú lateral. La pantalla y su export comparten esta consulta.
 *
 * Índices: (empresa, sucursal, estado, fecha) con sucursal y (empresa, estado, fecha) para todas.
 */
final class ListadoDespachos
{
    /**
     * @param  array{estado?: string|null, sucursal_id?: int|null, cliente?: string|null, desde?: string|null, hasta?: string|null}  $filtros
     * @return Builder<Venta>
     */
    public static function consulta(User $usuario, array $filtros = []): Builder
    {
        $estado = EstadoEntrega::tryFrom((string) ($filtros['estado'] ?? ''));
        // Solo los estados de despachos: la pestaña no lista pedidos En cocina.
        $estado = in_array($estado, EstadoEntrega::delFlujo(Capacidad::Despachos), true) ? $estado : EstadoEntrega::PorDespachar;

        return self::base($usuario, $filtros)
            ->where('ventas.estado_entrega', $estado->value)
            ->with(['cliente:id,nombre', 'sucursal:id,nombre', 'vendedor:id,name'])
            // Lo pendiente se atiende por orden de llegada; lo ya resuelto, de lo más reciente a lo más antiguo.
            ->when(in_array($estado, EstadoEntrega::pendientes(Capacidad::Despachos), true),
                fn (Builder $q) => $q->orderBy('ventas.fecha')->orderBy('ventas.id'),
                fn (Builder $q) => $q->orderByDesc('ventas.fecha')->orderByDesc('ventas.id'));
    }

    /**
     * Cantidad de ventas por estado con los mismos filtros de sucursal, cliente y fecha (una consulta).
     *
     * @param  array{sucursal_id?: int|null, cliente?: string|null, desde?: string|null, hasta?: string|null}  $filtros
     * @return array<string, int> estado => ventas, con todos los estados de despachos
     */
    public static function conteos(User $usuario, array $filtros = []): array
    {
        $filas = self::base($usuario, $filtros)->toBase()
            ->groupBy('ventas.estado_entrega')
            ->selectRaw('ventas.estado_entrega AS estado, COUNT(*) AS ventas')
            ->pluck('ventas', 'estado');

        $conteos = [];

        foreach (EstadoEntrega::delFlujo(Capacidad::Despachos) as $estado) {
            $conteos[$estado->value] = (int) ($filas[$estado->value] ?? 0);
        }

        return $conteos;
    }

    /**
     * @param  array{estado?: string|null, sucursal_id?: int|null, cliente?: string|null, desde?: string|null, hasta?: string|null}  $filtros
     * @return Builder<Venta>
     */
    private static function base(User $usuario, array $filtros): Builder
    {
        $sucursalId = match (true) {
            $usuario->esVendedor() => $usuario->sucursal_id,
            array_key_exists('sucursal_id', $filtros) => $filtros['sucursal_id'],
            default => $usuario->sucursalActivaId(),
        };
        $termino = trim((string) ($filtros['cliente'] ?? ''));
        $patron = '%'.addcslashes($termino, '\%_').'%';
        $desde = $filtros['desde'] ?? null;
        $hasta = $filtros['hasta'] ?? null;

        return Venta::query()
            ->where('ventas.empresa_id', $usuario->empresa_id)
            ->when($sucursalId !== null, fn (Builder $q) => $q->where('ventas.sucursal_id', $sucursalId))
            ->when($termino !== '', fn (Builder $q) => $q->whereHas('cliente', fn (Builder $c) => $c
                ->where(fn (Builder $w) => $w->where('nombre', 'like', $patron)->orWhere('documento', 'like', $patron))))
            // Con una sola fecha, ese día; fechas en hora del negocio (La Paz) convertidas a UTC.
            ->when($desde !== null || $hasta !== null, function (Builder $q) use ($desde, $hasta): void {
                [$inicio, $fin] = Periodo::rango($desde ?? $hasta, $hasta ?? $desde)->limitesUtc();
                $q->where('ventas.fecha', '>=', $inicio)->where('ventas.fecha', '<', $fin);
            });
    }
}
