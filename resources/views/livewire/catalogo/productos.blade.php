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
                @if ($esRevendedoraCyclone)
                    <button class="btn ghost" type="button" wire:click="sincronizarCyclone" wire:loading.attr="disabled" title="{{ __('Sincronizar con el catálogo de Cyclone') }}">
                        <x-icon name="history" /> {{ __('Sincronizar con Cyclone') }}
                    </button>
                @endif
                <a class="btn ghost" href="{{ route('productos.importar') }}"><x-icon name="upload" /> {{ __('Importar') }}</a>
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
      @php
    $pestanas = [
        'productos' => 'Productos',
    ];

    if ($esRevendedoraCyclone) {
        $pestanas['cyclone'] = 'Catálogo Cyclone';
    }

    $pestanas['categorias'] = 'Categorías';
    $pestanas['unidades'] = 'Unidades y medidas';
@endphp
            @foreach ($pestanas as $clave => $etiqueta)
                <button type="button" role="tab" class="tab {{ $pestana === $clave ? 'active' : '' }}" aria-selected="{{ $pestana === $clave ? 'true' : 'false' }}" wire:click="$set('pestana', '{{ $clave }}')">{{ __($etiqueta) }}</button>
            @endforeach
        </div>

        @if ($pestana === 'productos')
            <div class="tool-row">
                <div class="search">
                    <x-icon name="search" />
                    <input class="input" type="search" wire:model.live.debounce.350ms="buscar" placeholder="{{ __('Buscar por nombre, marca, código o código de barras...') }}" aria-label="{{ __('Buscar productos') }}">
                </div>
                <select class="select" wire:model.live="categoria" aria-label="{{ __('Filtrar por categoría') }}">
                    <option value="">{{ __('Todas las categorías') }}</option>
                    @foreach ($categorias as $opcion)
                        <option value="{{ $opcion->id }}">{{ $opcion->nombre }}</option>
                    @endforeach
                </select>
                {{-- Filtro por marca: solo si la empresa ya usa marcas. --}}
                @if ($marcas->isNotEmpty())
                    <select class="select" wire:model.live="marca" aria-label="{{ __('Filtrar por marca') }}" data-test="filtro-marca">
                        <option value="">{{ __('Todas las marcas') }}</option>
                        @foreach ($marcas as $opcion)
                            <option value="{{ $opcion }}">{{ $opcion }}</option>
                        @endforeach
                    </select>
                @endif
                @if ($administra && $sucursales->count() > 1)
                    <select class="select" wire:model.live="sucursal" @disabled($sucursalFija) aria-label="{{ __('Ver stock de la sucursal') }}">
                        @foreach ($sucursales as $opcion)
                            <option value="{{ $opcion->id }}" @selected($sucursalVista?->id === $opcion->id)>{{ __('Stock · :nombre', ['nombre' => $opcion->nombre]) }}</option>
                        @endforeach
                    </select>
                @endif
                <select class="select" wire:model.live="filtroStock" aria-label="{{ __('Filtrar por stock') }}">
                    <option value="">{{ __('Stock') }}</option>
                    <option value="con_stock">{{ __('Con Stock') }}</option>
                    <option value="sin_stock">{{ __('Sin Stock') }}</option>
                </select>
                @if ($agrupado)
                    {{-- Tallas y colores § 5: modelos (con sus combinaciones), productos sueltos o todos. --}}
                    <select class="select" wire:model.live="tipo" aria-label="{{ __('Modelos o productos sueltos') }}" data-test="filtro-tipo">
                        <option value="">{{ __('Todos') }}</option>
                        <option value="modelos">{{ __('Modelos') }}</option>
                        <option value="sueltos">{{ __('Sueltos') }}</option>
                    </select>
                @endif
                <a class="btn ghost sm" href="{{ route('productos.exportar', array_filter(['q' => trim($buscar), 'categoria' => $categoria, 'sucursal' => $sucursalVista?->id, 'stock' => $filtroStock, 'tipo' => $agrupado ? $tipo : '', 'marca' => $marca])) }}"><x-icon name="download" size="sm" /> {{ __('Exportar CSV') }}</a>
            </div>
            @if ($sinLote > 0)
                {{-- Lotes § 9.2: acción masiva del administrador. --}}
                <div class="notice mini-note" style="margin-bottom:14px" data-test="marcar-lotes">
                    <x-icon name="info" />
                    <div>{{ $categoriaFiltro === null
                        ? trans_choice(':count producto con control de stock todavía no controla lote y vencimiento.|:count productos con control de stock todavía no controlan lote y vencimiento.', $sinLote)
                        : trans_choice(':count producto de :categoria todavía no controla lote y vencimiento.|:count productos de :categoria todavía no controlan lote y vencimiento.', $sinLote, ['categoria' => $categoriaFiltro]) }}
                        <button class="link" type="button" wire:click="$set('confirmandoLotes', true)">{{ __('Marcarlos con lote y vencimiento') }}</button></div>
                </div>
            @endif
            @if ($confirmandoLotes && $sinLote > 0)
                <x-modal titulo="Marcar con lote y vencimiento" descripcion="El stock actual de cada sucursal pasa a «Sin lote (regularización)». El total no cambia y se puede vender; después regulariza cada lote desde Vencimientos → Sin fecha." cerrar="$set('confirmandoLotes', false)">
                    <p>{{ $categoriaFiltro === null
                        ? trans_choice('Se marcará :count producto.|Se marcarán :count productos.', $sinLote)
                        : trans_choice('Se marcará :count producto de :categoria.|Se marcarán :count productos de :categoria.', $sinLote, ['categoria' => $categoriaFiltro]) }}</p>
                    <div class="modal-actions">
                        <button type="button" class="btn ghost" wire:click="$set('confirmandoLotes', false)">{{ __('Cancelar') }}</button>
                        <button type="button" class="btn primary" wire:click="marcarConLote" wire:loading.attr="disabled" wire:target="marcarConLote"><x-icon name="check" /> {{ __('Marcar') }}</button>
                    </div>
                </x-modal>
            @endif
            <div x-data="{ agrupar: [] }">
            @if ($conVariantes)
                {{-- Tallas y colores § 9: los productos sueltos marcados se agrupan en un modelo (página completa). --}}
                <div class="agrupar-barra" data-test="barra-agrupar">
                    <span class="subtle" x-show="agrupar.length === 0">{{ __('Marca productos sueltos para agruparlos en un modelo con tallas y colores.') }}</span>
                    <b x-show="agrupar.length > 0" x-cloak x-text="agrupar.length === 1 ? @js(__('1 producto marcado')) : @js(__(':count productos marcados')).replace(':count', agrupar.length)"></b>
                    <a class="btn primary sm" x-show="agrupar.length > 0" x-cloak :href="@js(route('productos.modelos.agrupar')) + '?productos=' + agrupar.join(',')" data-test="agrupar-en-modelo"><x-icon name="tag" size="sm" /> {{ __('Agrupar en modelo') }}</a>
                    <button class="btn ghost sm" type="button" x-show="agrupar.length > 0" x-cloak @click="agrupar = []">{{ __('Desmarcar') }}</button>
                </div>
            @endif
            <div class="table-wrap">
                <table class="catalogo-tabla" x-data="{ abiertos: {} }">
                    <thead><tr><th>{{ __('Producto / código') }}</th><th>{{ __('Categoría') }}</th><th>{{ __('Consumidor') }}</th><th>{{ __('Minorista') }}</th><th>{{ __('Mayorista') }}</th><th>{{ __('Stock · :nombre', ['nombre' => $sucursalVista->nombre ?? '—']) }}</th><th>{{ __('Alerta') }}</th>@if ($administra)<th>{{ __('Acción') }}</th>@endif</tr></thead>
                    <tbody>
                        @forelse ($productos as $producto)
                            @if ($agrupado && $producto->tipo === 'modelo')
                                @continue(! isset($modelos[$producto->entrada_id]))
                                @include('livewire.catalogo.partials.fila-modelo', ['modelo' => $modelos[$producto->entrada_id], 'variantes' => $variantes[$producto->entrada_id] ?? collect(), 'resumen' => $resumenModelos[$producto->entrada_id] ?? null])
                            @else
                                @continue($agrupado && ! isset($sueltos[$producto->entrada_id]))
                                @include('livewire.catalogo.partials.fila-producto', ['producto' => $agrupado ? $sueltos[$producto->entrada_id] : $producto])
                            @endif
                        @empty
                            <tr>
                                <td colspan="8">
                                    @if ($esRevendedoraCyclone && $buscar === '' && $categoria === '')
                                        <div class="empty" style="padding:48px 16px;text-align:center">
                                            <div style="font-size:36px;margin-bottom:12px">📦</div>
                                            <h3 style="margin-bottom:6px">{{ __('Aún no tienes productos en tu catálogo') }}</h3>
                                            <p class="subtle" style="max-width:440px;margin:0 auto 20px auto">
                                                {{ __('Tu empresa está vinculada a Cyclone. Puedes elegir qué productos vender desde el catálogo central o registrar tus propios productos.') }}
                                            </p>
                                            <div style="display:flex;gap:12px;justify-content:center;flex-wrap:wrap">
                                                <button type="button" class="btn primary" wire:click="$set('pestana', 'cyclone')">
                                                    <x-icon name="store" /> {{ __('Cargar desde Cyclone') }}
                                                </button>
                                                @if ($administra)
                                                    <a class="btn ghost" href="{{ route('productos.crear') }}">
                                                        <x-icon name="plus" /> {{ __('Nuevo producto manual') }}
                                                    </a>
                                                @endif
                                            </div>
                                        </div>
                                    @else
                                        <div class="empty">{{ $buscar !== '' || $categoria !== '' || $filtroStock !== '' || $tipo !== '' ? __('No hay productos que coincidan con el filtro.') : __('Aún no hay productos registrados.') }}</div>
                                    @endif
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
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
        @endif

        @if ($pestana === 'categorias')
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
        @endif

        @if ($pestana === 'unidades')
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

        @if ($pestana === 'cyclone' && $esRevendedoraCyclone)
            <div class="tool-row" style="margin-bottom:16px">
                <div class="search">
                    <x-icon name="search" />
                    <input class="input" type="search" wire:model.live.debounce.350ms="buscarCyclone" placeholder="{{ __('Buscar en catálogo Cyclone por nombre, código o línea...') }}" aria-label="{{ __('Buscar en catálogo Cyclone') }}">
                </div>
                <select class="select" wire:model.live="lineaCyclone" aria-label="{{ __('Filtrar por línea o categoría') }}">
                    <option value="">{{ __('Todas las líneas') }}</option>
                    @foreach ($lineasCyclone as $opcionLinea)
                        <option value="{{ $opcionLinea }}">{{ $opcionLinea }}</option>
                    @endforeach
                </select>
                <select class="select" wire:model.live="estadoCyclone" aria-label="{{ __('Filtrar por estado en catálogo') }}">
                    <option value="todos">{{ __('Todos los productos') }}</option>
                    <option value="en_catalogo">{{ __('En mi catálogo') }}</option>
                    <option value="disponibles">{{ __('Disponibles para agregar') }}</option>
                </select>
            </div>

            @if (count($seleccionadosCyclone) > 0)
                <div class="bulk-bar" style="display:flex;align-items:center;gap:12px;background:#241f1b;border:1px solid #3d342c;border-radius:12px;padding:10px 18px;margin-bottom:18px">
                    <div style="display:flex;align-items:center;gap:10px">
                        <span style="background:#3b2314;border:1px solid #78350f;color:#f59e0b;font-weight:800;font-size:13px;padding:2px 10px;border-radius:6px">
                            {{ count($seleccionadosCyclone) }}
                        </span>
                        <span style="font-weight:700;color:var(--text, #fff);font-size:13px">
                            {{ __('filas marcadas') }}
                        </span>
                    </div>
                    @if ($administra)
                        <button type="button" class="btn sm" style="background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.14);color:#f3f4f6;font-size:13px;font-weight:600;padding:6px 14px;border-radius:8px;cursor:pointer" wire:click="agregarMarcados" wire:loading.attr="disabled">
                            <span style="margin-right:4px">+</span> {{ __('Agregar marcados') }}
                        </button>
                        <button type="button" class="btn sm" style="background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.14);color:#f3f4f6;font-size:13px;font-weight:600;padding:6px 14px;border-radius:8px;cursor:pointer" wire:click="solicitarDejarVenderMarcados" wire:loading.attr="disabled">
                            <span style="margin-right:4px">−</span> {{ __('Dejar de vender') }}
                        </button>
                    @endif
                    <button type="button" style="margin-left:auto;background:none;border:none;color:#9ca3af;font-size:13px;cursor:pointer;padding:4px 8px" wire:click="desmarcarCyclone">
                        {{ __('Desmarcar') }}
                    </button>
                </div>
            @endif

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th style="width:40px">
                                @php
                                    $idsPagina = $productosCyclone->pluck('id')->map(fn ($id) => (string) $id)->all();
                                    $todosPaginaSeleccionados = count($idsPagina) > 0 && count(array_intersect($idsPagina, array_map('strval', $seleccionadosCyclone))) === count($idsPagina);
                                @endphp
                                <input type="checkbox" style="accent-color:var(--brand, #ea580c);width:17px;height:17px;cursor:pointer" @checked($todosPaginaSeleccionados) wire:click="toggleSeleccionarTodos({{ json_encode($idsPagina) }})" aria-label="{{ __('Seleccionar todos') }}">
                            </th>
                            <th>{{ __('PRODUCTO / CÓDIGO') }}</th>
                            <th>{{ __('CATEGORÍA') }}</th>
                            <th>{{ __('PRESENTACIÓN') }}</th>
                            <th>{{ __('VENTA') }}</th>
                            <th>{{ __('ESTADO') }}</th>
                            @if ($administra)<th style="text-align:right">{{ __('ACCIÓN') }}</th>@endif
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($productosCyclone as $item)
                            <tr wire:key="cyclone-{{ $item->id }}">
                                <td>
                                    <input type="checkbox" value="{{ $item->id }}" wire:model.live="seleccionadosCyclone" style="accent-color:var(--brand, #ea580c);width:17px;height:17px;cursor:pointer">
                                </td>
                                <td>
                                    <div class="item-name">
                                        <span class="token" style="overflow:hidden;border-radius:10px;width:38px;height:38px;background:var(--panel-soft);display:flex;align-items:center;justify-content:center;position:relative">
                                            @if ($item->foto_url || $item->foto_cache_path)
                                                <img src="{{ route('catalogo-externo.foto', $item) }}" alt="" loading="lazy" style="width:100%;height:100%;object-fit:cover" onerror="this.style.display='none';if(this.nextElementSibling)this.nextElementSibling.style.display='inline-flex'">
                                                <span style="display:none;align-items:center;justify-content:center"><x-icon name="store" size="sm" /></span>
                                            @else
                                                <x-icon name="store" size="sm" />
                                            @endif
                                        </span>
                                        <span>
                                            <b style="color:var(--text, #fff);font-size:13px">{{ $item->nombre_limpio }}</b>
                                            <small class="subtle">{{ $item->codigo ? $item->codigo . ' · Cyclone' : 'Cyclone' }}</small>
                                        </span>
                                    </div>
                                </td>
                                <td>{{ $item->linea_nombre ?: '—' }}</td>
                                <td>
                                    <b>
                                        @if ($item->contenido !== null)
                                            {{ \App\Support\Decimal::formatear((string) $item->contenido) }} {{ $item->unidad_sugerida }}
                                        @else
                                            {{ $item->unidad_sugerida ?: '—' }}
                                        @endif
                                    </b>
                                </td>
                                <td>{{ $item->tipo_venta === 'medida' ? __('Por peso o medida') : __('Por unidad') }}</td>
                                <td>
                                    @if ($item->en_catalogo)
                                        <span class="badge success" style="background:rgba(16,185,129,0.12);border:1px solid rgba(16,185,129,0.28);color:#34d399;font-weight:700;font-size:12px;padding:4px 12px;border-radius:999px;display:inline-flex;align-items:center;gap:6px">
                                            <span style="font-size:8px">●</span> {{ __('En mi catálogo') }}
                                        </span>
                                    @else
                                        <span class="badge subtle" style="background:rgba(255,255,255,0.05);border:1px solid rgba(255,255,255,0.1);color:#9ca3af;font-weight:600;font-size:12px;padding:4px 12px;border-radius:999px">
                                            {{ __('Disponible') }}
                                        </span>
                                    @endif
                                </td>
                                @if ($administra)
                                    <td style="text-align:right">
                                        @if ($item->en_catalogo)
                                            <button type="button" class="btn sm ghost" style="background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.1);color:#e5e7eb;font-weight:600;border-radius:8px;padding:6px 14px" wire:click="solicitarDejarVender({{ $item->id }})" wire:loading.attr="disabled">
                                                {{ __('Dejar de vender') }}
                                            </button>
                                        @else
                                            <button type="button" class="btn sm soft" style="font-weight:600;border-radius:8px;padding:6px 14px" wire:click="agregarProducto({{ $item->id }})" wire:loading.attr="disabled">
                                                {{ __('Agregar') }}
                                            </button>
                                        @endif
                                    </td>
                                @endif
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7">
                                    <div class="empty">{{ __('No se encontraron productos en el catálogo de Cyclone.') }}</div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($productosCyclone instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator && $productosCyclone->hasPages())
                <div class="table-footer">
                    <span>{{ __('Página :currentpage de :lastpage', ['currentpage' => $productosCyclone->currentPage(), 'lastpage' => $productosCyclone->lastPage()]) }}</span>
                    <span class="row">
                        @unless ($productosCyclone->onFirstPage())
                            <button class="btn ghost sm" type="button" wire:click="previousPage('cyclonePage')">{{ __('Anterior') }}</button>
                        @endunless
                        @if ($productosCyclone->hasMorePages())
                            <button class="btn ghost sm" type="button" wire:click="nextPage('cyclonePage')">{{ __('Siguiente') }}</button>
                        @endif
                    </span>
                </div>
            @endif
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

    @if ($confirmandoDejarVenderId && $alertaStockIndividual)
        <x-modal titulo="Atención: Producto con inventario" cerrar="cancelarDejarVender">
            <div style="margin-bottom:16px">
                <div style="margin-bottom:14px;background:#382414;border:1px solid #78350f;color:#fcd34d;padding:12px 16px;border-radius:8px;font-size:13px">
                    <b>⚠️ {{ __('Este producto tiene existencias registradas en inventario.') }}</b>
                </div>
                <p style="margin-bottom:12px;font-size:13px">
                    {{ __('El producto') }} <b>{{ $alertaStockIndividual['nombre'] }}</b> {{ __('tiene un stock total de') }}
                    <b style="color:var(--brand, #f59e0b)">{{ \App\Support\Decimal::formatear((string) $alertaStockIndividual['stock_total']) }} {{ $alertaStockIndividual['unidad'] }}</b>:
                </p>
                <ul style="margin:0 0 16px 20px;padding:0;font-size:13px;line-height:1.7">
                    @foreach ($alertaStockIndividual['sucursales'] as $sucursal => $cantidad)
                        <li><b>{{ $sucursal }}:</b> {{ \App\Support\Decimal::formatear((string) $cantidad) }} {{ $alertaStockIndividual['unidad'] }}</li>
                    @endforeach
                </ul>
                <p class="subtle" style="font-size:12px;line-height:1.6">
                    {{ __('Al dejar de venderlo, el producto se retirará de tu catálogo activo para que no se ofrezca en nuevas ventas ni pedidos, pero su inventario y el historial de movimientos se conservarán de forma segura.') }}
                </p>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn ghost" wire:click="cancelarDejarVender">{{ __('Cancelar') }}</button>
                <button type="button" class="btn danger" wire:click="confirmarDejarVender" wire:loading.attr="disabled">
                    {{ __('Sí, dejar de vender') }}
                </button>
            </div>
        </x-modal>
    @endif

    @if ($confirmandoDejarVenderBulk && !empty($alertaStockBulk))
        <x-modal titulo="Atención: Productos con inventario" cerrar="cancelarDejarVenderBulk">
            <div style="margin-bottom:16px">
                <div style="margin-bottom:14px;background:#382414;border:1px solid #78350f;color:#fcd34d;padding:12px 16px;border-radius:8px;font-size:13px">
                    <b>⚠️ {{ __(':count productos seleccionados tienen existencias registradas en inventario.', ['count' => count($alertaStockBulk)]) }}</b>
                </div>
                <div class="table-wrap" style="max-height:220px;overflow-y:auto;margin-bottom:14px">
                    <table>
                        <thead>
                            <tr>
                                <th>{{ __('Producto') }}</th>
                                <th>{{ __('Stock total') }}</th>
                                <th>{{ __('Sucursales') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($alertaStockBulk as $prod)
                                <tr>
                                    <td><b>{{ $prod['nombre'] }}</b></td>
                                    <td><b>{{ \App\Support\Decimal::formatear((string) $prod['stock_total']) }} {{ $prod['unidad'] }}</b></td>
                                    <td>
                                        <small class="subtle">
                                            @foreach ($prod['sucursales'] as $suc => $cant)
                                                {{ $suc }}: {{ \App\Support\Decimal::formatear((string) $cant) }}@if (!$loop->last), @endif
                                            @endforeach
                                        </small>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <p class="subtle" style="font-size:12px;line-height:1.6">
                    {{ __('Los productos con existencias o historial serán desactivados para no venderse en POS ni pedidos, conservando sus existencias. Los productos sin movimientos se eliminarán completamente de tu catálogo.') }}
                </p>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn ghost" wire:click="cancelarDejarVenderBulk">{{ __('Cancelar') }}</button>
                <button type="button" class="btn danger" wire:click="confirmarDejarVenderBulk" wire:loading.attr="disabled">
                    {{ __('Confirmar y procesar') }}
                </button>
            </div>
        </x-modal>
    @endif

    <x-aviso :mensaje="$aviso" :numero="$avisoNumero" />
</div>
