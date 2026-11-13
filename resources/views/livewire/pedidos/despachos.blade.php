@use('App\Support\Decimal')
@use('App\Enums\EstadoEntrega')
@use('App\Domain\Despachos\HistorialEntrega')
<div>
    <div class="entrega-filtro" role="group" aria-label="{{ __('Filtrar por estado de entrega') }}">
        @foreach (EstadoEntrega::delFlujo(\App\Enums\Capacidad::Despachos) as $opcion)
            <button type="button" class="entrega-chip {{ $estado === $opcion->value ? 'active' : '' }}" aria-pressed="{{ $estado === $opcion->value ? 'true' : 'false' }}" wire:click="$set('estado', '{{ $opcion->value }}')">
                <span class="entrega-punto {{ $opcion->insignia() }}"></span>{{ $opcion->etiqueta() }}<b>{{ $conteos[$opcion->value] }}</b>
            </button>
        @endforeach
    </div>

    <div class="tool-row">
        <div class="search">
            <x-icon name="search" />
            <input class="input" type="search" wire:model.live.debounce.350ms="cliente" placeholder="{{ __('Buscar cliente o CI/NIT...') }}" aria-label="{{ __('Buscar cliente') }}">
        </div>
        @if ($administra)
            <select class="select" wire:model.live="sucursal" aria-label="{{ __('Filtrar sucursal') }}" @disabled($sucursalFija) @if ($sucursalFija) title="{{ __('Sucursal fijada en el menú lateral') }}" @endif>
                <option value="">{{ __('Todas las sucursales') }}</option>
                @foreach ($sucursales as $opcion)
                    <option value="{{ $opcion->id }}">{{ $opcion->nombre }}</option>
                @endforeach
            </select>
        @endif
        <label class="row" style="gap:6px"><span class="subtle">{{ __('Desde') }}</span><input class="input" type="date" wire:model.live="desde" aria-label="{{ __('Venta desde') }}"></label>
        <label class="row" style="gap:6px"><span class="subtle">{{ __('hasta') }}</span><input class="input" type="date" wire:model.live="hasta" aria-label="{{ __('Venta hasta') }}"></label>
        <a class="btn ghost sm" href="{{ route('pedidos.despachos.exportar', $exportar) }}"><x-icon name="download" size="sm" /> {{ __('Exportar CSV') }}</a>
    </div>

    <div class="table-wrap">
        <table class="despachos-tabla">
            <thead><tr><th>{{ __('Venta') }}</th><th>{{ __('Cliente') }}</th><th>{{ __('Sucursal') }}</th><th>{{ __('Productos') }}</th><th>{{ __('Total') }}</th><th>{{ __('Pago') }}</th><th>{{ __('Entrega') }}</th><th>{{ __('Acción') }}</th></tr></thead>
            <tbody>
                @forelse ($ventas as $venta)
                    <tr wire:key="despacho-{{ $venta->id }}">
                        <td><b>{{ $venta->codigo() }}</b><small>{{ $venta->fecha->timezone(config('mozistock.business_timezone'))->format('d/m/Y H:i') }} · {{ $venta->vendedor->name }}</small></td>
                        <td>{{ $venta->cliente->nombre ?? __('Consumidor final') }}</td>
                        <td>{{ $venta->sucursal->nombre }}</td>
                        <td>{{ trans_choice(':n producto|:n productos', $venta->items_count, ['n' => $venta->items_count]) }}</td>
                        <td>{{ Decimal::bs($venta->total) }}</td>
                        <td>
                            @if (Decimal::comparar($venta->saldo_pendiente, '0', 2) > 0)
                                <span class="badge warning">{{ __('Saldo :saldo', ['saldo' => Decimal::bs($venta->saldo_pendiente)]) }}</span>
                            @else
                                <span class="badge success">{{ __('Pagado') }}</span>
                            @endif
                        </td>
                        <td>
                            <span class="badge {{ $venta->estado_entrega->insignia() }}">{{ $venta->estado_entrega->etiqueta() }}</span>
                            @if ($venta->estado_entrega === EstadoEntrega::Despachado)
                                <small>{{ __(':usuario :momento', ['usuario' => $venta->despachador->name ?? '', 'momento' => HistorialEntrega::momento($venta->despachado_en)]) }}@if ($venta->despacho_retira) · {{ __('Retira: :nombre', ['nombre' => $venta->despacho_retira]) }}@endif</small>
                            @elseif ($venta->estado_entrega === EstadoEntrega::Entregado && $venta->entregador)
                                <small>{{ __(':usuario :momento', ['usuario' => $venta->entregador->name, 'momento' => HistorialEntrega::momento($venta->entregado_en)]) }}@if ($venta->entrega_recibio) · {{ __('Recibió: :nombre', ['nombre' => $venta->entrega_recibio]) }}@endif</small>
                            @endif
                        </td>
                        <td>
                            <span class="row" style="gap:6px;flex-wrap:nowrap">
                                @if ($venta->estado_entrega === EstadoEntrega::PorDespachar)
                                    @can('despachar', $venta)
                                        <button type="button" class="btn primary sm" wire:click="abrirEntrega({{ $venta->id }}, 'despachar')">{{ __('Despachar') }}</button>
                                        <button type="button" class="btn soft sm" wire:click="abrirEntrega({{ $venta->id }}, 'despachar_entregar')">{{ __('Despachar y entregar') }}</button>
                                    @endcan
                                @elseif ($venta->estado_entrega === EstadoEntrega::Despachado)
                                    @can('marcarEntregada', $venta)
                                        <button type="button" class="btn primary sm" wire:click="abrirEntrega({{ $venta->id }}, 'entregar')">{{ __('Marcar entregado') }}</button>
                                    @endcan
                                @endif
                                <a class="btn ghost sm" href="{{ route('ventas.ver', $venta) }}" aria-label="{{ __('Abrir :codigo', ['codigo' => $venta->codigo()]) }}">{{ __('Abrir') }} <x-icon name="arrow" size="sm" /></a>
                            </span>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8"><div class="empty">{{ $estado === EstadoEntrega::PorDespachar->value ? __('No hay ventas por despachar con este filtro.') : __('No hay ventas en este estado con el filtro elegido.') }}</div></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($ventas->hasPages())
        <div class="table-footer">
            <span>{{ __('Página :actual de :total', ['actual' => $ventas->currentPage(), 'total' => $ventas->lastPage()]) }}</span>
            <span class="row">
                @unless ($ventas->onFirstPage())<button class="btn ghost sm" type="button" wire:click="previousPage">{{ __('Anterior') }}</button>@endunless
                @if ($ventas->hasMorePages())<button class="btn ghost sm" type="button" wire:click="nextPage">{{ __('Siguiente') }}</button>@endif
            </span>
        </div>
    @endif
    <div class="divider"></div>
    <div class="notice"><x-icon name="info" /><div><b>{{ __('La entrega no mueve stock ni dinero') }}</b><p>{{ __('El stock se descontó al confirmar la venta y queda reservado hasta despacharla. Despachar autoriza la salida del almacén; los cobros se registran aparte, en Historial de pagos.') }}</p></div></div>

    @include('livewire.despachos.dialogo')

    <x-aviso :mensaje="$aviso" :numero="$avisoNumero" :tipo="$avisoTipo" />
</div>
