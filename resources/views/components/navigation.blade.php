@php
    $usuario = auth()->user();
    $administra = $usuario->esAdministrador();
    $saas = config('mozistock.deployment_mode') === 'saas';
    // Indicador de vencimiento (§ 8.5: correo + panel); solo para administradores.
    $plan = $administra && $saas ? \App\Models\Suscripcion::query()->first(['id', 'empresa_id', 'vencimiento']) : null;
    $situacion = $plan?->estado();
    $alertaCuenta = $situacion === null || $situacion === \App\Enums\EstadoSuscripcion::AlDia ? null : $situacion;
    // Cada entrada lleva ruta, patrón activo, icono (App\Support\Iconos), visibilidad por rol y
    // la etiqueta corta del menú colapsado.
    $enlace = fn (string $etiqueta, string $ruta, string $patron, string $icono, bool $visible = true, ?string $corto = null) => compact('etiqueta', 'ruta', 'patron', 'icono', 'visible', 'corto');
    // Icono y etiqueta corta de cada grupo en el menú colapsado (sus enlaces se abren en un panel flotante).
    $iconosGrupo = [
        'Ventas y finanzas' => ['cart', 'Ventas'],
        'Inventario' => ['box', 'Inventario'],
        'Gestión' => ['users', 'Gestión'],
        'Reportes' => ['chart', 'Reportes'],
    ];
    $grupos = [
        'Ventas y finanzas' => [
            $enlace('Pedidos', 'pedidos.index', 'pedidos.*|ventas.*|pos', 'cart', corto: 'Pedidos'),
            
            $enlace('Mi caja', 'caja', 'caja', 'cash', $usuario->esVendedor(), 'Caja'),
            
            $enlace('Cuentas por cobrar', 'creditos.index', 'creditos.*', 'wallet', corto: 'Cobrar'),
            $enlace('Cuentas por pagar', 'cuentas-pagar.index', 'cuentas-pagar.*', 'receipt', $administra, 'Pagar'),
            $enlace('Egresos', 'egresos.index', 'egresos.*', 'expense', $administra),
            ],
        'Inventario' => [
            $enlace('Productos', 'productos.index', 'productos.*', 'box'),
            $enlace('Compras', 'compras.index', 'compras.*', 'truck', $administra),
            $enlace('Ajustes y traspasos', 'inventario.movimientos', 'inventario.movimientos|inventario.ajuste|inventario.traspaso', 'swap', $administra, 'Ajustes'),
            // Lotes y vencimientos § 4: dentro de Inventario, solo con la capacidad y para quien ve el historial.
            $enlace('Vencimientos', 'inventario.vencimientos', 'inventario.vencimientos|inventario.lote', 'calendar', $administra && \Illuminate\Support\Facades\Gate::allows('capacidad', \App\Enums\Capacidad::LotesVencimientos)),
        ],
        'Gestión' => [
            $enlace('Clientes', 'clientes.index', 'clientes.*', 'users'),
            $enlace('Proveedores', 'proveedores.index', 'proveedores.*', 'building', $administra),
            $enlace('Sucursales y cajas', 'sucursales.index', 'sucursales.*', 'store', $administra, 'Sucursales'),
            $enlace('Usuarios', 'usuarios.index', 'usuarios.*', 'shield', $administra),
        ],
        'Reportes' => [
            $enlace('Caja y arqueo', 'caja', 'caja|caja.*', 'cash', $administra, 'Caja'),
            $enlace('Reportes generales', 'reportes', 'reportes|reportes.*', 'chart', $administra, 'Reportes'),
        ],
    ];
    $agenteActivo = request()->routeIs('agente-mozi');
    $cuentaActiva = request()->routeIs('*.edit');
    $empresaActiva = request()->routeIs('configuracion', 'configuracion.*', 'suscripcion', 'suscripcion.*', 'facturacion');
@endphp
<nav class="nav-scroll nav-groups" aria-label="{{ __('Navegación principal') }}">
    <div class="nav-label">{{ __('Inicio') }}</div>
    <a class="nav-item {{ request()->routeIs('dashboard') ? 'active' : '' }}" href="{{ route('dashboard') }}" title="{{ __('Resumen') }}"><span class="nav-symbol"><x-icon name="dashboard" /></span><span class="nav-text">{{ __('Resumen') }}</span><span class="nav-short">{{ __('Inicio') }}</span></a>
    @can('usar-agente-mozi')
        <a class="nav-item nav-agente {{ $agenteActivo ? 'active' : '' }}" href="{{ route('agente-mozi') }}" title="{{ __('Agente Mozi') }}" @if ($agenteActivo) aria-current="page" @endif><span class="nav-symbol"><x-icon name="sparkles" /></span><span class="nav-text">{{ __('Agente Mozi') }}</span><span class="nav-short">{{ __('Mozi') }}</span></a>
    @endcan
    @if ($usuario->perteneceAEmpresa())
        @foreach ($grupos as $grupo => $items)
            @php
                $items = array_filter($items, fn ($item) => $item['visible']);
                $abierto = collect($items)->contains(fn ($item) => request()->routeIs(...explode('|', $item['patron'])));
            @endphp
            @continue($items === [])
            <details class="nav-group {{ $abierto ? 'has-active' : '' }}" @if ($abierto) open @endif>
                <summary title="{{ __($grupo) }}"><span class="nav-symbol nav-group-symbol"><x-icon :name="$iconosGrupo[$grupo][0]" /></span><span class="nav-group-title">{{ __($grupo) }}</span><span class="nav-short">{{ __($iconosGrupo[$grupo][1]) }}</span><span class="group-chevron"><x-icon name="chevron-down" size="sm" /></span></summary>
                <div class="nav-subitems">
                    <div class="nav-flyout-title">{{ __($grupo) }}</div>
                    @foreach ($items as $item)
                        @php($activo = request()->routeIs(...explode('|', $item['patron'])))
                        <a class="nav-item {{ $activo ? 'active' : '' }}" href="{{ route($item['ruta']) }}" title="{{ __($item['etiqueta']) }}" @if ($activo) aria-current="page" @endif><span class="nav-symbol"><x-icon :name="$item['icono']" /></span><span class="nav-text">{{ __($item['etiqueta']) }}</span><span class="nav-short">{{ __($item['corto'] ?? $item['etiqueta']) }}</span></a>
                    @endforeach
                </div>
            </details>
        @endforeach
    @endif
    <div class="nav-label">{{ __('Cuenta') }}</div>

    @if ($usuario->perteneceAEmpresa() && $administra)
        <a class="nav-item {{ $empresaActiva ? 'active' : '' }}" href="{{ route('configuracion') }}" title="{{ __('Mi empresa') }}" @if ($empresaActiva) aria-current="page" @endif><span class="nav-symbol"><x-icon name="store" /></span><span class="nav-text">{{ __('Mi empresa') }}</span><span class="nav-short">{{ __('Empresa') }}</span>@if ($alertaCuenta)<span class="badge {{ $alertaCuenta->insignia() }}" style="margin-left:auto">{{ $alertaCuenta->etiqueta() }}</span>@endif</a>
    @endif
    <a class="nav-item {{ $cuentaActiva ? 'active' : '' }}" href="{{ route('profile.edit') }}" title="{{ __('Mi cuenta') }}" @if ($cuentaActiva) aria-current="page" @endif><span class="nav-symbol"><x-icon name="user" /></span><span class="nav-text">{{ __('Mi cuenta') }}</span><span class="nav-short">{{ __('Cuenta') }}</span></a>

</nav>
