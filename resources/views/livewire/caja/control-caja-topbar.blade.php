<div>
    @if ($visible)
        @php
            $hora = fn ($fecha) => $fecha->copy()->timezone(config('mozistock.business_timezone'));
            $listaTurnos = fn ($turnos) => $turnos->map(fn ($t) => __(':caja · :sucursal · turno #:id', ['caja' => $t->caja->nombre, 'sucursal' => $t->sucursal->nombre, 'id' => $t->id]))->join(', ');
        @endphp
        @if ($caja)
            <div class="top-caja-container" style="display:flex;align-items:center;justify-content:center">
                @if ($turno)
                    <div class="top-caja-pill" style="display:inline-flex;align-items:center;gap:8px;padding:4px 6px 4px 12px;background:var(--input);border:1px solid rgba(34,197,94,0.3);border-radius:999px;box-shadow:0 2px 8px rgba(0,0,0,0.06)">
                        <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:#22c55e;box-shadow:0 0 8px rgba(34,197,94,0.6)"></span>
                        <span style="font-size:12px;font-weight:600">{{ $caja->nombre }}</span>
                        <span class="badge soft sm" style="font-size:11px;padding:2px 7px">#{{ $turno->id }} · {{ $hora($turno->fecha_apertura)->format('H:i') }}</span>
                        @include('livewire.caja.partials.otros-turnos-topbar')
                        <button type="button" class="btn soft sm" wire:click="abrirModalCierre" style="padding:3px 9px;font-size:11.5px;border-radius:999px">
                            <x-icon name="lock" size="sm" /> {{ __('Cerrar caja') }}
                        </button>
                    </div>
                @else
                    <div class="top-caja-pill" style="display:inline-flex;align-items:center;gap:8px;padding:4px 6px 4px 12px;background:var(--input);border:1px solid rgba(234,179,8,0.3);border-radius:999px">
                        <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:#eab308"></span>
                        <span class="subtle" style="font-size:12px;font-weight:600">{{ $caja->nombre }}</span>
                        <span class="badge warning sm" style="font-size:11px;padding:2px 7px">{{ __('Cerrada') }}</span>
                        @include('livewire.caja.partials.otros-turnos-topbar')
                        <button type="button" class="btn primary sm" wire:click="abrirModalApertura" style="padding:3px 10px;font-size:11.5px;border-radius:999px">
                            <x-icon name="plus" size="sm" /> {{ __('Abrir caja') }}
                        </button>
                    </div>
                @endif
            </div>
        @endif

        {{-- Apertura: la caja de la barra o la elegida en Caja y arqueo --}}
        @if ($modalAperturaAbierto && $cajaApertura)
            <x-modal :titulo="__('Apertura de turno en :caja', ['caja' => $cajaApertura->nombre])" cerrar="cerrarModalApertura"
                descripcion="Inicia una nueva sesión de cobros ingresando el fondo inicial de efectivo.">
                <form wire:submit="ejecutarApertura">
                    @if ($misTurnos->isNotEmpty())
                        <div class="notice caja-alerta" style="margin-bottom:14px" role="status"><x-icon name="info" /><div>
                            <b>{{ trans_choice('Sigues con 1 caja abierta|Sigues con :count cajas abiertas', $misTurnos->count()) }}</b>
                            <p>{{ $listaTurnos($misTurnos) }}. {{ __('No se cierra: cada venta o cobro queda en la caja donde se registra.') }}</p>
                        </div></div>
                    @endif
                    <div class="fields" style="margin-bottom:14px">
                        <x-campo etiqueta="Caja física" campo="caja_nombre">
                            <input id="caja_nombre" class="input" type="text" value="{{ $cajaApertura->nombre }} · {{ $cajaApertura->sucursal->nombre }} ({{ $cajaApertura->modalidad === 'compartida' ? __('Modalidad compartida') : __('Modalidad individual') }})" disabled>
                        </x-campo>
                        <x-campo etiqueta="Fondo inicial de efectivo (Bs)" campo="fondoInicial">
                            <input id="fondoInicial" class="input" type="number" step="0.01" min="0" wire:model="fondoInicial" required autofocus>
                        </x-campo>
                    </div>
                    <p class="subtle" style="font-size:13px;margin:0 0 16px">{{ __('Puedes registrar Bs 0,00 si no dispones de efectivo inicial.') }}</p>
                    <div class="modal-actions">
                        <button type="button" class="btn ghost" wire:click="cerrarModalApertura">{{ __('Cancelar') }}</button>
                        <button class="btn primary" type="submit" wire:loading.attr="disabled" wire:target="ejecutarApertura"><x-icon name="check" /> {{ __('Abrir turno') }}</button>
                    </div>
                </form>
            </x-modal>
        @endif

        {{-- Cierre y arqueo: el turno de la barra o el elegido en Caja y arqueo --}}
        @if ($modalCierreAbierto && $turnoCierre && $arqueoCierre)
            <x-modal :titulo="__('Cierre de turno y arqueo · :caja', ['caja' => $turnoCierre->caja->nombre])" cerrar="cerrarModalCierre"
                :descripcion="__('Turno #:id de :sucursal. Revisa el efectivo esperado y registra el dinero contado.', ['id' => $turnoCierre->id, 'sucursal' => $turnoCierre->sucursal->nombre])">
                <form wire:submit="ejecutarCierre">
                    <div class="grid two" style="margin-bottom:14px;gap:12px">
                        <article class="card card-pad" style="background:var(--input)">
                            <div class="stat-label">{{ __('Fondo inicial') }}</div>
                            <div class="stat-value" style="font-size:18px">{{ \App\Support\Decimal::bs($arqueoCierre['monto_apertura']) }}</div>
                            <div class="stat-foot">{{ __('Apertura: :hora · :usuario', ['hora' => $hora($turnoCierre->fecha_apertura)->format('d/m H:i'), 'usuario' => $turnoCierre->usuarioApertura->name]) }}</div>
                        </article>
                        <article class="card card-pad" style="background:var(--input)">
                            <div class="stat-label">{{ __('Cobros en efectivo') }}</div>
                            <div class="stat-value" style="font-size:18px;color:#22c55e">+{{ \App\Support\Decimal::bs($arqueoCierre['ingresos_efectivo']) }}</div>
                            <div class="stat-foot">{{ __('Ventas en efectivo del turno') }}</div>
                        </article>
                    </div>

                    <div class="card card-pad" style="background:var(--input);margin-bottom:16px">
                        <div class="row between" style="margin-bottom:6px">
                            <span class="subtle">{{ __('Cobros electrónicos (QR / Transferencia)') }}</span>
                            <b>{{ \App\Support\Decimal::bs($arqueoCierre['ingresos_electronico']) }}</b>
                        </div>
                        <div class="row between" style="margin-bottom:6px">
                            <span class="subtle">{{ __('Total ventas cobradas') }}</span>
                            <b>{{ \App\Support\Decimal::bs($arqueoCierre['total_ventas_cobradas']) }} ({{ $arqueoCierre['cantidad_ventas'] }} {{ $arqueoCierre['cantidad_ventas'] === 1 ? __('venta') : __('ventas') }})</b>
                        </div>
                        <div class="divider"></div>
                        <div class="row between" style="font-size:16px;margin-top:8px">
                            <b>{{ __('Efectivo teórico esperado en caja') }}</b>
                            <b style="color:var(--accent)">{{ \App\Support\Decimal::bs($arqueoCierre['efectivo_esperado']) }}</b>
                        </div>
                    </div>

                    <div class="fields" style="margin-bottom:14px">
                        <x-campo etiqueta="Efectivo contado físico (Bs)" campo="montoCierre">
                            <input id="montoCierre" class="input" type="number" step="0.01" min="0" wire:model.live="montoCierre" required autofocus>
                        </x-campo>
                        <x-campo etiqueta="Observación (obligatoria si hay diferencia)" campo="observacionesCierre">
                            <input id="observacionesCierre" class="input" type="text" wire:model="observacionesCierre" placeholder="{{ __('Motivo del faltante o sobrante') }}">
                        </x-campo>
                    </div>

                    @if ($arqueoCierre['diferencia'] !== null)
                        <div style="margin-bottom:16px">
                            @if ($arqueoCierre['estado_diferencia'] === 'cuadrada')
                                <div class="badge success" style="padding:8px 12px;font-size:14px;display:flex;align-items:center;gap:6px">
                                    <x-icon name="check" size="sm" /> {{ __('Caja cuadrada: el efectivo contado coincide exactamente.') }}
                                </div>
                            @elseif ($arqueoCierre['estado_diferencia'] === 'sobrante')
                                <div class="badge warning" style="padding:8px 12px;font-size:14px;display:flex;align-items:center;gap:6px">
                                    <x-icon name="info" size="sm" /> {{ __('Sobrante en caja de :monto', ['monto' => \App\Support\Decimal::bs($arqueoCierre['diferencia'])]) }}
                                </div>
                            @elseif ($arqueoCierre['estado_diferencia'] === 'faltante')
                                <div class="badge danger" style="padding:8px 12px;font-size:14px;display:flex;align-items:center;gap:6px">
                                    <x-icon name="alert-triangle" size="sm" /> {{ __('Faltante en caja de :monto', ['monto' => \App\Support\Decimal::bs(ltrim($arqueoCierre['diferencia'], '-'))]) }}
                                </div>
                            @endif
                        </div>
                    @endif

                    <div class="modal-actions">
                        <button type="button" class="btn ghost" wire:click="cerrarModalCierre">{{ __('Cancelar') }}</button>
                        <button class="btn primary" type="submit" wire:loading.attr="disabled" wire:target="ejecutarCierre"><x-icon name="check" /> {{ __('Confirmar cierre de turno') }}</button>
                    </div>
                </form>
            </x-modal>
        @endif

        <x-aviso :mensaje="$aviso" :numero="$avisoNumero" :tipo="$avisoTipo" />
    @endif
</div>
