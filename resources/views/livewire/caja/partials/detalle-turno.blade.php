@use('App\Support\Decimal')
{{-- Arqueo de un turno: la cuenta del efectivo paso a paso, el conteo y lo electrónico aparte. --}}
@if ($turnoDetalle && $arqueoDetalle)
    @php($hora = fn ($f) => $f?->copy()->timezone($zona))
    @php($resultado = $turnoDetalle->resultado())
    @php($fechaApertura = $hora($turnoDetalle->fecha_apertura))
    @php($fechaCierre = $turnoDetalle->fecha_cierre ? $hora($turnoDetalle->fecha_cierre) : null)
    @php($hasta = ($fechaCierre ?? now()->timezone($zona))->format('Y-m-d'))
    @php($desde = $fechaApertura->format('Y-m-d'))
    @php($filtrosImprimir = array_filter([
        'caja' => $turnoDetalle->caja_id,
        'sucursal' => $turnoDetalle->sucursal_id,
        'fecha' => $hasta,
        'desde' => $desde === $hasta ? null : $desde,
    ]))
    <x-modal :titulo="__('Arqueo · Turno #:id', ['id' => $turnoDetalle->id])" cerrar="cerrarDetalleTurno">
        <p class="subtle" style="margin:-12px 0 16px;line-height:1.6">
            {{ $turnoDetalle->caja->nombre }} · {{ $turnoDetalle->sucursal->nombre }} · {{ $turnoDetalle->caja->modalidad === 'compartida' ? __('Compartida') : __('Individual') }}<br>
            {{ __('Abrió :nombre el :fecha', ['nombre' => $turnoDetalle->usuarioApertura->name, 'fecha' => $hora($turnoDetalle->fecha_apertura)->format('d/m/Y H:i')]) }}
            @if ($turnoDetalle->fecha_cierre)
                <br>{{ __('Cerró :nombre el :fecha', ['nombre' => $turnoDetalle->usuarioCierre?->name ?? '—', 'fecha' => $hora($turnoDetalle->fecha_cierre)->format('d/m/Y H:i')]) }}
            @endif
            @if ($turnoDetalle->participantes->isNotEmpty())
                <br>{{ __('Participan: :nombres', ['nombres' => $turnoDetalle->participantes->map(fn ($p) => $p->usuario->name)->join(', ')]) }}
            @endif
        </p>

        <div class="caja-formula">
            <div class="list-line"><span>{{ __('Fondo inicial') }}</span><b>{{ Decimal::bs($arqueoDetalle['monto_apertura']) }}</b></div>
            <div class="list-line"><span>{{ __('+ Cobros en efectivo') }}</span><b class="success-text">{{ Decimal::bs($arqueoDetalle['ingresos_efectivo']) }}</b></div>
            <div class="list-line"><span>{{ __('− Salidas en efectivo') }}</span><b class="danger-text">{{ Decimal::bs($arqueoDetalle['egresos_efectivo']) }}</b></div>
            <div class="list-line total"><b>{{ __('= Debe haber en efectivo') }}</b><strong class="callout-number">{{ Decimal::bs($turnoDetalle->efectivo_esperado ?? $arqueoDetalle['efectivo_esperado']) }}</strong></div>
        </div>

        <div class="caja-resultado">
            @if ($resultado === 'abierto')
                <span class="subtle">{{ __('Turno abierto: aún no tiene conteo.') }}</span>
                <span class="badge success"><span class="caja-dot"></span>{{ __('Abierta') }}</span>
            @elseif ($resultado === 'sin_conteo')
                <span class="subtle">{{ __('Se cerró sin registrar el efectivo contado.') }}</span>
                <span class="badge">{{ __('Sin conteo') }}</span>
            @else
                <span>{{ __('Contado al cerrar') }} <b>{{ Decimal::bs((string) $turnoDetalle->monto_cierre) }}</b></span>
                @include('livewire.caja.partials.resultado', ['turno' => $turnoDetalle])
            @endif
        </div>
        @if ($turnoDetalle->observaciones)
            <p class="subtle" style="margin:10px 0 0">{{ __('Observación: :texto', ['texto' => $turnoDetalle->observaciones]) }}</p>
        @endif

        <div class="divider"></div>
        <div class="list-line"><span>{{ __('Electrónico del turno (no va al cajón)') }}</span><b>{{ Decimal::bs($arqueoDetalle['ingresos_electronico']) }}</b></div>
        <div class="list-line"><span>{{ __('Ventas registradas') }}</span><b>{{ $arqueoDetalle['cantidad_ventas'] }}</b></div>

        <div class="modal-actions">
            <button type="button" class="btn ghost" wire:click="cerrarDetalleTurno">{{ __('Cerrar') }}</button>
            @can('ver-reportes')
                <a class="btn soft" href="{{ route('caja.imprimir', $filtrosImprimir) }}" target="_blank"><x-icon name="print" size="sm" /> {{ __('Imprimir') }}</a>
            @endcan
        </div>
    </x-modal>
@endif
