@use('App\Support\Decimal')
@use('App\Domain\Caja\Consultas\ReporteCaja')
@php($hora = fn ($f) => $f?->copy()->timezone($zona))
@php($firmado = fn (string $v) => match (Decimal::comparar($v, '0', 2)) { 1 => '+ '.Decimal::bs($v), -1 => '− '.Decimal::bs(ltrim($v, '-')), default => Decimal::bs($v) })
@php($corto = fn (string $v) => Decimal::formatear($v, 2, 2))
@php($hoy = \App\Domain\Reportes\Periodo::hoy()->desde->format('Y-m-d'))
@php($filtros = array_filter(['fecha' => $periodoActual->hasta->format('Y-m-d'), 'desde' => $periodoActual->esUnDia() ? null : $periodoActual->desde->format('Y-m-d'), 'sucursal' => $sucursal, 'caja' => $caja]))
<div class="caja-reporte">
    <div class="page-head">
        <div>
            <div class="eyebrow">{{ __('Dinero real · Administración') }}</div>
            <h1>{{ __('Caja y arqueo') }}<span class="accent-text">.</span></h1>
            <p class="lead">{{ __('Cuánto entró, cuánto salió y cuánto debe haber en cada caja.') }}</p>
        </div>
        <div class="page-actions">
            <a class="btn ghost" href="{{ route('caja.imprimir', $filtros) }}" target="_blank"><x-icon name="print" /> {{ __('Imprimir') }}</a>
            <a class="btn soft" href="{{ route('caja.exportar', $filtros) }}"><x-icon name="download" /> {{ __('Exportar') }}</a>
        </div>
    </div>

    {{-- Filtros en una sola fila --}}
    <section class="card caja-filtros">
        <div class="caja-chips" role="group" aria-label="{{ __('Período') }}">
            <button type="button" class="caja-chip {{ $periodo === '' && $fecha === $hoy ? 'active' : '' }}" wire:click="elegirPeriodo('hoy')">{{ __('Hoy') }}</button>
            <button type="button" class="caja-chip {{ $periodo === '' && $fecha === \App\Domain\Reportes\Periodo::hoy()->desde->subDay()->format('Y-m-d') ? 'active' : '' }}" wire:click="elegirPeriodo('ayer')">{{ __('Ayer') }}</button>
            <button type="button" class="caja-chip {{ $periodo === '7d' ? 'active' : '' }}" wire:click="elegirPeriodo('7d')">{{ __('7 días') }}</button>
            <button type="button" class="caja-chip {{ $periodo === 'mes' ? 'active' : '' }}" wire:click="elegirPeriodo('mes')">{{ __('Este mes') }}</button>
            <input class="input" type="date" aria-label="{{ __('Elegir fecha') }}" wire:model.live="fecha" max="{{ $hoy }}">
        </div>
        <div class="caja-selects">
            <select class="select" aria-label="{{ __('Sucursal') }}" wire:model.live="sucursal" @disabled($sucursalFija)>
                <option value="">{{ __('Todas las sucursales') }}</option>
                @foreach ($sucursales as $s)
                    <option value="{{ $s->id }}">{{ $s->nombre }}</option>
                @endforeach
            </select>
            <select class="select" aria-label="{{ __('Caja') }}" wire:model.live="caja">
                <option value="">{{ __('Todas las cajas') }}</option>
                @foreach ($cajas as $c)
                    <option value="{{ $c->id }}">{{ $c->nombre }}{{ $sucursal === '' ? ' · '.$c->sucursal->nombre : '' }}</option>
                @endforeach
                <option value="externo">{{ __('Fuera de caja') }}</option>
            </select>
        </div>
    </section>

    {{-- Avisos que piden acción --}}
    @if ($avisos['anteriores']->isNotEmpty() || $avisos['diferencias']->isNotEmpty())
        <div class="caja-avisos caja-gap">
            @foreach ($avisos['anteriores'] as $t)
                <div class="notice caja-alerta" wire:key="aviso-ant-{{ $t->id }}">
                    <x-icon name="history" />
                    <div><b>{{ __('Turno abierto desde un día anterior') }}</b><p>{{ __(':caja · :sucursal sigue abierta desde el :fecha (:nombre).', ['caja' => $t->caja->nombre, 'sucursal' => $t->sucursal->nombre, 'fecha' => $hora($t->fecha_apertura)->format('d/m H:i'), 'nombre' => $t->usuarioApertura->name]) }}</p></div>
                    <button type="button" class="btn ghost sm" wire:click="verDetalleTurno({{ $t->id }})">{{ __('Ver turno') }}</button>
                </div>
            @endforeach
            @foreach ($avisos['diferencias'] as $t)
                @php($falta = Decimal::comparar((string) $t->diferencia, '0', 2) < 0)
                <div class="notice {{ $falta ? 'caja-peligro' : '' }}" wire:key="aviso-dif-{{ $t->id }}">
                    <x-icon name="status-alert" />
                    <div><b>{{ $falta ? __('Cierre con faltante') : __('Cierre con sobrante') }}</b><p>{{ __(':caja · :sucursal cerró el :fecha con :monto (:nombre).', ['caja' => $t->caja->nombre, 'sucursal' => $t->sucursal->nombre, 'fecha' => $hora($t->fecha_cierre)->format('d/m H:i'), 'monto' => $firmado((string) $t->diferencia), 'nombre' => $t->usuarioCierre?->name ?? $t->usuarioApertura->name]) }}</p></div>
                    <button type="button" class="btn ghost sm" wire:click="verDetalleTurno({{ $t->id }})">{{ __('Ver arqueo') }}</button>
                </div>
            @endforeach
        </div>
    @endif

    {{-- Cuatro cifras del período --}}
    <div class="grid four caja-kpis caja-gap">
        <article class="card stat">
            <div class="row between"><span class="stat-label">{{ __('Entró') }}</span><x-icon name="cash" /></div>
            <div class="stat-value">{{ Decimal::bs($resumen['total']) }}</div>
            <div class="caja-split"><span>{{ __('Efectivo') }} <b>{{ $corto($resumen['efectivo']) }}</b></span><span>{{ __('Electrónico') }} <b>{{ $corto($resumen['electronico']) }}</b></span></div>
        </article>
        <article class="card stat">
            <div class="row between"><span class="stat-label">{{ __('Salió') }}</span><x-icon name="expense" /></div>
            <div class="stat-value">{{ Decimal::bs($resumen['egresos']) }}</div>
            <div class="caja-split"><span>{{ __('Efectivo') }} <b>{{ $corto($resumen['egresos_efectivo']) }}</b></span><span>{{ __('Electrónico') }} <b>{{ $corto($resumen['egresos_electronico']) }}</b></span></div>
        </article>
        <article class="card stat">
            <div class="row between"><span class="stat-label">{{ __('Neto de caja') }}</span><x-icon name="wallet" /></div>
            <div class="stat-value">{{ Decimal::bs($resumen['neto']) }}</div>
            <div class="stat-foot">{{ __('Entradas − salidas · no es ganancia') }}</div>
        </article>
        <article class="card stat">
            <div class="row between"><span class="stat-label">{{ __('Diferencias de arqueo') }}</span><x-icon name="scale" /></div>
            <div class="stat-value {{ Decimal::comparar($diferencias['total'], '0', 2) < 0 ? 'danger-text' : '' }}">{{ $firmado($diferencias['total']) }}</div>
            <div class="stat-foot">{{ trans_choice(':n faltante|:n faltantes', $diferencias['faltantes'], ['n' => $diferencias['faltantes']]) }} · {{ trans_choice(':n sobrante|:n sobrantes', $diferencias['sobrantes'], ['n' => $diferencias['sobrantes']]) }} · {{ trans_choice(':n cierre|:n cierres', $diferencias['cierres'], ['n' => $diferencias['cierres']]) }}</div>
        </article>
    </div>

    {{-- Estado de cada caja ahora mismo --}}
    <section class="card card-pad caja-gap">
        <div class="card-head">
            <div class="heading"><span class="heading-icon"><x-icon name="pos" /></span>{{ __('Cajas en este momento') }}</div>
            <span class="subtle">{{ __('Efectivo que debería haber en cada cajón ahora') }}</span>
        </div>
        @if ($cajasAhora === [])
            <div class="empty">{{ $caja === 'externo' ? __('Lo de fuera de caja no pasa por ningún cajón ni arqueo; sus movimientos están abajo.') : __('No hay cajas activas con estos filtros.') }}</div>
        @else
            <div class="caja-ahora">
                @foreach ($cajasAhora as $fila)
                    @php($t = $fila['turno'])
                    <article class="caja-tarjeta {{ $t?->esDelDiaAnterior() ? 'caja-alerta' : '' }}" wire:key="caja-ahora-{{ $fila['caja']->id }}">
                        <div class="caja-tarjeta-top">
                            <div><div class="caja-nombre">{{ $fila['caja']->nombre }}</div><span class="subtle">{{ $fila['caja']->sucursal->nombre }} · {{ $fila['caja']->modalidad === 'compartida' ? __('Compartida') : __('Individual') }}</span></div>
                            @if ($t)
                                <span class="badge {{ $t->esDelDiaAnterior() ? 'warning' : 'success' }}"><span class="caja-dot"></span>{{ $t->esDelDiaAnterior() ? __('Abierta desde :fecha', ['fecha' => $hora($t->fecha_apertura)->format('d/m H:i')]) : __('Abierta :hora', ['hora' => $hora($t->fecha_apertura)->format('H:i')]) }}</span>
                            @elseif ($fila['ultimo'])
                                <span class="badge">{{ __('Cerrada :fecha', ['fecha' => $hora($fila['ultimo']->fecha_cierre)->format('d/m H:i')]) }}</span>
                            @else
                                <span class="badge">{{ __('Sin turnos') }}</span>
                            @endif
                        </div>
                        @if ($t)
                            <div><div class="caja-etiqueta">{{ __('Debe haber en efectivo') }}</div><div class="caja-monto">{{ Decimal::bs($fila['esperado']) }}</div></div>
                            <div class="caja-split"><span>{{ __('Electrónico') }} <b>{{ $corto($fila['totales']['electronico_ingreso']) }}</b></span><span>{{ __('Ventas') }} <b>{{ $fila['totales']['ventas'] }}</b></span></div>
                            <div class="row between"><span class="subtle">{{ collect([$t->usuarioApertura->name])->merge($t->participantes->map(fn ($p) => $p->usuario->name))->unique()->join(' · ') }}</span>
                                <span class="caja-tarjeta-acciones"><button type="button" class="btn ghost sm" wire:click="verDetalleTurno({{ $t->id }})">{{ __('Ver turno') }}</button><button type="button" class="btn soft sm" wire:click="cerrarTurno({{ $t->id }})"><x-icon name="lock" size="sm" /> {{ __('Cerrar caja') }}</button></span></div>
                        @elseif ($fila['ultimo'])
                            @php($u = $fila['ultimo'])
                            <div><div class="caja-etiqueta">{{ __('Último cierre') }}</div><div class="caja-monto {{ $u->resultado() === 'faltante' ? 'danger-text' : '' }}">{{ $u->diferencia === null ? __('Sin conteo') : $firmado((string) $u->diferencia) }}</div></div>
                            <div class="caja-split"><span>{{ __('Esperado') }} <b>{{ $corto((string) ($u->efectivo_esperado ?? '0')) }}</b></span><span>{{ __('Contado') }} <b>{{ $u->monto_cierre === null ? '—' : $corto((string) $u->monto_cierre) }}</b></span></div>
                            <div class="row between"><span class="subtle">{{ $u->usuarioApertura->name }}</span>
                                <span class="caja-tarjeta-acciones"><button type="button" class="btn ghost sm" wire:click="verDetalleTurno({{ $u->id }})">{{ __('Ver arqueo') }}</button><button type="button" class="btn soft sm" wire:click="abrirCaja({{ $fila['caja']->id }})"><x-icon name="plus" size="sm" /> {{ __('Abrir caja') }}</button></span></div>
                        @else
                            <div class="row between"><span class="subtle">{{ __('Todavía no se abrió ningún turno en esta caja.') }}</span>
                                <span class="caja-tarjeta-acciones"><button type="button" class="btn soft sm" wire:click="abrirCaja({{ $fila['caja']->id }})"><x-icon name="plus" size="sm" /> {{ __('Abrir caja') }}</button></span></div>
                        @endif
                    </article>
                @endforeach
            </div>
        @endif
    </section>

    {{-- Detalle por pestañas: solo se consulta la pestaña visible --}}
    <section class="card card-pad">
        <div class="tabs" role="tablist">
            @foreach (['turnos' => __('Turnos y cierres'), 'movimientos' => __('Movimientos'), 'responsables' => __('Por responsable'), 'dias' => __('Por día y sucursal')] as $clave => $etiqueta)
                <button type="button" role="tab" aria-selected="{{ $pestanaActiva === $clave ? 'true' : 'false' }}" class="tab {{ $pestanaActiva === $clave ? 'active' : '' }}" wire:click="cambiarPestana('{{ $clave }}')">{{ $etiqueta }}</button>
            @endforeach
        </div>

        @if ($pestanaActiva === 'turnos')
            <div class="table-wrap"><table>
                <thead><tr><th>{{ __('Turno') }}</th><th>{{ __('Caja') }}</th><th>{{ __('Responsable') }}</th><th>{{ __('Horario') }}</th><th class="num">{{ __('Fondo') }}</th><th class="num">{{ __('Esperado') }}</th><th class="num">{{ __('Contado') }}</th><th>{{ __('Resultado') }}</th><th></th></tr></thead>
                <tbody>
                    @forelse ($turnos as $t)
                        @php($otros = $t->participantes->map(fn ($p) => $p->usuario->name)->reject(fn ($n) => $n === $t->usuarioApertura->name))
                        <tr wire:key="turno-{{ $t->id }}">
                            <td><b>#{{ $t->id }}</b></td>
                            <td>{{ $t->caja->nombre }}<small>{{ $t->sucursal->nombre }}</small></td>
                            <td>{{ $t->usuarioApertura->name }}@if ($otros->isNotEmpty())<small>+ {{ $otros->join(', ') }}</small>@endif</td>
                            <td>{{ $hora($t->fecha_apertura)->format('d/m H:i') }} → {{ $t->fecha_cierre ? $hora($t->fecha_cierre)->format($hora($t->fecha_cierre)->isSameDay($hora($t->fecha_apertura)) ? 'H:i' : 'd/m H:i') : __('abierta') }}</td>
                            <td class="num">{{ $corto((string) $t->monto_apertura) }}</td>
                            <td class="num"><b>{{ $corto(ReporteCaja::esperado($t, $totalesTurnos[$t->id] ?? null)) }}</b></td>
                            <td class="num">{{ $t->monto_cierre === null ? '—' : $corto((string) $t->monto_cierre) }}</td>
                            <td>@include('livewire.caja.partials.resultado', ['turno' => $t])</td>
                            <td><button type="button" class="btn ghost sm" wire:click="verDetalleTurno({{ $t->id }})">{{ __('Ver') }}</button></td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="empty">{{ __('No hubo turnos en este período.') }}</td></tr>
                    @endforelse
                </tbody>
            </table></div>
            <div class="table-footer"><span>{{ __('Esperado = fondo inicial + cobros en efectivo − salidas en efectivo. Lo electrónico no va al cajón.') }}</span></div>
            {{ $turnos->links(data: ['scrollTo' => false]) }}
        @elseif ($pestanaActiva === 'movimientos')
            <div class="tool-row">
                <div class="search"><x-icon name="search" /><input class="input" aria-label="{{ __('Buscar movimiento') }}" placeholder="{{ __('Buscar cliente, proveedor, documento o usuario...') }}" wire:model.live.debounce.400ms="buscar"></div>
                <select class="select" aria-label="{{ __('Tipo de movimiento') }}" wire:model.live="sentido">
                    <option value="">{{ __('Entradas y salidas') }}</option>
                    <option value="ingreso">{{ __('Solo entradas') }}</option>
                    <option value="egreso">{{ __('Solo salidas') }}</option>
                </select>
                <select class="select" aria-label="{{ __('Medio de pago') }}" wire:model.live="medio">
                    <option value="">{{ __('Todos los medios') }}</option>
                    <option value="efectivo">{{ __('Con efectivo') }}</option>
                    <option value="electronico">{{ __('Con electrónico') }}</option>
                </select>
            </div>
            <div class="table-wrap"><table>
                <thead><tr><th>{{ __('Fecha') }}</th><th>{{ __('Movimiento') }}</th><th>{{ __('Cliente / detalle') }}</th><th>{{ __('Caja') }}</th><th>{{ __('Usuario') }}</th><th class="num">{{ __('Efectivo') }}</th><th class="num">{{ __('Electrónico') }}</th><th class="num">{{ __('Total') }}</th></tr></thead>
                <tbody>
                    @forelse ($movimientos as $m)
                        @php($d = ReporteCaja::describir($m))
                        @php($sale = $m->sentido === 'egreso')
                        <tr wire:key="mov-{{ $m->origen_tipo }}-{{ $m->origen_id }}-{{ $m->sentido }}">
                            <td>{{ \Illuminate\Support\Carbon::parse($m->fecha, 'UTC')->timezone($zona)->format($periodoActual->esUnDia() ? 'H:i' : 'd/m H:i') }}</td>
                            <td><span class="caja-mov {{ $sale ? 'salida' : '' }}">{{ $d['concepto'] }} · {{ $d['referencia'] }}</span></td>
                            <td>{{ $d['detalle'] }}</td>
                            <td>{{ $m->caja ?? __('Fuera de caja') }}<small>{{ $m->sucursal }}</small></td>
                            <td>{{ $m->usuario }}</td>
                            <td class="num">{{ Decimal::comparar((string) $m->efectivo, '0', 2) === 0 ? '—' : $corto((string) $m->efectivo) }}</td>
                            <td class="num">{{ Decimal::comparar((string) $m->electronico, '0', 2) === 0 ? '—' : $corto((string) $m->electronico) }}</td>
                            <td class="num {{ $sale ? 'danger-text' : 'success-text' }}"><b>{{ $sale ? '− ' : '+ ' }}{{ Decimal::bs((string) $m->total) }}</b></td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="empty">{{ __('No hay movimientos de dinero con estos filtros.') }}</td></tr>
                    @endforelse
                </tbody>
            </table></div>
            <div class="table-footer"><span>{{ __('Cada pago aparece una vez, en la fecha y hora en que se recibió o pagó.') }}</span><span>{{ trans_choice(':n movimiento|:n movimientos', $movimientos->total(), ['n' => $movimientos->total()]) }}</span></div>
            {{ $movimientos->links(data: ['scrollTo' => false]) }}
        @elseif ($pestanaActiva === 'responsables')
            @php($suma = fn (string $campo) => array_reduce($responsables, fn (string $t, object $r) => Decimal::sumar($t, $r->{$campo}, 2), '0.00'))
            <div class="table-wrap"><table>
                <thead><tr><th>{{ __('Usuario') }}</th><th>{{ __('Cajas') }}</th><th class="num">{{ __('Cobros') }}</th><th class="num">{{ __('Efectivo') }}</th><th class="num">{{ __('Electrónico') }}</th><th class="num">{{ __('Total cobrado') }}</th><th class="num">{{ __('Salidas') }}</th><th>{{ __('Sus turnos') }}</th></tr></thead>
                <tbody>
                    @forelse ($responsables as $r)
                        <tr wire:key="resp-{{ $r->id }}">
                            <td><b>{{ $r->nombre }}</b><small>{{ \App\Enums\Rol::tryFrom($r->rol)?->etiqueta() ?? $r->rol }}</small></td>
                            <td>{{ $r->cajas !== '' ? $r->cajas : '—' }}</td>
                            <td class="num">{{ $r->cobros }}</td>
                            <td class="num">{{ $corto($r->efectivo) }}</td>
                            <td class="num">{{ $corto($r->electronico) }}</td>
                            <td class="num"><b>{{ $corto($r->total) }}</b></td>
                            <td class="num">{{ Decimal::comparar($r->salidas, '0', 2) === 0 ? '—' : $corto($r->salidas) }}</td>
                            <td>
                                @if ($r->abiertos > 0)<span class="badge success"><span class="caja-dot"></span>{{ __('Turno abierto') }}</span>@endif
                                @if ($r->cierres > 0)
                                    @php($cmp = Decimal::comparar($r->diferencia, '0', 2))
                                    <span class="badge {{ $cmp < 0 ? 'danger' : ($cmp > 0 ? 'info' : 'success') }}">{{ $cmp === 0 ? trans_choice(':n cierre cuadrado|:n cierres cuadrados', $r->cierres, ['n' => $r->cierres]) : $firmado($r->diferencia) }}</span>
                                @endif
                                @if ($r->abiertos === 0 && $r->cierres === 0)<span class="subtle">—</span>@endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="empty">{{ __('Nadie registró dinero en este período.') }}</td></tr>
                    @endforelse
                </tbody>
                @if ($responsables !== [])
                    <tfoot><tr><td colspan="2"><b>{{ __('Total') }}</b></td><td class="num"><b>{{ array_sum(array_column($responsables, 'cobros')) }}</b></td><td class="num"><b>{{ $corto($suma('efectivo')) }}</b></td><td class="num"><b>{{ $corto($suma('electronico')) }}</b></td><td class="num"><b>{{ $corto($suma('total')) }}</b></td><td class="num"><b>{{ $corto($suma('salidas')) }}</b></td><td></td></tr></tfoot>
                @endif
            </table></div>
            <div class="table-footer"><span>{{ __('Cada cobro cuenta para quien lo recibió, aunque el turno sea compartido. «Sus turnos» muestra los turnos que abrió y se cerraron en el período.') }}</span></div>
        @else
            <div class="wide-left">
                <div>
                    <div class="row between"><span class="subtle">{{ __('Entradas del :desde al :hasta', ['desde' => $rangoDias->desde->format('d/m'), 'hasta' => $rangoDias->hasta->format('d/m')]) }}</span></div>
                    <div class="chart-space" style="margin-top:13px">
                        <div class="chartbars">
                            @foreach ($barras as $b)
                                <div class="chartbar" data-label="{{ $b['etiqueta'] }}" title="{{ $b['titulo'] }}"><span style="height:{{ $b['alto'] }}%"></span></div>
                            @endforeach
                        </div>
                    </div>
                    <div style="height:18px"></div>
                    <div class="table-wrap"><table>
                        <thead><tr><th>{{ __('Fecha') }}</th><th class="num">{{ __('Entradas') }}</th><th class="num">{{ __('Salidas') }}</th><th class="num">{{ __('Neto') }}</th><th class="num">{{ __('Diferencias') }}</th></tr></thead>
                        <tbody>
                            @foreach ($dias as $d)
                                @php($cmp = Decimal::comparar($d['diferencia'], '0', 2))
                                <tr wire:key="dia-{{ $d['fecha']->format('Ymd') }}"><td>{{ $d['fecha']->format('d/m/Y') }}</td><td class="num">{{ $corto($d['entradas']) }}</td><td class="num">{{ $corto($d['salidas']) }}</td><td class="num"><b>{{ $corto($d['neto']) }}</b></td><td class="num {{ $cmp < 0 ? 'danger-text' : ($cmp > 0 ? 'success-text' : 'subtle') }}">{{ $cmp === 0 ? '0,00' : $firmado($d['diferencia']) }}</td></tr>
                            @endforeach
                        </tbody>
                    </table></div>
                </div>
                <div>
                    <div class="row between"><span class="subtle">{{ __('Por sucursal en el período') }}</span></div>
                    <div class="stack" style="margin-top:13px">
                        @forelse ($porSucursal as $s)
                            @php($parte = Decimal::comparar($totalEntradas, '0', 2) > 0 ? (int) Decimal::dividir(Decimal::multiplicar((string) $s->total, '100', 2), $totalEntradas, 0) : 0)
                            <div class="caja-tarjeta" wire:key="suc-{{ $s->id }}">
                                <div class="caja-tarjeta-top"><div class="caja-nombre">{{ $s->nombre }}</div><span class="subtle">{{ __(':p % de las entradas', ['p' => $parte]) }}</span></div>
                                <div class="caja-formula">
                                    <div class="list-line"><span>{{ __('Entradas') }}</span><b class="success-text">+ {{ Decimal::bs(Decimal::redondear((string) $s->total)) }}</b></div>
                                    <div class="list-line"><span>{{ __('Salidas') }}</span><b class="danger-text">− {{ Decimal::bs(Decimal::redondear((string) $s->salidas)) }}</b></div>
                                    <div class="list-line total"><b>{{ __('Neto') }}</b><strong class="callout-number">{{ Decimal::bs(Decimal::redondear((string) $s->neto)) }}</strong></div>
                                </div>
                                <div class="progress"><span style="width:{{ $parte }}%"></span></div>
                            </div>
                        @empty
                            <div class="empty">{{ __('Sin sucursales activas.') }}</div>
                        @endforelse
                    </div>
                </div>
            </div>
        @endif
    </section>

    @include('livewire.caja.partials.detalle-turno')
</div>
