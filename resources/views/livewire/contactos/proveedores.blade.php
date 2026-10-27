<div>
    <div class="page-head">
        <div>
            <div class="eyebrow">{{ __('Contactos') }}</div>
            <h1>{{ __('Proveedores') }}<span class="accent-text">.</span></h1>
            <p class="lead">{{ __('Datos y compras de tus proveedores.') }}</p>
        </div>
        <div class="page-actions">
            <button class="btn primary" type="button" wire:click="nuevoProveedor"><x-icon name="plus" /> {{ __('Nuevo proveedor') }}</button>
        </div>
    </div>

    <div class="grid three" style="margin-bottom:18px">
        <article class="card stat"><span class="stat-label">{{ __('Proveedores registrados') }}</span><div class="stat-value">{{ $resumen['registrados'] }}</div><div class="stat-foot">{{ __('En :empresa', ['empresa' => $empresa]) }}</div></article>
        <article class="card stat"><span class="stat-label">{{ __('Con compras este mes') }}</span><div class="stat-value">{{ $resumen['con_compras'] }}</div><div class="stat-foot">{{ __('Actividad del período') }}</div></article>
        <article class="card stat"><span class="stat-label">{{ __('Registros activos') }}</span><div class="stat-value">{{ $resumen['activos'] }}</div><div class="stat-foot">{{ __('Disponibles en compras') }}</div></article>
    </div>

    <section class="card card-pad">
        <div class="card-head"><div class="heading"><span class="heading-icon"><x-icon name="building" /></span>{{ __('Directorio de proveedores') }}</div></div>
        <div class="tool-row">
            <div class="search">
                <x-icon name="search" />
                <input class="input" type="search" wire:model.live.debounce.350ms="buscar" placeholder="{{ __('Buscar por empresa, teléfono, ciudad...') }}" aria-label="{{ __('Buscar proveedores') }}">
            </div>
            <a class="btn ghost sm" href="{{ route('proveedores.exportar', array_filter(['q' => trim($buscar)])) }}"><x-icon name="download" size="sm" /> {{ __('Exportar CSV') }}</a>
        </div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>{{ __('Proveedor') }}</th><th>{{ __('Ciudad') }}</th><th>{{ __('Teléfono') }}</th><th>{{ __('Historial') }}</th><th>{{ __('Estado') }}</th><th>{{ __('Acción') }}</th></tr></thead>
                <tbody>
                    @forelse ($proveedores as $proveedor)
                        <tr wire:key="proveedor-{{ $proveedor->id }}">
                            <td><div class="item-name"><span class="token"><x-icon name="building" /></span><div><b>{{ $proveedor->nombre }}</b><small>{{ $proveedor->documento ? 'NIT/CI '.$proveedor->documento : __('Proveedor') }}</small></div></div></td>
                            <td>{{ $proveedor->ciudad ?? '—' }}</td>
                            <td>{{ $proveedor->telefono ?? '—' }}</td>
                            <td>{{ $proveedor->compras }} {{ (int) $proveedor->compras === 1 ? 'compra' : 'compras' }}</td>
                            <td><span class="badge {{ $proveedor->activo ? 'success' : 'danger' }}">{{ $proveedor->activo ? __('Activo') : __('Inactivo') }}</span></td>
                            <td><span class="row" style="gap:6px;flex-wrap:nowrap"><button class="btn sm ghost" type="button" wire:click="editarProveedor({{ $proveedor->id }})" title="{{ __('Editar') }}" aria-label="{{ __('Editar :nombre', ['nombre' => $proveedor->nombre]) }}"><x-icon name="edit" size="sm" /> {{ __('Editar') }}</button><a class="btn sm ghost" href="{{ route('proveedores.ver', $proveedor->id) }}">{{ __('Ver ficha') }} <x-icon name="arrow" size="sm" /></a></span></td>
                        </tr>
                    @empty
                        <tr><td colspan="6"><div class="empty">{{ trim($buscar) === '' ? __('Aún no registras proveedores.') : __('No hay proveedores que coincidan con la búsqueda.') }}</div></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($proveedores->hasPages())
            <div class="table-footer">
                <span>{{ __('Página :currentpage de :lastpage', ['currentpage' => $proveedores->currentPage(), 'lastpage' => $proveedores->lastPage()]) }}</span>
                <span class="row">
                    @unless ($proveedores->onFirstPage())<button class="btn ghost sm" type="button" wire:click="previousPage">{{ __('Anterior') }}</button>@endunless
                    @if ($proveedores->hasMorePages())<button class="btn ghost sm" type="button" wire:click="nextPage">{{ __('Siguiente') }}</button>@endif
                </span>
            </div>
        @endif
    </section>

    @if ($formProveedor)
        @include('livewire.contactos.partials.formulario-proveedor', $editandoId === null
            ? ['titulo' => 'Nuevo proveedor', 'accion' => 'crearProveedor']
            : ['titulo' => 'Editar proveedor', 'accion' => 'actualizarProveedor'])
    @endif

    <x-aviso :mensaje="$aviso" :numero="$avisoNumero" />
</div>
