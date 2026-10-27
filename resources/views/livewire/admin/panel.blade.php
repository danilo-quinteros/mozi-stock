@use('App\Domain\Suscripciones\CalendarioSuscripcion')
@use('App\Support\Decimal')
<div>
    <div class="page-head">
        <div>
            <div class="eyebrow">{{ __('Superadministración') }}</div>
            <h1>{{ __('Panel general') }}<span class="accent-text">.</span></h1>
            <p class="lead">{{ __('Gestiona empresas y suscripciones desde MoziStudios.') }}</p>
        </div>
        <div class="page-actions">
            <a class="btn soft" href="{{ route('admin.empresas') }}"><x-icon name="store" /> {{ __('Administrar empresas') }}</a>
            <a class="btn primary" href="{{ route('admin.comprobantes', ['estado' => 'pendiente']) }}"><x-icon name="receipt" /> {{ __('Revisar pagos') }}</a>
        </div>
    </div>

    <div class="grid four" style="margin-bottom:18px">
        <article class="card stat">
            <span class="stat-label">{{ __('Empresas registradas') }}</span>
            <div class="stat-value">{{ (int) $conteo->registradas }}</div>
            <div class="stat-foot">{{ __('Clientes SaaS') }}</div>
        </article>
        <article class="card stat">
            <span class="stat-label">{{ __('Empresas activas') }}</span>
            <div class="stat-value">{{ (int) $conteo->activas }}</div>
            <div class="stat-foot">{{ __('Activación exclusivamente manual') }}</div>
        </article>
        <article class="card stat">
            <span class="stat-label">{{ __('Comprobantes pendientes') }}</span>
            <div class="stat-value">{{ $suscripciones['en_revision'] }}</div>
            <div class="stat-foot">{{ __('Revisión humana necesaria') }}</div>
        </article>
        <article class="card stat">
            <span class="stat-label">{{ __('Vencimientos cercanos') }}</span>
            <div class="stat-value">{{ $suscripciones['por_vencer'] }}</div>
            <div class="stat-foot">{{ __('Hoy o en los próximos 5 días · :suscripciones vencidas', ['suscripciones' => $suscripciones['vencidas']]) }}</div>
        </article>
    </div>

    <div class="wide-left">
        <section class="card card-pad">
            <div class="card-head"><div class="heading"><span class="heading-icon"><x-icon name="calendar" /></span>{{ __('Actividad de empresas') }}</div></div>
            @if ($proximas->isEmpty())
                <div class="empty">{{ __('Todavía no hay empresas registradas.') }}</div>
            @else
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>{{ __('Empresa') }}</th><th>{{ __('Plan') }}</th><th>{{ __('Vence') }}</th><th>{{ __('Acceso') }}</th><th>{{ __('Revisión') }}</th></tr></thead>
                        <tbody>
                            @foreach ($proximas as $fila)
                                @php($vence = $fila->vencimiento === null ? null : CalendarioSuscripcion::fecha((string) $fila->vencimiento))
                                <tr wire:key="proxima-{{ $fila->id }}">
                                    <td><b>{{ $fila->nombre }}</b></td>
                                    @if ($vence)
                                        <td>{{ Decimal::bs((string) $fila->precio_mensual) }}</td>
                                        <td>{{ $vence->format('d/m/Y') }}</td>
                                    @else
                                        <td colspan="2"><span class="badge info">{{ __('Sin plan configurado') }}</span></td>
                                    @endif
                                    <td><span class="badge {{ $fila->activa ? 'success' : 'danger' }}" style="white-space:nowrap">{{ $fila->activa ? __('Activa') : __('Inactiva') }}</span></td>
                                    <td>
                                        @if ((int) $fila->en_revision > 0)
                                            <span class="badge warning">{{ __('Comprobante en revisión') }}</span>
                                        @elseif ($vence)
                                            @php($situacion = CalendarioSuscripcion::estado($vence, $hoy))
                                            <span class="badge {{ $situacion->insignia() }}" style="white-space:nowrap">{{ $situacion->etiqueta() }}</span>
                                        @else
                                            —
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
        <section class="card card-pad">
            <div class="card-head"><div class="heading"><span class="heading-icon"><x-icon name="info" /></span>{{ __('Próximas tareas') }}</div></div>
            <div class="list-line">
                <div><b>{{ __('Revisar comprobantes') }}</b><p class="subtle" style="margin:5px 0">{{ $suscripciones['en_revision'] }} {{ $suscripciones['en_revision'] === 1 ? 'pendiente' : 'pendientes' }}</p></div>
                <a class="btn ghost sm" href="{{ route('admin.comprobantes', ['estado' => 'pendiente']) }}">{{ __('Abrir') }} <x-icon name="arrow" size="sm" /></a>
            </div>
            <div class="list-line">
                <div><b>{{ __('Empresas por vencer') }}</b><p class="subtle" style="margin:5px 0">{{ __('Avisos D-5, D0 y D+3') }}</p></div>
                <a class="btn ghost sm" href="{{ route('admin.suscripciones', ['estado' => 'por_vencer']) }}">{{ __('Abrir') }} <x-icon name="arrow" size="sm" /></a>
            </div>
            @if ($sinPlan > 0)
                <div class="list-line">
                    <div><b>{{ __('Empresas sin plan') }}</b><p class="subtle" style="margin:5px 0">{{ __(':sinplan sin precio ni vencimiento', ['sinplan' => $sinPlan]) }}</p></div>
                    <a class="btn ghost sm" href="{{ route('admin.suscripciones', ['estado' => 'sin_plan']) }}">{{ __('Abrir') }} <x-icon name="arrow" size="sm" /></a>
                </div>
            @endif
            <div class="list-line">
                <div><b>{{ __('Permisos SIAT') }}</b><p class="subtle" style="margin:5px 0">{{ __('Integración futura') }}</p></div>
                <span class="badge info">{{ __('No disponible') }}</span>
            </div>
        </section>
    </div>
</div>
