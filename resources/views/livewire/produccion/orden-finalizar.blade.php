@use('App\Support\Decimal')
{{-- Registrar lo producido de una orden Aprobada (ESPECIFICACION_PRODUCCION.md § 6.3). --}}
@php
    $unidad = $orden->producto->unidad->abreviatura;
@endphp
<div>
    <div class="page-head">
        <div>
            <div class="eyebrow">{{ __('Producción · Órdenes') }}</div>
            <h1>{{ __('Registrar lo producido') }}<span class="accent-text">.</span></h1>
            <p class="lead">{{ __(':codigo · :producto en :sucursal. Se planificaron :cantidad :unidad.', ['codigo' => $orden->codigo(), 'producto' => $orden->producto->nombre, 'sucursal' => $orden->sucursal->nombre, 'cantidad' => Decimal::formatear((string) $orden->cantidad_planificada), 'unidad' => $unidad]) }}</p>
        </div>
        <div class="page-actions">
            <a class="btn ghost" href="{{ route('produccion.ordenes.ver', $orden) }}"><x-icon name="arrow-left" /> {{ __('Volver sin guardar') }}</a>
        </div>
    </div>

    @if ($errors->hasAny(['orden', 'lineas', 'consumos']))
        <div class="notice caja-alerta" style="margin-bottom:17px" role="alert" data-test="errores-finalizar" data-error-validacion><x-icon name="status-alert" /><div>
            @foreach (['orden', 'lineas', 'consumos'] as $clave)
                @foreach ($errors->get($clave) as $mensaje)<p>{{ $mensaje }}</p>@endforeach
            @endforeach
        </div></div>
    @endif

    <form wire:submit="finalizar" class="stack">
        <section class="card card-pad">
            <div class="card-head"><div class="heading"><span class="heading-icon"><x-icon name="box" /></span>{{ __('Producto obtenido') }}</div></div>
            <div class="fields">
                <x-campo etiqueta="Cantidad obtenida" campo="cantidad_obtenida">
                    <div class="row" style="gap:8px;align-items:center">
                        <input id="cantidad_obtenida" class="input" type="text" inputmode="decimal" wire:model.live.debounce.400ms="cantidad_obtenida" data-test="cantidad-obtenida" required>
                        <span class="subtle">{{ $unidad }}</span>
                    </div>
                    @if ($variacion !== null && Decimal::comparar($variacion, '0') !== 0)
                        <small @class(['danger-text' => Decimal::comparar($variacion, '0') < 0, 'success-text' => Decimal::comparar($variacion, '0') > 0]) style="display:block;margin-top:6px" data-test="variacion">
                            {{ Decimal::comparar($variacion, '0') < 0 ? __('Merma de :porcentaje % respecto de lo planificado.', ['porcentaje' => Decimal::formatear(ltrim($variacion, '-'), 0, 1)]) : __('Excedente de :porcentaje % respecto de lo planificado.', ['porcentaje' => Decimal::formatear($variacion, 0, 1)]) }}
                        </small>
                    @endif
                </x-campo>
                @if ($conLote)
                    <x-campo etiqueta="Vencimiento del lote" campo="vencimiento">
                        <input id="vencimiento" class="input" type="date" min="{{ $hoy }}" wire:model="vencimiento" data-test="vencimiento-lote" required>
                        <small class="subtle" style="display:block;margin-top:6px">{{ __('Fecha en que vence lo producido. Se guarda en el lote :lote.', ['lote' => $orden->codigo()]) }}</small>
                    </x-campo>
                @endif
            </div>
        </section>

        <section class="card card-pad" data-test="consumo-real">
            <div class="card-head">
                <div>
                    <div class="heading"><span class="heading-icon"><x-icon name="table" /></span>{{ __('Consumo real') }}</div>
                    <p class="subtle" style="margin:9px 0 0">{{ __('Viene con lo descontado al aprobar. Si se usó menos, la diferencia vuelve al almacén; si se usó más, se descuenta lo extra.') }}</p>
                </div>
            </div>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>{{ __('Materia prima') }}</th><th>{{ __('Descontado') }}</th><th>{{ __('Usado') }}</th><th>{{ __('Diferencia') }}</th></tr></thead>
                    <tbody>
                        @foreach ($lineas as $linea)
                            @php
                                $real = Decimal::normalizar((string) ($consumos[$linea['producto_id']] ?? ''), Decimal::ESCALA_CANTIDAD);
                                $diferencia = $real === null ? null : Decimal::restar($linea['cantidad_teorica'], $real);
                            @endphp
                            <tr wire:key="consumo-{{ $linea['id'] }}">
                                <td data-label="{{ __('Materia prima') }}"><b>{{ $linea['nombre'] }}</b></td>
                                <td data-label="{{ __('Descontado') }}">{{ Decimal::formatear($linea['cantidad_teorica']) }} {{ $linea['unidad'] }}</td>
                                <td data-label="{{ __('Usado') }}">
                                    <div class="row" style="gap:8px;align-items:center">
                                        <input class="input" style="max-width:140px" type="text" inputmode="decimal" wire:model.live.debounce.400ms="consumos.{{ $linea['producto_id'] }}" aria-label="{{ __('Usado de :materia', ['materia' => $linea['nombre']]) }}" data-test="consumo-{{ $linea['producto_id'] }}">
                                        <span class="subtle">{{ $linea['unidad'] }}</span>
                                    </div>
                                    @error('consumos.'.$linea['producto_id'])<p class="auth-error" role="alert">{{ $message }}</p>@enderror
                                </td>
                                <td data-label="{{ __('Diferencia') }}">
                                    @if ($diferencia === null || Decimal::comparar($diferencia, '0') === 0)
                                        —
                                    @elseif (Decimal::comparar($diferencia, '0') > 0)
                                        <span class="success-text">{{ __('Vuelven :cantidad', ['cantidad' => Decimal::formatear($diferencia)]) }}</span>
                                    @else
                                        <span class="danger-text">{{ __('Se descuentan :cantidad más', ['cantidad' => Decimal::formatear(ltrim($diferencia, '-'))]) }}</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        <div class="row" style="justify-content:flex-end;gap:10px">
            <a class="btn ghost" href="{{ route('produccion.ordenes.ver', $orden) }}">{{ __('Cancelar') }}</a>
            <button class="btn primary" type="submit" wire:loading.attr="disabled" wire:target="finalizar" data-test="confirmar-finalizar"><x-icon name="check" /> {{ __('Finalizar e ingresar al stock') }}</button>
        </div>
    </form>
</div>
