@use('App\Support\Decimal')
@use('App\Enums\EstadoOrdenProduccion')
@use('App\Domain\Inventario\DatosLote')
{{-- Detalle de una orden de producción (ESPECIFICACION_PRODUCCION.md § 6). --}}
@php
    $unidad = $orden->producto->unidad->abreviatura;
    $zona = config('mozistock.business_timezone');
    $estado = $orden->estado;
@endphp
<div>
    <div class="page-head">
        <div>
            <div class="eyebrow">{{ __('Producción · Órdenes') }}</div>
            <h1>{{ $orden->codigo() }}<span class="accent-text">.</span></h1>
            <p class="lead">
                {{ __(':cantidad :unidad de :producto en :sucursal.', ['cantidad' => Decimal::formatear((string) $orden->cantidad_planificada), 'unidad' => $unidad, 'producto' => $orden->producto->nombre, 'sucursal' => $orden->sucursal->nombre]) }}
                <span class="badge {{ $estado->insignia() }}" data-test="estado-orden">{{ $estado->etiqueta() }}</span>
            </p>
        </div>
        <div class="page-actions">
            <a class="btn ghost" href="{{ route('produccion.ordenes') }}"><x-icon name="arrow-left" /> {{ __('Órdenes') }}</a>
            @if (in_array($estado, EstadoOrdenProduccion::abiertas(), true))
                <button class="btn ghost" type="button" wire:click="$set('anulando', true)" data-test="anular-orden">{{ __('Anular') }}</button>
            @endif
            @if ($estado === EstadoOrdenProduccion::Emitida)
                <a class="btn ghost" href="{{ route('produccion.ordenes.editar', $orden) }}" data-test="editar-orden"><x-icon name="edit" /> {{ __('Editar') }}</a>
                <button class="btn primary" type="button" wire:click="$set('aprobando', true)" data-test="aprobar-orden"><x-icon name="check" /> {{ __('Aprobar') }}</button>
            @elseif ($estado === EstadoOrdenProduccion::Aprobada)
                <a class="btn primary" href="{{ route('produccion.ordenes.finalizar', $orden) }}" data-test="finalizar-orden"><x-icon name="check" /> {{ __('Registrar lo producido') }}</a>
            @endif
        </div>
    </div>

    @if ($errors->any() && ! $aprobando && ! $anulando)
        <div class="notice caja-alerta" style="margin-bottom:17px" role="alert" data-test="errores-orden" data-error-validacion><x-icon name="status-alert" /><div>
            @foreach ($errors->all() as $mensaje)<p>{{ $mensaje }}</p>@endforeach
        </div></div>
    @endif

    <div class="stack">
        <section class="card card-pad">
            <div class="card-head"><div class="heading"><span class="heading-icon"><x-icon name="receipt" /></span>{{ __('Datos de la orden') }}</div></div>
            <div class="info-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:14px">
                <div><span class="subtle">{{ __('Producto') }}</span><b style="display:block">{{ $orden->producto->nombre }}</b>@if ($orden->producto->sku)<small style="color:var(--faint)">{{ $orden->producto->sku }}</small>@endif</div>
                <div><span class="subtle">{{ __('Receta') }}</span><b style="display:block"><a class="link" href="{{ route('produccion.recetas.ver', ['producto' => $orden->producto_id, 'version' => $orden->receta->version]) }}">{{ __('Versión :version', ['version' => $orden->receta->version]) }}</a></b><small style="color:var(--faint)">{{ __('Para :cantidad :unidad', ['cantidad' => Decimal::formatear((string) $orden->cantidad_base), 'unidad' => $unidad]) }}</small></div>
                <div><span class="subtle">{{ __('Programada') }}</span><b style="display:block">{{ $orden->fecha_programada->format('d/m/Y') }}</b></div>
                <div><span class="subtle">{{ __('Emitida') }}</span><b style="display:block">{{ $orden->created_at->timezone($zona)->format('d/m/Y H:i') }}</b><small style="color:var(--faint)">{{ $orden->emisor->name }}</small></div>
                @if ($orden->aprobada_en)
                    <div><span class="subtle">{{ __('Aprobada') }}</span><b style="display:block">{{ $orden->aprobada_en->timezone($zona)->format('d/m/Y H:i') }}</b><small style="color:var(--faint)">{{ $orden->aprobador?->name }}</small></div>
                @endif
                @if ($orden->finalizada_en)
                    <div><span class="subtle">{{ __('Finalizada') }}</span><b style="display:block">{{ $orden->finalizada_en->timezone($zona)->format('d/m/Y H:i') }}</b><small style="color:var(--faint)">{{ $orden->finalizador?->name }}</small></div>
                @endif
                @if ($orden->anulada_en)
                    <div><span class="subtle">{{ __('Anulada') }}</span><b style="display:block">{{ $orden->anulada_en->timezone($zona)->format('d/m/Y H:i') }}</b><small style="color:var(--faint)">{{ $orden->anulador?->name }}</small></div>
                @endif
            </div>
            @if ($orden->nota)<p style="margin:14px 0 0">{{ $orden->nota }}</p>@endif
            @if ($orden->motivo_anulacion)<div class="notice mini-note" style="margin-top:14px"><x-icon name="info" /><div><b>{{ __('Motivo de la anulación:') }}</b> {{ $orden->motivo_anulacion }}</div></div>@endif
        </section>

        @if ($estado === EstadoOrdenProduccion::Finalizada)
            <section class="card card-pad" data-test="resultado-orden">
                <div class="card-head"><div class="heading"><span class="heading-icon"><x-icon name="chart" /></span>{{ __('Resultado') }}</div></div>
                <div class="info-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:14px">
                    <div><span class="subtle">{{ __('Obtenido') }}</span><b style="display:block">{{ Decimal::formatear((string) $orden->cantidad_obtenida) }} {{ $unidad }}</b>
                        <small @class(['danger-text' => Decimal::comparar((string) $variacion, '0') < 0, 'success-text' => Decimal::comparar((string) $variacion, '0') > 0])>{{ Decimal::comparar((string) $variacion, '0') > 0 ? '+' : '' }}{{ Decimal::formatear((string) $variacion, 0, 1) }} % {{ Decimal::comparar((string) $variacion, '0') < 0 ? __('merma') : (Decimal::comparar((string) $variacion, '0') > 0 ? __('excedente') : '') }}</small></div>
                    <div><span class="subtle">{{ __('Costo de la orden') }}</span><b style="display:block">{{ Decimal::bs((string) $orden->costo_total) }}</b></div>
                    <div><span class="subtle">{{ __('Costo unitario') }}</span><b style="display:block">Bs {{ Decimal::formatear((string) $orden->costo_unitario, 2, 4) }}</b><small style="color:var(--faint)">{{ __('por :unidad', ['unidad' => $unidad]) }}</small></div>
                    @if ($orden->lote)
                        <div><span class="subtle">{{ __('Lote producido') }}</span><b style="display:block"><a class="link" href="{{ route('inventario.lote', $orden->lote) }}">{{ $orden->lote->numero }}</a></b><small style="color:var(--faint)">{{ __('Vence :fecha', ['fecha' => DatosLote::formatear($orden->lote->vencimiento?->format('Y-m-d'))]) }}</small></div>
                    @endif
                </div>
            </section>
        @endif

        <section class="card card-pad" data-test="materias-orden">
            <div class="card-head">
                <div class="heading"><span class="heading-icon"><x-icon name="table" /></span>{{ __('Materias primas') }}</div>
                @if ($operaciones !== [])
                    <span class="subtle">{{ implode(' · ', array_map(fn ($tipo, $codigo) => match ($tipo) { 'consumo' => __('Consumo :codigo', ['codigo' => $codigo]), 'cierre' => __('Cierre :codigo', ['codigo' => $codigo]), default => __('Devolución :codigo', ['codigo' => $codigo]) }, array_keys($operaciones), $operaciones)) }}</span>
                @endif
            </div>
            @if ($faltantes !== [])
                <div class="notice mini-note caja-alerta" style="margin-bottom:14px" data-test="aviso-faltantes"><x-icon name="status-alert" /><div>{{ __('No alcanza el stock de algunas materias primas en :sucursal. La orden no se podrá aprobar hasta que haya stock.', ['sucursal' => $orden->sucursal->nombre]) }}</div></div>
            @endif
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>{{ __('Materia prima') }}</th><th>{{ __('Teórico') }}</th>
                            @if ($estado === EstadoOrdenProduccion::Finalizada)<th>{{ __('Real') }}</th>@endif
                            @if ($orden->aprobada_en)<th>{{ __('Costo') }}</th><th>{{ __('Lotes usados') }}</th>@endif
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($lineas as $linea)
                            @php($falta = $faltantes[$linea['producto_id']] ?? null)
                            <tr wire:key="linea-{{ $linea['id'] }}">
                                <td data-label="{{ __('Materia prima') }}"><b>{{ $linea['nombre'] }}</b>@if ($linea['sku'])<small style="display:block;color:var(--faint);margin-top:4px">{{ $linea['sku'] }}</small>@endif</td>
                                <td data-label="{{ __('Teórico') }}">{{ Decimal::formatear($linea['cantidad_teorica']) }} {{ $linea['unidad'] }}
                                    @if ($falta)<small class="danger-text" style="display:block;margin-top:3px">{{ __('Disponible :disponible · faltan :falta', ['disponible' => Decimal::formatear($falta['disponible']), 'falta' => Decimal::formatear($falta['falta'])]) }}</small>@endif
                                </td>
                                @if ($estado === EstadoOrdenProduccion::Finalizada)
                                    <td data-label="{{ __('Real') }}">{{ Decimal::formatear((string) $linea['cantidad_real']) }} {{ $linea['unidad'] }}</td>
                                @endif
                                @if ($orden->aprobada_en)
                                    <td data-label="{{ __('Costo') }}">Bs {{ Decimal::formatear((string) $linea['costo_unitario'], 2, 4) }} <small style="color:var(--faint)">/ {{ $linea['unidad'] }}</small></td>
                                    <td data-label="{{ __('Lotes usados') }}">
                                        @forelse ($lotes[$linea['producto_id']] ?? [] as $lote)
                                            <small style="display:block"><a class="link" href="{{ route('inventario.lote', $lote['id']) }}">{{ $lote['numero'] ?? __('Sin lote') }}</a> · {{ Decimal::formatear($lote['cantidad']) }}@if ($lote['vencimiento']) · {{ __('vence :fecha', ['fecha' => DatosLote::formatear($lote['vencimiento'])]) }}@endif</small>
                                        @empty
                                            —
                                        @endforelse
                                    </td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    @if ($aprobando && $estado === EstadoOrdenProduccion::Emitida)
        <x-modal titulo="Aprobar orden" :descripcion="__('Se descontará del almacén de :sucursal:', ['sucursal' => $orden->sucursal->nombre])" cerrar="$set('aprobando', false)">
            <form wire:submit="aprobar">
                @if ($errors->any())
                    <div class="notice caja-alerta" style="margin-bottom:14px" role="alert" data-test="errores-aprobar" data-error-validacion><x-icon name="status-alert" /><div>
                        @foreach ($errors->all() as $mensaje)<p>{{ $mensaje }}</p>@endforeach
                    </div></div>
                @endif
                <div class="table-wrap" style="margin-bottom:14px">
                    <table>
                        <tbody>
                            @foreach ($lineas as $linea)
                                <tr wire:key="aprobar-{{ $linea['id'] }}"><td>{{ $linea['nombre'] }}</td><td style="text-align:right"><b>{{ Decimal::formatear($linea['cantidad_teorica']) }} {{ $linea['unidad'] }}</b></td></tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="notice mini-note"><x-icon name="info" /><div>{{ __('Si no alcanza el stock, no se descuenta nada. Las materias primas con lote se toman del que vence primero, sin usar lotes vencidos.') }}</div></div>
                <div class="modal-actions">
                    <button type="button" class="btn ghost" wire:click="$set('aprobando', false)">{{ __('Cancelar') }}</button>
                    <button class="btn primary" type="submit" wire:loading.attr="disabled" wire:target="aprobar" data-test="confirmar-aprobar"><x-icon name="check" /> {{ __('Aprobar y descontar') }}</button>
                </div>
            </form>
        </x-modal>
    @endif

    @if ($anulando && in_array($estado, EstadoOrdenProduccion::abiertas(), true))
        <x-modal :titulo="__('Anular orden :codigo', ['codigo' => $orden->codigo()])" :descripcion="$estado === EstadoOrdenProduccion::Aprobada ? __('Las materias primas descontadas vuelven al almacén, a los mismos lotes. No se borra: queda en el historial con tu nombre, la fecha y el motivo.') : __('No se borra: queda en el historial con tu nombre, la fecha y el motivo.')" cerrar="$set('anulando', false)">
            <form wire:submit="anular">
                <div class="fields">
                    <x-campo etiqueta="Motivo de la anulación" campo="motivo" style="grid-column:1/-1">
                        <input id="motivo" class="input" type="text" maxlength="200" wire:model="motivo" placeholder="{{ __('Ej. Se canceló el pedido') }}" data-test="motivo-anulacion" required>
                    </x-campo>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn ghost" wire:click="$set('anulando', false)">{{ __('Cancelar') }}</button>
                    <button class="btn primary" type="submit" wire:loading.attr="disabled" wire:target="anular" data-test="confirmar-anular"><x-icon name="x" /> {{ __('Anular') }}</button>
                </div>
            </form>
        </x-modal>
    @endif

    <x-aviso :mensaje="$aviso" :numero="$avisoNumero" />
</div>
