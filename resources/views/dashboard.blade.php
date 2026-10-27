<x-layouts::app title="{{ __('Inicio') }}">
    <div class="page-head">
        <div><div class="eyebrow">{{ $guiaPendiente ? __('Empieza aquí') : $empresa->nombre }}</div>
            <h1>@if ($guiaPendiente){{ __('¡Bienvenido a MoziStock!') }}@else{{ __('Hola, :name', ['name' => auth()->user()->name]) }}<span class="accent-text">.</span>@endif</h1>
            <p class="lead">
                @if ($guiaPendiente)
                    {{ __('Tu negocio está casi listo. Completa estos pasos para comenzar a gestionar tus productos, ventas y cobros.') }}
                @elseif (auth()->user()->esVendedor())
                    {{ __('Trabajas en la sucursal') }} <b>{{ $sucursal->nombre ?? __('sin asignar') }}</b>.
                @else
                    {{ __('Administras todas las sucursales de tu empresa.') }}
                @endif
            </p>
        </div>
    </div>

    @if ($guiaPendiente)
        <livewire:inicio.guia />
    @endif

    @if ($suscripcion && $suscripcion->estado() !== \App\Enums\EstadoSuscripcion::AlDia)
        @php($dias = $suscripcion->diasHastaVencimiento())
        <div class="notice" style="margin-bottom:18px"><x-icon name="calendar" /><div>
            <b>{{ match (true) { $dias > 1 => "Tu suscripción vence en {$dias} días", $dias === 1 => __('Tu suscripción vence mañana'), $dias === 0 => __('Tu suscripción vence hoy'), default => __('Tu suscripción venció el ').$suscripcion->vencimiento->format('d/m/Y') } }}</b>
            <p>{{ $comprobanteEnRevision ? __('Tu comprobante está en revisión por MoziStudios.') : __('Paga con el QR de MoziStudios y sube el comprobante. El acceso no se suspende automáticamente.') }}</p>
            <a class="btn soft sm" style="margin-top:10px" href="{{ route('suscripcion') }}">{{ __('Ir a Mi suscripción') }} <x-icon name="arrow" size="sm" /></a>
        </div></div>
    @endif

    @if ($alertasStock > 0)
        <div class="notice" style="margin-bottom:18px"><x-icon name="info" /><div><b>{{ $alertasStock }} {{ $alertasStock === 1 ? __('producto está') : __('productos están') }} en o bajo su stock mínimo</b><p>{{ auth()->user()->esVendedor() ? __('En tu sucursal.') : __('Sumando todas las sucursales activas.') }}</p><a class="btn soft sm" style="margin-top:10px" href="{{ route('productos.index') }}">{{ __('Ver productos') }} <x-icon name="arrow" size="sm" /></a></div></div>
    @endif

    @if ($resumen)
        <div class="grid two" style="margin-bottom:18px">
            <article class="card stat"><span class="stat-label">{{ __('Cobrado hoy') }}</span><div class="stat-value">{{ \App\Support\Decimal::bs($resumen['cobrado_hoy']) }}</div><div class="stat-foot"><a class="link" href="{{ route('caja') }}">{{ __('Dinero recibido · ver caja') }} <x-icon name="arrow" size="sm" /></a></div></article>
            <article class="card stat"><span class="stat-label">{{ __('Valor vendido hoy') }}</span><div class="stat-value">{{ \App\Support\Decimal::bs($resumen['vendido_hoy']) }}</div><div class="stat-foot">{{ __('Actividad comercial, no es ingreso') }}</div></article>
        </div>
        <div class="grid three" style="margin-bottom:18px">
            <article class="card stat">
                <span class="stat-label">{{ __('Sucursales activas') }}</span>
                <div class="stat-value">{{ $resumen['sucursales'] }}</div>
                <div class="stat-foot"><a class="link" href="{{ route('sucursales.index') }}">{{ __('Administrar sucursales') }} <x-icon name="arrow" size="sm" /></a></div>
            </article>
            <article class="card stat">
                <span class="stat-label">{{ __('Usuarios activos') }}</span>
                <div class="stat-value">{{ $resumen['usuarios'] }}</div>
                <div class="stat-foot"><a class="link" href="{{ route('usuarios.index') }}">{{ __('Administrar usuarios') }} <x-icon name="arrow" size="sm" /></a></div>
            </article>
            <article class="card stat">
                <span class="stat-label">{{ __('Vendedores') }}</span>
                <div class="stat-value">{{ $resumen['vendedores'] }}</div>
                <div class="stat-foot">{{ __('Cada uno con sucursal asignada') }}</div>
            </article>
        </div>
        @if ($resumen['sucursales'] === 0 && ! $guiaPendiente)
            <div class="notice" style="margin-bottom:18px"><x-icon name="info" /><div><b>{{ __('Registra tu primera sucursal') }}</b><p>{{ __('Las sucursales separan el inventario y la caja. Necesitas al menos una para asignar vendedores.') }}</p><a class="btn soft sm" style="margin-top:10px" href="{{ route('sucursales.index') }}"><x-icon name="plus" size="sm" /> {{ __('Nueva sucursal') }}</a></div></div>
        @endif
    @endif

    <section class="card card-pad foundation-state">
        <div class="card-head"><h2 class="heading">{{ __('Estado de tu espacio') }}</h2><span class="badge info">{{ __('Etapa 6') }}</span></div>
        <div class="list-line"><span>{{ __('Acceso y cuenta personal') }}</span><span class="badge success">{{ __('Disponible') }}</span></div>
        @if (auth()->user()->esVendedor())
            <div class="list-line"><span>{{ __('Sucursal de trabajo · :nombre', ['nombre' => $sucursal->nombre ?? __('sin asignar')]) }}</span><span class="badge info">{{ __('Asignada') }}</span></div>
        @else
            <div class="list-line"><span>{{ __('Empresa, sucursales y usuarios') }}</span><span class="badge success">{{ __('Disponible') }}</span></div>
        @endif
        <div class="list-line"><span>{{ auth()->user()->esVendedor() ? __('Consulta de productos y stock') : __('Productos, ajustes y traspasos') }}</span><span class="badge success">{{ __('Disponible') }}</span></div>
        <div class="list-line"><span>{{ __('Pedidos, ventas y créditos') }}</span><span class="badge success">{{ __('Disponible') }}</span></div>
        <div class="list-line"><span>{{ auth()->user()->esVendedor() ? __('Caja y reportes (administrador)') : __('Caja, arqueo y reportes') }}</span><span class="badge {{ auth()->user()->esVendedor() ? 'info' : 'success' }}">{{ auth()->user()->esVendedor() ? __('Restringido') : __('Disponible') }}</span></div>
        <div class="list-line"><span>{{ __('Clientes') }}</span><span class="badge success">{{ __('Disponible') }}</span></div>
        <div class="list-line"><span>{{ auth()->user()->esVendedor() ? __('Compras, egresos, proveedores y devoluciones (administrador)') : __('Compras, egresos, proveedores y devoluciones') }}</span><span class="badge {{ auth()->user()->esVendedor() ? 'info' : 'success' }}">{{ auth()->user()->esVendedor() ? __('Restringido') : __('Disponible') }}</span></div>
        @if (auth()->user()->esAdministrador() && config('mozistock.deployment_mode') === 'saas')
            <div class="list-line"><span>{{ __('Mi suscripción y pagos a MoziStudios') }}</span><span class="badge success">{{ __('Disponible') }}</span></div>
        @endif
    </section>
</x-layouts::app>
