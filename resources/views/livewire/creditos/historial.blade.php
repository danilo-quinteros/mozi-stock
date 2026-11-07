@use('App\Support\Decimal')
@use('App\Models\CuentaPagar')
@use('App\Domain\Creditos\PlanCuotas')
@php($etiquetas = ['pagada' => ['success', 'Pagada'], 'vencida' => ['danger', 'Vencida'], 'parcial' => ['warning', 'Parcial'], 'pendiente' => ['info', 'Pendiente']])
<div>
    <div class="page-head">
        <div>
            <div class="eyebrow">{{ __('Cuentas por cobrar') }}</div>
            <h1>{{ __('Historial de pagos') }}<span class="accent-text">.</span></h1>
            <p class="lead">{{ __('Condiciones del crédito, todos los cobros y el registro de un nuevo cobro en esta pantalla.') }}</p>
        </div>
        <div class="page-actions">
            <a class="btn ghost" href="{{ route('creditos.index') }}"><x-icon name="arrow-left" /> {{ __('Volver a Cuentas por cobrar') }}</a>
            <a class="btn soft" href="{{ route('creditos.imprimir', $venta) }}" target="_blank"><x-icon name="print" /> {{ __('Imprimir con desglose') }}</a>
        </div>
    </div>

    <div class="credit-heading">
        <div class="credit-tag">{{ __('CRÉDITO ·') }} <strong>{{ $venta->codigo() }}</strong></div>
        <div><b>{{ $venta->cliente->nombre ?? __('Sin cliente') }}</b><span class="subtle"> {{ __('· :nombre · Vendedor: :name', ['nombre' => $venta->sucursal->nombre, 'name' => $venta->vendedor->name]) }}</span></div>
    </div>

    <div class="grid three credit-kpis" style="margin-bottom:18px">
        <article class="card stat"><span class="stat-label">{{ __('Total de venta') }}</span><div class="stat-value">{{ Decimal::bs($venta->total) }}</div><div class="stat-foot">{{ __('No equivale a dinero ingresado') }}</div></article>
        <article class="card stat"><span class="stat-label">{{ __('Total cobrado') }}</span><div class="stat-value">{{ Decimal::bs($cobrado) }}</div><div class="stat-foot">{{ __('Cuota inicial + cobros posteriores') }}</div></article>
        <article class="card stat"><span class="stat-label">{{ __('Saldo pendiente') }}</span><div class="stat-value accent-text">{{ Decimal::bs($venta->saldo_pendiente) }}</div><div class="stat-foot">{{ $pendiente ? ($venta->vencimiento ? __('Vence el :fecha', ['fecha' => $venta->vencimiento->format('d/m/Y')]) : ($venta->cuotas->isNotEmpty() ? __('Plan de :n cuotas', ['n' => $venta->cuotas->count()]) : __('Sin vencimiento'))) : __('Crédito pagado') }}</div></article>
    </div>

    @if (Decimal::comparar($venta->devuelto, '0', 2) > 0)
        <div class="notice" style="margin-bottom:18px"><x-icon name="info" /><div><b>{{ __('Devoluciones por :devuelto', ['devuelto' => Decimal::bs($venta->devuelto)]) }}</b><p>Redujeron primero el saldo{{ Decimal::comparar($venta->reembolsado, '0', 2) > 0 ? __(' y se reembolsaron ').Decimal::bs($venta->reembolsado) : '' }}. No son abonos ni cobros.@can('view', $venta) <a class="link" href="{{ route('ventas.ver', $venta) }}">{{ __('Ver la venta') }} <x-icon name="arrow" size="sm" /></a>@endcan</p></div></div>
    @endif

    <div class="credit-grid">
        <div style="display:grid;gap:18px;align-content:start">
            <section class="card card-pad">
                <div class="card-head">
                    <div><div class="heading">{{ __('Condiciones del crédito') }}</div><p class="subtle" style="margin-top:8px">{{ __('Sin vencimiento, una fecha única o un plan de cuotas. Una venta sin vencimiento nunca figura como vencida.') }}</p></div>
                    @if ($pendiente && $planEditable)
                        <button class="btn ghost sm" type="button" wire:click="editarCondiciones"><x-icon name="edit" size="sm" /> {{ __('Definir') }}</button>
                    @endif
                </div>
                @if ($venta->cuotas->isNotEmpty())
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>{{ __('Cuota') }}</th><th>{{ __('Vencimiento') }}</th><th>{{ __('Importe') }}</th><th>{{ __('Saldo') }}</th><th>{{ __('Estado') }}</th></tr></thead>
                            <tbody>
                                @foreach ($venta->cuotas as $cuota)
                                    @php([$tono, $texto] = $etiquetas[CuentaPagar::estadoDe($cuota->saldo, Decimal::restar($cuota->monto, $cuota->saldo, 2), $cuota->vencimiento)])
                                    <tr wire:key="cuota-{{ $cuota->id }}">
                                        <td>{{ $cuota->numero }} / {{ $venta->cuotas->count() }}</td>
                                        <td>{{ $cuota->vencimiento->format('d/m/Y') }}</td>
                                        <td>{{ Decimal::bs($cuota->monto) }}</td>
                                        <td><b>{{ Decimal::bs($cuota->saldo) }}</b></td>
                                        <td><span class="badge {{ $tono }}">{{ __($texto) }}</span></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <p>{{ $venta->vencimiento ? __('Fecha única de vencimiento: :fecha', ['fecha' => $venta->vencimiento->format('d/m/Y')]) : __('Sin fecha de vencimiento.') }}</p>
                @endif
            </section>

            <section class="card card-pad">
                <div class="card-head">
                    <div><div class="heading">{{ __('Historial de pagos') }}</div><p class="subtle" style="margin-top:8px">{{ __('Cada pago aparece en su fecha real y con su método de pago.') }}</p></div>
                    <a class="btn ghost sm" href="{{ route('creditos.historial.exportar', $venta) }}"><x-icon name="download" size="sm" /> CSV</a>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>{{ __('Fecha') }}</th><th>{{ __('Concepto') }}</th><th>{{ __('Efectivo') }}</th><th>{{ __('Electrónico') }}</th><th>{{ __('Total') }}</th></tr></thead>
                        <tbody>
                            @forelse ($movimientos as $indice => $fila)
                                <tr wire:key="movimiento-{{ $indice }}" @if ($fila['anulado']) style="opacity:.6" @endif>
                                    <td>{{ $fila['fecha']->timezone(config('mozistock.business_timezone'))->format('d/m/Y H:i') }}</td>
                                    <td>
                                        <b>{{ $fila['concepto'] }}</b> · {{ $fila['metodo'] }}@if ($fila['anulado']) <span class="badge">{{ __('Anulado') }}</span>@endif
                                        <small style="display:block;color:var(--faint);margin-top:4px">{{ $fila['sucursal'] }} · {{ $fila['receptor'] }}@if ($fila['nota']) · {{ $fila['nota'] }}@endif @if ($fila['comprobante']) · <a class="link" href="{{ route('cobros.comprobante', $fila['cobro_id']) }}" target="_blank">{{ __('Comprobante') }}</a>@endif</small>
                                        @if ($fila['anulado'])<small style="display:block;color:var(--faint);margin-top:4px">{{ $fila['anulado'] }}</small>@endif
                                        @if ($administra && $fila['cobro_id'] && ! $fila['anulado'])<button type="button" class="btn ghost sm" style="margin-top:6px" wire:click="$dispatch('anular', { tipo: 'cobro', id: {{ $fila['cobro_id'] }} })">{{ __('Anular cobro') }}</button>@endif
                                    </td>
                                    <td @if ($fila['anulado']) style="text-decoration:line-through" @endif>{{ Decimal::bs($fila['efectivo']) }}</td>
                                    <td @if ($fila['anulado']) style="text-decoration:line-through" @endif>{{ Decimal::bs($fila['electronico']) }}</td>
                                    <td @if ($fila['anulado']) style="text-decoration:line-through" @endif><b>{{ Decimal::bs($fila['monto']) }}</b></td>
                                </tr>
                            @empty
                                <tr><td colspan="5"><div class="empty">{{ __('No hay pagos registrados (cuota inicial Bs 0,00).') }}</div></td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>
        </div>

        <aside class="card card-pad credit-payment-card">
            <div class="heading">{{ __('Registrar cobro') }}</div>
            @if ($pendiente)
                <p class="subtle" style="margin:8px 0 20px">{{ __('Registra únicamente el importe recibido. El saldo se actualiza al confirmar.') }}</p>
                <form wire:submit="registrar">
                    @include('livewire.partials.pago', ['etiquetaMonto' => 'Monto cobrado (Bs)'])
                    @if (count($items) > 1)
                        <x-campo etiqueta="Aplicación" campo="modo">
                            <select id="modo" class="select" wire:model.live="modo">
                                <option value="automatico">{{ __('Automática · cuotas más antiguas primero') }}</option>
                                <option value="manual">{{ __('Manual · elijo cuánto a cada cuota') }}</option>
                            </select>
                        </x-campo>
                        @if ($modo === 'manual')
                            @foreach ($items as $item)
                                <x-campo :etiqueta="$item['detalle'].' · '.__('saldo').' '.Decimal::bs($item['saldo'])" :campo="'asignaciones.'.$item['clave']" wire:key="asignar-{{ $item['clave'] }}">
                                    <input id="asignaciones.{{ $item['clave'] }}" class="input" type="text" inputmode="decimal" wire:model="asignaciones.{{ $item['clave'] }}" placeholder="0,00">
                                </x-campo>
                            @endforeach
                            @error('asignaciones')<p class="auth-error" role="alert">{{ $message }}</p>@enderror
                        @endif
                    @endif
                    <div class="credit-payment-hint">{{ __('Saldo actual :saldo_pendiente. El cobro se registra con la fecha y hora reales, una sola vez, y cuenta en los cobros de hoy.', ['saldo_pendiente' => Decimal::bs($venta->saldo_pendiente)]) }}</div>
                    <button class="btn primary" type="submit" style="width:100%" wire:loading.attr="disabled" wire:target="registrar,comprobante">{{ __('Registrar cobro') }}</button>
                </form>
            @else
                <div class="notice" style="margin-top:14px"><x-icon name="check" /><div><b>{{ __('Crédito pagado') }}</b><p>{{ __('El saldo es Bs 0,00. No se admiten más cobros.') }}</p></div></div>
            @endif
        </aside>
    </div>

    @if ($formCondiciones)
        <x-modal titulo="Condiciones del crédito" descripcion="El plan de cuotas debe sumar exactamente el saldo pendiente. No cambia el total vendido ni lo cobrado." cerrar="$set('formCondiciones', false)">
            <form wire:submit="guardarCondiciones">
                <x-campo etiqueta="Tipo" campo="tipo">
                    <select id="tipo" class="select" wire:model.live="condicion">
                        <option value="ninguno">{{ __('Sin vencimiento') }}</option>
                        <option value="unico">{{ __('Fecha única de vencimiento') }}</option>
                        <option value="cuotas">{{ __('Plan de cuotas') }}</option>
                    </select>
                </x-campo>
                @if ($condicion === 'unico')
                    <x-campo etiqueta="Vencimiento" campo="vencimiento">
                        <input id="vencimiento" class="input" type="date" wire:model="vencimiento" required>
                    </x-campo>
                @elseif ($condicion === 'cuotas')
                    <div class="fields">
                        <x-campo etiqueta="Cantidad de cuotas" campo="planCantidad">
                            <input id="planCantidad" class="input" type="number" min="1" max="{{ PlanCuotas::MAX_CUOTAS }}" wire:model="planCantidad">
                        </x-campo>
                        <x-campo etiqueta="Primer vencimiento" campo="planPrimera">
                            <input id="planPrimera" class="input" type="date" wire:model="planPrimera">
                        </x-campo>
                        <x-campo etiqueta="Frecuencia" campo="planFrecuencia">
                            <select id="planFrecuencia" class="select" wire:model="planFrecuencia">
                                @foreach (PlanCuotas::FRECUENCIAS as $valor => $texto)
                                    <option value="{{ $valor }}">{{ __($texto) }}</option>
                                @endforeach
                            </select>
                        </x-campo>
                        <div class="field" style="align-self:end"><button class="btn soft" type="button" wire:click="generarPlan">{{ __('Generar cuotas') }}</button></div>
                    </div>
                    @if ($plan !== [])
                        <div class="table-wrap" style="max-height:280px;margin-top:12px">
                            <table>
                                <thead><tr><th>{{ __('Cuota') }}</th><th>{{ __('Vencimiento') }}</th><th>{{ __('Importe (Bs)') }}</th><th></th></tr></thead>
                                <tbody>
                                    @foreach ($plan as $indice => $fila)
                                        <tr wire:key="plan-{{ $indice }}">
                                            <td>{{ $indice + 1 }}</td>
                                            <td><input class="input" type="date" wire:model="plan.{{ $indice }}.vencimiento" aria-label="{{ __('Vencimiento de la cuota :n', ['n' => $indice + 1]) }}"></td>
                                            <td><input class="input" type="text" inputmode="decimal" wire:model.live.debounce.400ms="plan.{{ $indice }}.monto" aria-label="{{ __('Importe de la cuota :n', ['n' => $indice + 1]) }}"></td>
                                            <td><button class="icon-action" type="button" wire:click="quitarCuota({{ $indice }})" aria-label="{{ __('Quitar cuota') }}"><x-icon name="x" size="sm" /></button></td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                    <div class="row" style="justify-content:space-between;margin-top:10px">
                        <button class="btn ghost sm" type="button" wire:click="agregarCuota"><x-icon name="plus" size="sm" /> {{ __('Agregar cuota') }}</button>
                        <span @class(['subtle', 'danger-text' => Decimal::comparar($sumaPlan, $venta->saldo_pendiente, 2) !== 0])>{{ __('Suma :suma de :saldo', ['suma' => Decimal::bs($sumaPlan), 'saldo' => Decimal::bs($venta->saldo_pendiente)]) }}</span>
                    </div>
                    @error('cuotas')<p class="auth-error" role="alert">{{ $message }}</p>@enderror
                @endif
                <div class="modal-actions">
                    <button type="button" class="btn ghost" wire:click="$set('formCondiciones', false)">{{ __('Cancelar') }}</button>
                    <button class="btn primary" type="submit" wire:loading.attr="disabled" wire:target="guardarCondiciones"><x-icon name="check" /> {{ __('Guardar condiciones') }}</button>
                </div>
            </form>
        </x-modal>
    @endif

    @if ($administra)
        <livewire:cuentas.anular :volver="route('creditos.historial', $venta)" />
    @endif

    <x-aviso :mensaje="$aviso" :numero="$avisoNumero" />
</div>
