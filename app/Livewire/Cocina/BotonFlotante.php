<?php

namespace App\Livewire\Cocina;

use App\Domain\Despachos\Consultas\PedidosEnCocina;
use App\Models\Empresa;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Botón flotante «En cocina» (ESPECIFICACION_COCINA.md § 4.2): contador de pedidos En cocina, en color de
 * alerta si alguno pasó el límite. Se actualiza cada 10 segundos solo mientras está visible
 * (wire:poll.visible) y al entregar o cambiar el turno de caja. Quién lo ve y de qué sucursal lo decide
 * PedidosEnCocina en cada render; abrirlo solo avisa al panel (Cocina\Panel), que vuelve a comprobarlo.
 *
 * El administrador cuenta los pedidos de la sucursal elegida en el menú lateral (todas si no eligió).
 */
class BotonFlotante extends Component
{
    #[On('cocina-actualizada')]
    #[On('turno-modificado')]
    public function actualizar(): void
    {
        // Solo vuelve a contar.
    }

    public function render(): View
    {
        /** @var User $actor */
        $actor = Auth::user();
        $pedidos = app(PedidosEnCocina::class);

        if (! $pedidos->visible($actor)) {
            return view('livewire.cocina.boton-flotante', ['visible' => false]);
        }

        $minutos = (int) Empresa::query()->whereKey($actor->empresa_id)->value('minutos_alerta_cocina');
        $conteo = PedidosEnCocina::conteo($actor, PedidosEnCocina::sucursalDe($actor, $actor->sucursalActivaId()), $minutos);

        return view('livewire.cocina.boton-flotante', ['visible' => true, ...$conteo]);
    }
}
