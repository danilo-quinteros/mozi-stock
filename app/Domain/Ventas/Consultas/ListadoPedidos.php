<?php

namespace App\Domain\Ventas\Consultas;

use App\Models\Pedido;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Listado único de Pedidos y ventas (pestañas Todos, Cotizaciones, Ventas y A crédito).
 * El vendedor solo ve su sucursal; el administrador, la fijada en el menú lateral (o todas). La pantalla y el CSV comparten esta consulta.
 */
final class ListadoPedidos
{
    public const PESTANAS = ['todos', 'cotizaciones', 'ventas', 'credito'];

    /**
     * @return Builder<Pedido>
     */
    public static function consulta(User $usuario, string $pestana = 'todos', string $buscar = '', string $estado = ''): Builder
    {
        $termino = trim($buscar);
        $patron = '%'.addcslashes($termino, '\\%_').'%';
        $numero = preg_replace('/\D/', '', $termino);
        $sucursalId = $usuario->sucursalActivaId();

        return Pedido::query()
            ->where('pedidos.empresa_id', $usuario->empresa_id)
            ->when($sucursalId !== null, fn (Builder $q) => $q->where('pedidos.sucursal_id', $sucursalId))
            ->with(['cliente:id,nombre', 'vendedor:id,name', 'venta:id,pedido_id,numero,total,saldo_pendiente,devuelto,reembolsado'])
            ->when($pestana === 'cotizaciones', fn (Builder $q) => $q->where('estado', '<>', 'vendido'))
            ->when($pestana === 'ventas', fn (Builder $q) => $q->where('estado', 'vendido'))
            ->when($pestana === 'credito', fn (Builder $q) => $q->whereHas('venta', fn (Builder $v) => $v->where('saldo_pendiente', '>', 0)))
            ->when(in_array($estado, ['pendiente', 'vendido', 'cancelado'], true), fn (Builder $q) => $q->where('estado', $estado))
            ->when($termino !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->whereHas('cliente', fn (Builder $c) => $c->where('nombre', 'like', $patron)->orWhere('documento', 'like', $patron))
                ->when($numero !== '' && $numero !== null, fn (Builder $q) => $q->orWhere('pedidos.numero', (int) $numero)
                    ->orWhereHas('venta', fn (Builder $v) => $v->where('numero', (int) $numero)))))
            ->orderByDesc('pedidos.id');
    }
}
