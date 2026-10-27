@use('App\Support\Decimal')
@use('App\Domain\Catalogo\Consultas\ListadoProductos')
<div>
    <div class="page-head">
        <div>
            <div class="eyebrow">{{ __('Catálogo') }}</div>
            <h1>{{ __('Productos') }}<span class="accent-text">.</span></h1>
            <p class="lead">{{ __('Tus productos, precios e inventario en un solo lugar.') }}</p>
        </div>
        @if ($administra)
            <div class="page-actions">
                <a class="btn primary" href="{{ route('productos.crear') }}"><x-icon name="plus" /> {{ __('Nuevo producto') }}</a>
            </div>
        @endif
    </div>

    <div class="grid four" style="margin-bottom:18px">
        <article class="card stat"><span class="stat-label">{{ __('Productos activos') }}</span><div class="stat-value">{{ $totalProductos }}</div><div class="stat-foot">{{ __('Ficha única para todas las sucursales') }}</div></article>
        <article class="card stat"><span class="stat-label">{{ __('Categorías') }}</span><div class="stat-value">{{ $categorias->where('activa', true)->count() }}</div><div class="stat-foot">{{ __('Organización simple') }}</div></article>
        <article class="card stat"><span class="stat-label">{{ __('Existencias bajas') }}</span><div class="stat-value">{{ $alertas }}</div><div class="stat-foot">{{ $sucursalVista ? __('En ').$sucursalVista->nombre : __('Sin sucursal') }}</div></article>
        <article class="card stat"><span class="stat-label">{{ __('Stock de') }}</span><div class="stat-value" style="font-size:clamp(18px,2vw,24px)">{{ $sucursalVista->nombre ?? '—' }}</div><div class="stat-foot">{{ $administra ? __('Elige la sucursal en el filtro') : __('Tu sucursal asignada') }}</div></article>
    </div>

    <section class="card card-pad">
        <div class="card-head"><div class="heading"><span class="heading-icon"><x-icon name="store" /></span>{{ __('Catálogo de productos') }}</div></div>
        <div class="tabs" role="tablist">
            @foreach (['productos' => 'Productos', 'categorias' => 'Categorías', 'unidades' => 'Unidades y medidas'] as $clave => $etiqueta)
                <button type="button" role="tab" class="tab {{ $pestana === $clave ? 'active' : '' }}" aria-selected="{{ $pestana === $clave ? 'true' : 'false' }}" wire:click="$set('pestana', '{{ $clave }}')">{{ __($etiqueta) }}</button>
            @endforeach
        </div>

        @if ($pestana === 'productos')
            <div class="tool-row">
                <div class="search">
                    <x-icon name="search" />
                    <input class="input" type="search" wire:model.live.debounce.350ms="buscar" placeholder="{{ __('Buscar por nombre, código o código de barras...') }}" aria-label="{{ __('Buscar productos') }}">
                </div>
                <select class="select" wire:model.live="categoria" aria-label="{{ __('Filtrar por categoría') }}">
                    <option value="">{{ __('Todas las categorías') }}</option>
                    @foreach ($categorias as $opcion)
                        <option value="{{ $opcion->id }}">{{ $opcion->nombre }}</option>
                    @endforeach
                </select>
                @if ($administra && $sucursales->count() > 1)
                    <select class="select" wire:model.live="sucursal" @disabled($sucursalFija) aria-label="{{ __('Ver stock de la sucursal') }}">
                        @foreach ($sucursales as $opcion)
                            <option value="{{ $opcion->id }}" @selected($sucursalVista?->id === $opcion->id)>{{ __('Stock · :nombre', ['nombre' => $opcion->nombre]) }}</option>
                        @endforeach
                    </select>
                @endif
                <a class="btn ghost sm" href="{{ route('productos.exportar', array_filter(['q' => trim($buscar), 'categoria' => $categoria, 'sucursal' => $sucursalVista?->id])) }}"><x-icon name="download" size="sm" /> {{ __('Exportar CSV') }}</a>
            </div>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>{{ __('Producto / código') }}</th><th>{{ __('Categoría') }}</th><th>{{ __('Consumidor') }}</th><th>{{ __('Minorista') }}</th><th>{{ __('Mayorista') }}</th><th>{{ __('Stock · :nombre', ['nombre' => $sucursalVista->nombre ?? '—']) }}</th><th>{{ __('Alerta') }}</th>@if ($administra)<th>{{ __('Acción') }}</th>@endif</tr></thead>
                    <tbody>
                        @forelse ($productos as $producto)
                            @php($alerta = ListadoProductos::alerta((string) $producto->stock, (string) $producto->minimo))
                            <tr wire:key="producto-{{ $producto->id }}">
                                <td>
                                    <div class="item-name">
                                        <span class="token" style="overflow:hidden">
                                            @if ($producto->imagen_path)
                                                <img src="{{ route('productos.imagen', $producto) }}" alt="" loading="lazy" style="width:100%;height:100%;object-fit:cover">
                                            @else
                                                <x-icon name="store" size="sm" />
                                            @endif
                                        </span>
                                        <span><b>{{ $producto->nombre }}</b><small>{{ $producto->sku }}@unless ($producto->activo) {{ __('· Inactivo') }} @endunless</small></span>
                                    </div>
                                </td>
                                <td>{{ $producto->categoria->nombre ?? '—' }}</td>
                                <td><b>{{ Decimal::bs($producto->precio_consumidor) }}</b></td>
                                <td>{{ $producto->precio_minorista === null ? '—' : Decimal::bs($producto->precio_minorista) }}</td>
                                <td>{{ $producto->precio_mayorista === null ? '—' : Decimal::bs($producto->precio_mayorista) }}</td>
                                <td><b>{{ Decimal::formatear((string) $producto->stock) }}</b> {{ $producto->unidad->abreviatura }}</td>
                                <td>
                                    @if ($alerta === 'sin_stock')<span class="badge danger">{{ __('Sin stock') }}</span>
                                    @elseif ($alerta === 'bajo')<span class="badge danger">{{ __('Stock bajo') }}</span>
                                    @else<span class="badge info">{{ __('Normal') }}</span>@endif
                                </td>
                                @if ($administra)
                                    <td><a class="btn sm ghost" href="{{ route('productos.editar', $producto) }}"><x-icon name="edit" size="sm" /> {{ __('Editar') }}</a></td>
                                @endif
                            </tr>
                        @empty
                            <tr><td colspan="8"><div class="empty">{{ $buscar !== '' || $categoria !== '' ? __('No hay productos que coincidan con el filtro.') : __('Aún no hay productos registrados.') }}</div></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="table-footer">
                <span>{{ __('Los precios se guardan por producto. El stock y el mínimo pertenecen a cada sucursal.') }}</span>
                @if ($administra)<a class="btn sm ghost" href="{{ route('inventario.movimientos') }}">{{ __('Ver movimientos') }} <x-icon name="arrow" size="sm" /></a>@endif
            </div>
            @if ($productos->hasPages())
                <div class="table-footer">
                    <span>{{ __('Página :currentpage de :lastpage', ['currentpage' => $productos->currentPage(), 'lastpage' => $productos->lastPage()]) }}</span>
                    <span class="row">
                        @unless ($productos->onFirstPage())<button class="btn ghost sm" type="button" wire:click="previousPage">{{ __('Anterior') }}</button>@endunless
                        @if ($productos->hasMorePages())<button class="btn ghost sm" type="button" wire:click="nextPage">{{ __('Siguiente') }}</button>@endif
                    </span>
                </div>
            @endif
        @elseif ($pestana === 'categorias')
            @if ($administra)<button class="btn soft sm" type="button" wire:click="nuevaCategoria"><x-icon name="plus" size="sm" /> {{ __('Nueva categoría') }}</button><div style="height:13px"></div>@endif
            <div class="table-wrap">
                <table>
                    <thead><tr><th>{{ __('Categoría') }}</th><th>{{ __('Productos') }}</th><th>{{ __('Estado') }}</th>@if ($administra)<th>{{ __('Acción') }}</th>@endif</tr></thead>
                    <tbody>
                        @forelse ($categorias as $item)
                            <tr wire:key="categoria-{{ $item->id }}">
                                <td>{{ $item->nombre }}</td>
                                <td>{{ $item->productos_count }}</td>
                                <td><span class="badge {{ $item->activa ? 'success' : 'danger' }}">{{ $item->activa ? __('Activa') : __('Inactiva') }}</span></td>
                                @if ($administra)<td><button class="btn sm ghost" type="button" wire:click="editarCategoria({{ $item->id }})"><x-icon name="edit" size="sm" /> {{ __('Editar') }}</button></td>@endif
                            </tr>
                        @empty
                            <tr><td colspan="4"><div class="empty">{{ __('Aún no hay categorías.') }}</div></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @else
            @if ($administra)<button class="btn soft sm" type="button" wire:click="nuevaUnidad"><x-icon name="plus" size="sm" /> {{ __('Nueva unidad') }}</button><div style="height:13px"></div>@endif
            <div class="table-wrap">
                <table>
                    <thead><tr><th>{{ __('Nombre') }}</th><th>{{ __('Abreviatura') }}</th><th>{{ __('Cantidades') }}</th>@if ($empresaSiat)<th>{{ __('Unidad SIN') }}</th>@endif<th>{{ __('Estado') }}</th>@if ($administra)<th>{{ __('Acción') }}</th>@endif</tr></thead>
                    <tbody>
                        @foreach ($unidades as $item)
                            <tr wire:key="unidad-{{ $item->id }}">
                                <td>{{ $item->nombre }}</td>
                                <td>{{ $item->abreviatura }}</td>
                                <td>{{ $item->admite_decimales ? __('Admite decimales') : __('Solo enteras') }}</td>
                                @if ($empresaSiat)
                                    <td>
                                        @if ($item->unidad_medida_sin)
                                            <span class="badge info">{{ $item->unidad_medida_sin }}</span>
                                        @else
                                            <span class="subtle">{{ __('Sin asignar') }}</span>
                                        @endif
                                    </td>
                                @endif
                                <td><span class="badge {{ $item->activa ? 'success' : 'danger' }}">{{ $item->activa ? __('Activa') : __('Inactiva') }}</span></td>
                                @if ($administra)<td><button class="btn sm ghost" type="button" wire:click="editarUnidad({{ $item->id }})"><x-icon name="edit" size="sm" /> {{ __('Editar') }}</button></td>@endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    @if ($formCategoria)
        <x-modal :titulo="$categoriaId ? 'Editar categoría' : 'Nueva categoría'" cerrar="cerrarFormularios">
            <form wire:submit="guardarCategoria">
                <div class="fields" style="grid-template-columns:1fr">
                    <x-campo etiqueta="Nombre" campo="categoriaNombre">
                        <input id="categoriaNombre" class="input" type="text" maxlength="80" wire:model="categoriaNombre" required autofocus>
                    </x-campo>
                </div>
                @if ($categoriaId)
                    <label class="row" style="margin-top:16px"><input type="checkbox" wire:model="categoriaActiva"><span>{{ __('Categoría activa') }}</span></label>
                @endif
                <div class="modal-actions">
                    <button type="button" class="btn ghost" wire:click="cerrarFormularios">{{ __('Cancelar') }}</button>
                    <button class="btn primary" type="submit" wire:loading.attr="disabled"><x-icon name="check" /> {{ __('Guardar') }}</button>
                </div>
            </form>
        </x-modal>
    @endif

    @if ($formUnidad)
        <x-modal :titulo="$unidadId ? 'Editar unidad' : 'Nueva unidad'" descripcion="Define si las cantidades pueden tener decimales (kilogramos, litros) o solo enteros." cerrar="cerrarFormularios">
            <form wire:submit="guardarUnidad">
                <div class="fields">
                    <x-campo etiqueta="Nombre" campo="unidadNombre">
                        <input id="unidadNombre" class="input" type="text" maxlength="60" wire:model="unidadNombre" placeholder="{{ __('Ej. Paquete') }}" required autofocus>
                    </x-campo>
                    <x-campo etiqueta="Abreviatura" campo="unidadAbreviatura">
                        <input id="unidadAbreviatura" class="input" type="text" maxlength="15" wire:model="unidadAbreviatura" placeholder="{{ __('Ej. paq.') }}" required>
                    </x-campo>
                    @if ($empresaSiat)
                        <x-campo etiqueta="Unidad de medida SIN (SIAT)" campo="unidadMedidaSin">
                            <select id="unidadMedidaSin" class="select" wire:model="unidadMedidaSin">
                                <option value="">{{ __('Sin asignar (o selecciona del catálogo SIN)') }}</option>
                                @foreach ($unidadesSin as $opcion)
                                    <option value="{{ $opcion->codigo }}">{{ $opcion->codigo }} · {{ $opcion->descripcion }}</option>
                                @endforeach
                            </select>
                        </x-campo>
                    @endif
                </div>
                <label class="row" style="margin-top:16px"><input type="checkbox" wire:model="unidadDecimales"><span>{{ __('Admite cantidades con decimales') }}</span></label>
                @if ($unidadId)
                    <label class="row" style="margin-top:10px"><input type="checkbox" wire:model="unidadActiva"><span>{{ __('Unidad activa') }}</span></label>
                @endif
                <div class="modal-actions">
                    <button type="button" class="btn ghost" wire:click="cerrarFormularios">{{ __('Cancelar') }}</button>
                    <button class="btn primary" type="submit" wire:loading.attr="disabled"><x-icon name="check" /> {{ __('Guardar') }}</button>
                </div>
            </form>
        </x-modal>
    @endif

    <x-aviso :mensaje="$aviso" :numero="$avisoNumero" />
</div>
