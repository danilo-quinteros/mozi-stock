@use('App\Support\Decimal')
@use('App\Domain\Catalogo\Consultas\ListadoProductos')
<x-layouts::app title="{{ __('Inicio') }}">
    @if ($guiaPendiente)
        {{-- Mientras la guía no termina, el Inicio solo muestra la guía (onboarding.html). --}}
        <div class="page-head">
            <div><div class="eyebrow">{{ __('Empieza aquí') }}</div>
                <h1>{{ __('¡Bienvenido a MoziStock!') }}</h1>
                <p class="lead">{{ __('Tu negocio está casi listo. Completa estos pasos para comenzar a gestionar tus productos, ventas y cobros.') }}</p>
            </div>
        </div>
        <livewire:inicio.guia />
    @else
        @php
            $usuario = auth()->user();
            $esVendedor = $usuario->esVendedor();
            $zona = config('mozistock.business_timezone');
            $codigo = fn (int|string $numero) => 'V-'.str_pad((string) $numero, 5, '0', STR_PAD_LEFT);
        @endphp
        <div class="page-head">
            <div><div class="eyebrow">{{ $empresa->nombre }}</div>
                <h1>{{ __('Hola, :name', ['name' => \Illuminate\Support\Str::before(trim($usuario->name), ' ')]) }}<span class="accent-text">.</span></h1>
                <p class="lead">
                    @if ($esVendedor)
                        {{ __('Así va tu día en la sucursal') }} <b>{{ $panel['sucursal'] ?? __('sin asignar') }}</b>.
                    @elseif ($panel['sucursal'])
                        {{ __('Así va hoy la sucursal') }} <b>{{ $panel['sucursal'] }}</b>.
                    @else
                        {{ __('Así va hoy tu negocio en todas las sucursales.') }}
                    @endif
                </p>
            </div>
            <div class="page-actions">
                @if ($esVendedor)
                    <a class="btn ghost" href="{{ route('pedidos.index') }}"><x-icon name="receipt" /> {{ __('Pedidos y ventas') }}</a>
                @else
                    <a class="btn ghost" href="{{ route('reportes') }}"><x-icon name="chart" /> {{ __('Ver reportes') }}</a>
                @endif
                <a class="btn primary" href="{{ route('pos') }}"><x-icon name="plus" /> {{ __('Nueva venta') }}</a>
            </div>
        </div>

        @if ($suscripcion && $suscripcion->estado() !== \App\Enums\EstadoSuscripcion::AlDia)
            @php($dias = $suscripcion->diasHastaVencimiento())
            <div class="notice" style="margin-bottom:18px"><x-icon name="calendar" /><div>
                <b>{{ match (true) { $dias > 1 => "Tu suscripción vence en {$dias} días", $dias === 1 => __('Tu suscripción vence mañana'), $dias === 0 => __('Tu suscripción vence hoy'), default => __('Tu suscripción venció el ').$suscripcion->vencimiento->format('d/m/Y') } }}</b>
                <p>{{ $comprobanteEnRevision ? __('Tu comprobante está en revisión por MoziStudios.') : __('Paga con el QR de MoziStudios y sube el comprobante. El acceso no se suspende automáticamente.') }}</p>
                <a class="btn soft sm" style="margin-top:10px" href="{{ route('suscripcion') }}">{{ __('Ir a Mi suscripción') }} <x-icon name="arrow" size="sm" /></a>
            </div></div>
        @endif

        {{-- Lotes y vencimientos § 4 (L4): aviso solo con la capacidad y para quien ve el reporte. --}}
        @if (($panel['lotes'] ?? null) && ($panel['lotes']['vencidos'] > 0 || $panel['lotes']['proximos'] > 0))
            <div @class(['notice', 'caja-alerta' => $panel['lotes']['vencidos'] > 0]) style="margin-bottom:18px" data-test="aviso-vencimientos"><x-icon name="calendar" /><div>
                <b>{{ $panel['lotes']['vencidos'] > 0
                    ? trans_choice(':count lote vencido con stock|:count lotes vencidos con stock', $panel['lotes']['vencidos'])
                    : trans_choice(':count lote vence en los próximos 30 días|:count lotes vencen en los próximos 30 días', $panel['lotes']['proximos']) }}</b>
                <p>{{ $panel['lotes']['vencidos'] > 0
                    ? __('No se venden: dalos de baja en Ajustes → Salida → Vencido.').($panel['lotes']['proximos'] > 0 ? ' '.trans_choice('Además, :count lote vence en los próximos 30 días.|Además, :count lotes vencen en los próximos 30 días.', $panel['lotes']['proximos']) : '')
                    : __('Véndelos primero: las ventas ya descuentan del lote que vence antes.') }}</p>
                <a class="btn soft sm" style="margin-top:10px" href="{{ route('inventario.vencimientos', ['estado' => $panel['lotes']['vencidos'] > 0 ? 'vencidos' : '30']) }}">{{ __('Ver vencimientos') }} <x-icon name="arrow" size="sm" /></a>
            </div></div>
        @endif

        <div class="grid four inicio-kpis">
            @if ($esVendedor)
                <article class="card stat"><div class="row between"><span class="stat-label">{{ __('Tus ventas hoy') }}</span><x-icon name="cart" /></div><div class="stat-value">{{ Decimal::bs($panel['actividad']['vendido']) }}</div><div class="stat-foot">{{ trans_choice(':n venta · no es ingreso|:n ventas · no es ingreso', $panel['actividad']['ventas'], ['n' => $panel['actividad']['ventas']]) }}</div></article>
                <article class="card stat"><div class="row between"><span class="stat-label">{{ __('Cobrado por ti hoy') }}</span><x-icon name="wallet" /></div><div class="stat-value">{{ Decimal::bs($panel['actividad']['cobrado']) }}</div><div class="stat-foot"><a class="link" href="{{ route('caja') }}">{{ __('Ver mi caja') }} <x-icon name="arrow" size="sm" /></a></div></article>
                <article class="card stat"><div class="row between"><span class="stat-label">{{ __('Pedidos pendientes') }}</span><x-icon name="receipt" /></div><div class="stat-value">{{ $panel['pedidos'] }}</div><div class="stat-foot"><a class="link" href="{{ route('pedidos.index') }}">{{ __('En tu sucursal · ver pedidos') }} <x-icon name="arrow" size="sm" /></a></div></article>
            @else
                <article class="card stat"><div class="row between"><span class="stat-label">{{ __('Cobrado hoy') }}</span><x-icon name="wallet" /></div><div class="stat-value">{{ Decimal::bs($panel['cobros']['total']) }}</div><div class="stat-foot">{{ __('Efectivo :efectivo · Electrónico :electronico', ['efectivo' => Decimal::bs($panel['cobros']['efectivo']), 'electronico' => Decimal::bs($panel['cobros']['electronico'])]) }}</div></article>
                <article class="card stat"><div class="row between"><span class="stat-label">{{ __('Valor vendido hoy') }}</span><x-icon name="cart" /></div><div class="stat-value">{{ Decimal::bs($panel['ventas']['valor_vendido']) }}</div><div class="stat-foot">{{ $panel['ventas']['cantidad_devoluciones'] > 0
                    ? __(':n ventas · venta neta :neta', ['n' => $panel['ventas']['cantidad'], 'neta' => Decimal::bs($panel['ventas']['venta_neta'])])
                    : trans_choice(':n venta · no es ingreso|:n ventas · no es ingreso', $panel['ventas']['cantidad'], ['n' => $panel['ventas']['cantidad']]) }}</div></article>
                <article class="card stat"><div class="row between"><span class="stat-label">{{ __('Por cobrar a hoy') }}</span><x-icon name="credit" /></div><div class="stat-value">{{ Decimal::bs($panel['porCobrar']['pendiente']) }}</div><div class="stat-foot">
                    @if (Decimal::comparar($panel['porCobrar']['vencido'], '0', 2) > 0)
                        <a class="link danger-text" href="{{ route('creditos.index') }}">{{ __('Vencido :importe', ['importe' => Decimal::bs($panel['porCobrar']['vencido'])]) }} <x-icon name="arrow" size="sm" /></a>
                    @else
                        <a class="link" href="{{ route('creditos.index') }}">{{ trans_choice(':n cliente con saldo|:n clientes con saldo', $panel['porCobrar']['clientes'], ['n' => $panel['porCobrar']['clientes']]) }} <x-icon name="arrow" size="sm" /></a>
                    @endif
                </div></article>
            @endif
            <article class="card stat"><div class="row between"><span class="stat-label">{{ __('Stock bajo') }}</span><x-icon name="box" /></div><div @class(['stat-value', 'danger-text' => $panel['alertas'] > 0])>{{ $panel['alertas'] }}</div><div class="stat-foot">{{ $esVendedor ? __('Productos en o bajo su mínimo en tu sucursal') : __('Productos en o bajo su mínimo') }}</div></article>
        </div>

        <div class="wide-left inicio-columnas">
            <div class="stack">
                @php($totalSerie = array_reduce($panel['serie'], fn (string $suma, array $dia) => Decimal::sumar($suma, $dia['total'], 2), '0.00'))
                <section class="card card-pad" aria-labelledby="inicio-serie">
                    <div class="card-head inicio-serie-head">
                        <div>
                            <div class="heading" id="inicio-serie"><span class="heading-icon"><x-icon name="chart" /></span>{{ $esVendedor ? __('Tus ventas de los últimos 7 días') : __('Cobros de los últimos 7 días') }}</div>
                            <p class="subtle inicio-sub">{{ $esVendedor ? __('Valor vendido por día · actividad comercial, no es ingreso') : __('Dinero recibido por fecha real · efectivo + electrónico, abonos incluidos') }}</p>
                        </div>
                        <div class="right"><div class="callout-number">{{ Decimal::bs($totalSerie) }}</div><span class="subtle">{{ __('en 7 días') }}</span></div>
                    </div>
                    <div class="chart-space">
                        <div class="chartbars inicio-barras" role="list">
                            @foreach ($panel['serie'] as $dia)
                                @php($etiqueta = $loop->last ? __('Hoy') : \Illuminate\Support\Str::ucfirst(trim($dia['fecha']->locale(app()->getLocale())->isoFormat('ddd'), '.')))
                                @php($vacio = Decimal::comparar($dia['total'], '0', 2) === 0)
                                <div role="listitem" @class(['chartbar', 'hoy' => $loop->last, 'vacio' => $vacio]) data-label="{{ $etiqueta }}"
                                    title="{{ $dia['fecha']->format('d/m') }} · {{ Decimal::bs($dia['total']) }}" aria-label="{{ $etiqueta }} {{ $dia['fecha']->format('d/m') }}: {{ Decimal::bs($dia['total']) }}">
                                    <span style="height:{{ max(2, intdiv($dia['altura'] * 82, 100)) }}%" @unless ($vacio) data-valor="{{ Decimal::formatear($dia['total'], 0, 0) }}" @endunless></span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </section>

                <section class="card card-pad inicio-lista" aria-labelledby="inicio-ultimas">
                    <div class="card-head">
                        <div class="heading" id="inicio-ultimas"><span class="heading-icon"><x-icon name="history" /></span>{{ $esVendedor ? __('Tus últimas ventas') : __('Últimas ventas') }}</div>
                        <a class="link small" href="{{ route('pedidos.index') }}">{{ __('Ver todas') }} <x-icon name="arrow" size="sm" /></a>
                    </div>
                    @forelse ($panel['ultimas'] as $venta)
                        @php($fecha = \Illuminate\Support\Carbon::parse($venta->fecha, 'UTC')->timezone($zona))
                        <a class="list-line inicio-venta" href="{{ route('ventas.ver', $venta->id) }}">
                            <span class="grow">
                                <b>{{ $codigo($venta->numero) }} · {{ $venta->cliente ?? __('Consumidor final') }}</b>
                                <small>{{ $fecha->isToday() ? __('Hoy :hora', ['hora' => $fecha->format('H:i')]) : $fecha->format('d/m H:i') }}@unless ($esVendedor) · {{ $venta->vendedor }}@unless ($panel['sucursal']) · {{ $venta->sucursal }}@endunless @endunless</small>
                            </span>
                            <span class="right">
                                <b class="inicio-monto">{{ Decimal::bs((string) $venta->total) }}</b>
                                @if (Decimal::comparar((string) $venta->saldo_pendiente, '0', 2) > 0)
                                    <span class="badge warning">{{ __('Saldo :importe', ['importe' => Decimal::bs((string) $venta->saldo_pendiente)]) }}</span>
                                @elseif (Decimal::comparar((string) $venta->devuelto, '0', 2) > 0)
                                    <span class="badge danger">{{ __('Devuelto :importe', ['importe' => Decimal::bs((string) $venta->devuelto)]) }}</span>
                                @else
                                    <span class="badge success">{{ __('Pagada') }}</span>
                                @endif
                            </span>
                        </a>
                    @empty
                        <div class="empty">{{ $esVendedor ? __('Todavía no registraste ventas.') : __('Todavía no hay ventas confirmadas.') }}</div>
                    @endforelse
                </section>
            </div>

            <div class="stack">
                <section class="card card-pad inicio-lista" aria-labelledby="inicio-stock">
                    <div class="card-head">
                        <div class="heading" id="inicio-stock"><span class="heading-icon"><x-icon name="box" /></span>{{ __('Reponer pronto') }}</div>
                        @if ($panel['alertas'] > 0)<a class="link small" href="{{ $esVendedor ? route('productos.index') : route('reportes', ['pestana' => 'inventario', 'alertas' => 1]) }}">{{ __('Ver todo') }} <x-icon name="arrow" size="sm" /></a>@endif
                    </div>
                    @forelse ($panel['stockBajo'] as $producto)
                        @php($alerta = ListadoProductos::alerta((string) $producto->stock, (string) $producto->minimo))
                        <div class="list-line">
                            <span class="grow"><b class="inicio-nombre">{{ $producto->nombre }}</b><small>{{ __('Mínimo :minimo', ['minimo' => Decimal::formatear((string) $producto->minimo)]) }}@unless ($esVendedor || $panel['sucursal']) · {{ $producto->sucursal }}@endunless</small></span>
                            <span class="badge {{ $alerta === 'sin_stock' ? 'danger' : 'warning' }}">{{ $alerta === 'sin_stock' ? __('Sin stock') : __('Quedan :cantidad', ['cantidad' => Decimal::formatear((string) $producto->stock).' '.$producto->abreviatura]) }}</span>
                        </div>
                    @empty
                        <div class="inicio-ok"><x-icon name="check" /><div><b>{{ __('Todo en orden') }}</b><p>{{ __('Ningún producto está en o bajo su stock mínimo.') }}</p></div></div>
                    @endforelse
                </section>

                <section class="card card-pad" aria-labelledby="inicio-top">
                    <div class="card-head">
                        <div>
                            <div class="heading" id="inicio-top"><span class="heading-icon"><x-icon name="tag" /></span>{{ __('Más vendidos') }}</div>
                            <p class="subtle inicio-sub">{{ $esVendedor ? __('En tu sucursal · últimos 30 días, sin lo devuelto') : __('Últimos 30 días · por valor vendido, sin lo devuelto') }}</p>
                        </div>
                    </div>
                    @forelse ($panel['masVendidos'] as $producto)
                        <div class="inicio-top">
                            <div class="row between"><b class="inicio-nombre">{{ $producto['nombre'] }}</b><b class="inicio-monto">{{ Decimal::bs($producto['importe']) }}</b></div>
                            <div class="progress"><span style="width:{{ max(3, $producto['ancho']) }}%"></span></div>
                            <small>{{ __(':cantidad :unidad vendidos', ['cantidad' => Decimal::formatear($producto['cantidad']), 'unidad' => $producto['unidad']]) }}</small>
                        </div>
                    @empty
                        <div class="empty">{{ __('Sin ventas en los últimos 30 días.') }}</div>
                    @endforelse
                </section>
            </div>
        </div>
    @endif
</x-layouts::app>
