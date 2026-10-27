@use('App\Support\Decimal')
<div>
    <div class="page-head">
        <div>
            <div class="eyebrow">{{ __('Ficha de cliente') }}</div>
            <h1>{{ $cliente->nombre }}<span class="accent-text">.</span></h1>
            <p class="lead">{{ $cliente->documento ? 'CI/NIT '.$cliente->documento : __('Sin CI/NIT') }} · {{ $cliente->telefono ?? __('Sin teléfono') }} · {{ $cliente->correo ?? __('Sin correo') }}</p>
        </div>
        <div class="page-actions">
            <a class="btn ghost" href="{{ route('clientes.index') }}"><x-icon name="arrow-left" /> {{ __('Clientes') }}</a>
            <button class="btn soft" type="button" wire:click="editar"><x-icon name="edit" /> {{ __('Editar') }}</button>
            @if ($administra)
                <button class="btn ghost" type="button" wire:click="$set('confirmandoEstado', true)">{{ $cliente->activo ? __('Desactivar') : __('Activar') }}</button>
            @endif
        </div>
    </div>

    <div class="grid four" style="margin-bottom:18px">
        <article class="card stat"><span class="stat-label">{{ __('Valor vendido') }}</span><div class="stat-value">{{ Decimal::bs($totales['vendido']) }}</div><div class="stat-foot">{{ __('Actividad comercial, no ingreso') }}</div></article>
        <article class="card stat"><span class="stat-label">{{ __('Cobrado') }}</span><div class="stat-value">{{ Decimal::bs($totales['cobrado']) }}</div><div class="stat-foot">{{ __('Pagos realmente recibidos') }}</div></article>
        <article class="card stat"><span class="stat-label">{{ __('Saldo pendiente') }}</span><div class="stat-value">{{ Decimal::bs($totales['saldo']) }}</div><div class="stat-foot">{{ __('Créditos por cobrar') }}</div></article>
        <article class="card stat"><span class="stat-label">{{ __('Estado') }}</span><div class="stat-value" style="font-size:clamp(17px,1.8vw,22px)">{{ $cliente->activo ? __('Activo') : __('Inactivo') }}</div><div class="stat-foot">{{ $cotizaciones }} {{ $cotizaciones === 1 ? __('cotización pendiente') : __('cotizaciones pendientes') }}</div></article>
    </div>

    <section class="card card-pad">
        <div class="card-head"><div class="heading"><span class="heading-icon"><x-icon name="store" /></span>{{ __('Historial de ventas') }}</div>@if (auth()->user()->esVendedor())<span class="subtle">{{ __('Solo ventas de tu sucursal') }}</span>@endif</div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>{{ __('Venta') }}</th><th>{{ __('Fecha') }}</th><th>{{ __('Sucursal') }}</th><th>{{ __('Vendedor') }}</th><th>{{ __('Vendido') }}</th><th>{{ __('Cobrado') }}</th><th>{{ __('Saldo') }}</th><th>{{ __('Acción') }}</th></tr></thead>
                <tbody>
                    @forelse ($ventas as $venta)
                        <tr wire:key="venta-{{ $venta->id }}">
                            <td><b>{{ $venta->codigo() }}</b>@if (Decimal::comparar($venta->devuelto, '0', 2) > 0)<small style="display:block;color:var(--faint);margin-top:4px">{{ __('Devuelto :devuelto', ['devuelto' => Decimal::bs($venta->devuelto)]) }}</small>@endif</td>
                            <td>{{ $venta->fecha->timezone(config('mozistock.business_timezone'))->format('d/m/Y') }}</td>
                            <td>{{ $venta->sucursal->nombre }}</td>
                            <td>{{ $venta->vendedor->name }}</td>
                            <td>{{ Decimal::bs($venta->total) }}</td>
                            <td class="success-text">{{ Decimal::bs($venta->cobrado()) }}</td>
                            <td>@if (Decimal::comparar($venta->saldo_pendiente, '0', 2) > 0)<span class="badge warning">{{ Decimal::bs($venta->saldo_pendiente) }}</span>@else<span class="subtle">{{ __('Sin saldo') }}</span>@endif</td>
                            <td><a class="link" href="{{ route('ventas.ver', $venta) }}">{{ __('Abrir') }} <x-icon name="arrow" size="sm" /></a></td>
                        </tr>
                    @empty
                        <tr><td colspan="8"><div class="empty">{{ __('Este cliente aún no tiene ventas confirmadas.') }}</div></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($ventas->hasPages())
            <div class="table-footer">
                <span>{{ __('Página :currentpage de :lastpage', ['currentpage' => $ventas->currentPage(), 'lastpage' => $ventas->lastPage()]) }}</span>
                <span class="row">
                    @unless ($ventas->onFirstPage())<button class="btn ghost sm" type="button" wire:click="previousPage">{{ __('Anterior') }}</button>@endunless
                    @if ($ventas->hasMorePages())<button class="btn ghost sm" type="button" wire:click="nextPage">{{ __('Siguiente') }}</button>@endif
                </span>
            </div>
        @endif
    </section>

    @if ($formCliente)
        @include('livewire.contactos.partials.formulario-cliente', ['titulo' => 'Editar cliente', 'accion' => 'guardar'])
    @endif

    @if ($confirmandoEstado)
        <x-modal :titulo="($cliente->activo ? 'Desactivar ' : 'Activar ').$cliente->nombre" cerrar="cerrar"
            :descripcion="$cliente->activo ? 'No se borra ningún dato ni su historial. No aparecerá al buscar clientes en nuevos pedidos.' : 'Volverá a estar disponible en nuevos pedidos.'">
            <div class="modal-actions">
                <button type="button" class="btn ghost" wire:click="cerrar">{{ __('Cancelar') }}</button>
                <button type="button" class="btn primary" wire:click="cambiarEstado" wire:loading.attr="disabled">{{ $cliente->activo ? __('Desactivar') : __('Activar') }}</button>
            </div>
        </x-modal>
    @endif

    <x-aviso :mensaje="$aviso" :numero="$avisoNumero" />
</div>
