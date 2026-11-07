@use('App\Support\Decimal')
@use('App\Domain\Caja\Consultas\ReporteCaja')
@php($hora = fn ($f) => $f?->copy()->timezone($zona))
@php($corto = fn (string $v) => Decimal::formatear($v, 2, 2))
{{-- Mi caja: el vendedor ve solo su caja asignada, su turno, lo que él cobró y sus turnos. --}}
<div class="caja-reporte">
    <div class="page-head">
        <div>
            <div class="eyebrow">{{ $miCaja ? $miCaja->nombre.' · '.$miCaja->sucursal->nombre : __('Sin caja asignada') }}</div>
            <h1>{{ __('Mi caja') }}<span class="accent-text">.</span></h1>
            <p class="lead">{{ __('Lo que debe haber en tu cajón y lo que cobraste en este turno.') }}</p>
        </div>
        @if ($operable)
            <div class="page-actions">
                <button type="button" class="btn primary" wire:click="cerrarMiCaja"><x-icon name="lock" /> {{ __('Cerrar caja') }}</button>
            </div>
        @endif
    </div>

    @if (! $miCaja)
        <div class="notice caja-gap"><x-icon name="info" /><div><b>{{ __('No tienes cajas asignadas') }}</b><p>{{ __('Solicita al administrador que te asigne una.') }}</p></div></div>
    @elseif (! $turno)
        <div class="notice caja-gap"><x-icon name="lock" /><div><b>{{ __('Tu caja está cerrada') }}</b><p>{{ __('Ábrela con «Abrir caja» en la barra superior para empezar a vender y cobrar.') }}</p></div></div>
    @elseif (! $operable)
        <div class="notice caja-gap"><x-icon name="lock" /><div><b>{{ __('Tu caja tiene un turno individual de otra persona') }}</b><p>{{ __(':nombre la abrió a las :hora. Podrás usarla cuando cierre su turno.', ['nombre' => $turno->usuarioApertura->name, 'hora' => $hora($turno->fecha_apertura)->format('H:i')]) }}</p></div></div>
    @else
        <div class="caja-hero caja-gap">
            <section class="card card-pad">
                <div class="card-head">
                    <div class="heading"><span class="heading-icon"><x-icon name="wallet" /></span>{{ __('Turno #:id', ['id' => $turno->id]) }}</div>
                    <span class="badge {{ $turno->esDelDiaAnterior() ? 'warning' : 'success' }}"><span class="caja-dot"></span>{{ $turno->esDelDiaAnterior()
                        ? __('Abierta desde el :fecha', ['fecha' => $hora($turno->fecha_apertura)->format('d/m H:i')])
                        : __('Abierta desde :hora · :tiempo', ['hora' => $hora($turno->fecha_apertura)->format('H:i'), 'tiempo' => ReporteCaja::duracion($turno)]) }}</span>
                </div>
                <div class="caja-etiqueta">{{ __('Debe haber en el cajón') }}</div>
                <div class="caja-monto-hero">{{ Decimal::bs($esperado) }}</div>
                <div class="caja-formula">
                    <div class="list-line"><span>{{ __('Fondo inicial') }}</span><b>{{ Decimal::bs((string) $turno->monto_apertura) }}</b></div>
                    <div class="list-line"><span>{{ __('+ Cobros en efectivo') }}</span><b class="success-text">{{ Decimal::bs($totales['efectivo_ingreso']) }}</b></div>
                    <div class="list-line"><span>{{ __('− Salidas en efectivo') }}</span><b class="danger-text">{{ Decimal::bs($totales['efectivo_egreso']) }}</b></div>
                    <div class="list-line total"><b>{{ __('= Efectivo esperado') }}</b><strong class="callout-number">{{ Decimal::bs($esperado) }}</strong></div>
                </div>
                @php($companeros = collect([$turno->usuarioApertura])->merge($turno->participantes->pluck('usuario'))->filter()->unique('id')->reject(fn ($u) => $u->id === auth()->id()))
                @if ($miCaja->modalidad === 'compartida' && $companeros->isNotEmpty())
                    <div class="notice" style="margin-top:14px"><x-icon name="users" /><div><b>{{ __('Turno compartido con :nombres', ['nombres' => $companeros->pluck('name')->join(', ')]) }}</b><p>{{ __('El efectivo esperado es de todo el cajón. Tus cobros aparecen abajo con tu nombre.') }}</p></div></div>
                @endif
            </section>
            <div class="stack">
                <article class="card stat">
                    <div class="row between"><span class="stat-label">{{ __('Electrónico del turno') }}</span><x-icon name="qr" /></div>
                    <div class="stat-value">{{ Decimal::bs($totales['electronico_ingreso']) }}</div>
                    <div class="stat-foot">{{ __('QR y transferencias · no está en el cajón') }}</div>
                </article>
                <article class="card stat">
                    <div class="row between"><span class="stat-label">{{ __('Cobrado por mí en este turno') }}</span><x-icon name="check" /></div>
                    <div class="stat-value">{{ Decimal::bs($mios['ingresos']) }}</div>
                    <div class="caja-split"><span>{{ __('Efectivo') }} <b>{{ $corto($mios['efectivo_ingreso']) }}</b></span><span>{{ __('Electrónico') }} <b>{{ $corto($mios['electronico_ingreso']) }}</b></span><span>{{ __('Ventas') }} <b>{{ $mios['ventas'] }}</b></span></div>
                </article>
            </div>
        </div>

        <section class="card card-pad caja-gap">
            <div class="card-head"><div class="heading"><span class="heading-icon"><x-icon name="receipt" /></span>{{ __('Mis cobros de este turno') }}</div><span class="subtle">{{ __('Solo lo que tú recibiste') }}</span></div>
            <div class="table-wrap"><table>
                <thead><tr><th>{{ __('Hora') }}</th><th>{{ __('Movimiento') }}</th><th>{{ __('Cliente') }}</th><th class="num">{{ __('Efectivo') }}</th><th class="num">{{ __('Electrónico') }}</th><th class="num">{{ __('Total') }}</th></tr></thead>
                <tbody>
                    @forelse ($misMovimientos as $m)
                        @php($d = ReporteCaja::describir($m))
                        @php($sale = $m->sentido === 'egreso')
                        <tr wire:key="mio-{{ $m->origen_tipo }}-{{ $m->origen_id }}-{{ $m->sentido }}">
                            <td>{{ \Illuminate\Support\Carbon::parse($m->fecha, 'UTC')->timezone($zona)->format('H:i') }}</td>
                            <td><span class="caja-mov {{ $sale ? 'salida' : '' }}">{{ $d['concepto'] }} · {{ $d['referencia'] }}</span></td>
                            <td>{{ $d['detalle'] }}</td>
                            <td class="num">{{ Decimal::comparar((string) $m->efectivo, '0', 2) === 0 ? '—' : $corto((string) $m->efectivo) }}</td>
                            <td class="num">{{ Decimal::comparar((string) $m->electronico, '0', 2) === 0 ? '—' : $corto((string) $m->electronico) }}</td>
                            <td class="num"><b>{{ $sale ? '− ' : '' }}{{ $corto((string) $m->total) }}</b></td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="empty">{{ __('Todavía no cobraste nada en este turno.') }}</td></tr>
                    @endforelse
                </tbody>
            </table></div>
            {{ $misMovimientos->links(data: ['scrollTo' => false]) }}
        </section>
    @endif

    <section class="card card-pad">
        <div class="card-head"><div class="heading"><span class="heading-icon"><x-icon name="history" /></span>{{ __('Mis últimos turnos') }}</div><span class="subtle">{{ __('Cierres en los que participaste') }}</span></div>
        <div class="table-wrap"><table>
            <thead><tr><th>{{ __('Fecha') }}</th><th>{{ __('Caja') }}</th><th>{{ __('Horario') }}</th><th class="num">{{ __('Cobrado por mí') }}</th><th class="num">{{ __('Esperado') }}</th><th class="num">{{ __('Contado') }}</th><th>{{ __('Resultado') }}</th><th></th></tr></thead>
            <tbody>
                @forelse ($misTurnos as $t)
                    <tr wire:key="mi-turno-{{ $t->id }}">
                        <td>{{ $hora($t->fecha_apertura)->format('d/m/Y') }}</td>
                        <td>{{ $t->caja->nombre }}</td>
                        <td>{{ $hora($t->fecha_apertura)->format('H:i') }} → {{ $hora($t->fecha_cierre)->format($hora($t->fecha_cierre)->isSameDay($hora($t->fecha_apertura)) ? 'H:i' : 'd/m H:i') }}</td>
                        <td class="num">{{ $corto($cobradoPorMi[$t->id]['ingresos'] ?? '0.00') }}</td>
                        <td class="num">{{ $t->efectivo_esperado === null ? '—' : $corto((string) $t->efectivo_esperado) }}</td>
                        <td class="num">{{ $t->monto_cierre === null ? '—' : $corto((string) $t->monto_cierre) }}</td>
                        <td>@include('livewire.caja.partials.resultado', ['turno' => $t])</td>
                        <td><button type="button" class="btn ghost sm" wire:click="verDetalleTurno({{ $t->id }})">{{ __('Ver') }}</button></td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="empty">{{ __('Aún no tienes turnos cerrados.') }}</td></tr>
                @endforelse
            </tbody>
        </table></div>
        <div class="table-footer"><span>{{ __('Solo ves tus cobros y los turnos en los que trabajaste. El administrador revisa todos los cierres.') }}</span></div>
    </section>

    @include('livewire.caja.partials.detalle-turno')
</div>
