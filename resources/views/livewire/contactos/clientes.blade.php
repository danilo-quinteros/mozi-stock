<div>
    <div class="page-head">
        <div>
            <div class="eyebrow">{{ __('Contactos') }}</div>
            <h1>{{ __('Clientes') }}<span class="accent-text">.</span></h1>
            <p class="lead">{{ __('Gestiona clientes e historial de compras.') }}</p>
        </div>
        <div class="page-actions">
            <button class="btn primary" type="button" wire:click="nuevoCliente"><x-icon name="plus" /> {{ __('Nuevo cliente') }}</button>
        </div>
    </div>

    <div class="grid three" style="margin-bottom:18px">
        <article class="card stat"><span class="stat-label">{{ __('Clientes registrados') }}</span><div class="stat-value">{{ $resumen['registrados'] }}</div><div class="stat-foot">{{ __('En :empresa', ['empresa' => $empresa]) }}</div></article>
        <article class="card stat"><span class="stat-label">{{ __('Con ventas este mes') }}</span><div class="stat-value">{{ $resumen['con_ventas'] }}</div><div class="stat-foot">{{ auth()->user()->esVendedor() ? __('En tu sucursal') : __('Actividad del período') }}</div></article>
        <article class="card stat"><span class="stat-label">{{ __('Registros activos') }}</span><div class="stat-value">{{ $resumen['activos'] }}</div><div class="stat-foot">{{ __('Disponibles en pedidos') }}</div></article>
    </div>

    <section class="card card-pad">
        <div class="card-head"><div class="heading"><span class="heading-icon"><x-icon name="users" /></span>{{ __('Directorio de clientes') }}</div></div>
        <div class="tool-row">
            <div class="search">
                <x-icon name="search" />
                <input class="input" type="search" wire:model.live.debounce.350ms="buscar" placeholder="{{ __('Buscar por nombre, CI/NIT, teléfono, correo...') }}" aria-label="{{ __('Buscar clientes') }}">
            </div>
            <a class="btn ghost sm" href="{{ route('clientes.exportar', array_filter(['q' => trim($buscar)])) }}"><x-icon name="download" size="sm" /> {{ __('Exportar CSV') }}</a>
        </div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>{{ __('Cliente') }}</th><th>{{ __('Teléfono') }}</th><th>{{ __('Correo') }}</th><th>{{ __('Historial') }}</th><th>{{ __('Estado') }}</th><th>{{ __('Acción') }}</th></tr></thead>
                <tbody>
                    @forelse ($clientes as $cliente)
                        <tr wire:key="cliente-{{ $cliente->id }}">
                            <td><div class="item-name"><span class="token"><x-icon name="user" /></span><div><b>{{ $cliente->nombre }}</b><small>{{ $cliente->documento ? 'CI/NIT '.$cliente->documento : __('Cliente') }}</small></div></div></td>
                            <td>{{ $cliente->telefono ?? '—' }}</td>
                            <td>{{ $cliente->correo ?? '—' }}</td>
                            <td>{{ $cliente->ventas }} {{ (int) $cliente->ventas === 1 ? 'venta' : 'ventas' }}</td>
                            <td><span class="badge {{ $cliente->activo ? 'success' : 'danger' }}">{{ $cliente->activo ? __('Activo') : __('Inactivo') }}</span></td>
                            <td><span class="row" style="gap:6px;flex-wrap:nowrap"><button class="btn sm ghost" type="button" wire:click="editarCliente({{ $cliente->id }})" title="{{ __('Editar') }}" aria-label="{{ __('Editar :nombre', ['nombre' => $cliente->nombre]) }}"><x-icon name="edit" size="sm" /> {{ __('Editar') }}</button><a class="btn sm ghost" href="{{ route('clientes.ver', $cliente->id) }}">{{ __('Ver ficha') }} <x-icon name="arrow" size="sm" /></a></span></td>
                        </tr>
                    @empty
                        <tr><td colspan="6"><div class="empty">{{ trim($buscar) === '' ? __('Aún no registras clientes.') : __('No hay clientes que coincidan con la búsqueda.') }}</div></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($clientes->hasPages())
            <div class="table-footer">
                <span>{{ __('Página :currentpage de :lastpage', ['currentpage' => $clientes->currentPage(), 'lastpage' => $clientes->lastPage()]) }}</span>
                <span class="row">
                    @unless ($clientes->onFirstPage())<button class="btn ghost sm" type="button" wire:click="previousPage">{{ __('Anterior') }}</button>@endunless
                    @if ($clientes->hasMorePages())<button class="btn ghost sm" type="button" wire:click="nextPage">{{ __('Siguiente') }}</button>@endif
                </span>
            </div>
        @endif
    </section>

    @if ($formCliente)
        @include('livewire.contactos.partials.formulario-cliente', $editandoId === null
            ? ['titulo' => 'Nuevo cliente', 'accion' => 'crearCliente']
            : ['titulo' => 'Editar cliente', 'accion' => 'actualizarCliente'])
    @endif

    <x-aviso :mensaje="$aviso" :numero="$avisoNumero" />
</div>
