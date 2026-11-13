@use('App\Support\Decimal')
@use('App\Models\Producto')
@use('App\Domain\Inventario\DatosLote')
@use('App\Domain\Inventario\Consultas\StockLotes')
@use('App\Domain\Inventario\Consultas\ReporteVencimientos')
<div>
    <div class="page-head">
        <div>
            <div class="eyebrow">{{ __('Inventario') }}</div>
            <h1>{{ __('Vencimientos') }}<span class="accent-text">.</span></h1>
            <p class="lead">{{ __('Lotes con stock por sucursal: lo vencido no se vende y se da de baja en Ajustes → Salida → Vencido.') }}</p>
        </div>
        <div class="page-actions">
            <a class="btn ghost" href="{{ route('inventario.ajuste') }}"><x-icon name="edit" /> {{ __('Nuevo ajuste') }}</a>
        </div>
    </div>

    <div class="grid three" style="margin-bottom:18px">
        <button type="button" class="card stat quick-entry" wire:click="$set('estado', 'vencidos')" data-test="resumen-vencidos">
            <span class="stat-label">{{ __('Vencidos con stock') }}</span>
            <div @class(['stat-value', 'danger-text' => $resumen['vencidos'] > 0])>{{ $resumen['vencidos'] }}</div>
            <div class="stat-foot">{{ __(':cantidad unidades bloqueadas para la venta', ['cantidad' => Decimal::formatear($resumen['unidades_vencidas'])]) }}</div>
        </button>
        <button type="button" class="card stat quick-entry" wire:click="$set('estado', '30')" data-test="resumen-proximos">
            <span class="stat-label">{{ __('Vencen en 30 días') }}</span>
            <div class="stat-value">{{ $resumen['proximos'] }}</div>
            <div class="stat-foot">{{ __(':cantidad unidades por vender primero', ['cantidad' => Decimal::formatear($resumen['unidades_proximas'])]) }}</div>
        </button>
        <button type="button" class="card stat quick-entry" wire:click="$set('estado', 'sin_fecha')" data-test="resumen-sin-fecha">
            <span class="stat-label">{{ __('Sin fecha · por regularizar') }}</span>
            <div class="stat-value">{{ $resumen['sin_fecha'] }}</div>
            <div class="stat-foot">{{ __(':cantidad unidades sin lote conocido', ['cantidad' => Decimal::formatear($resumen['unidades_sin_fecha'])]) }}</div>
        </button>
    </div>

    <section class="card card-pad">
        <div class="card-head">
            <div class="heading"><span class="heading-icon"><x-icon name="calendar" /></span>{{ __('Lotes con stock') }}</div>
            <a class="btn ghost sm" href="{{ route('inventario.vencimientos.exportar', array_filter(['estado' => $filtrosActivos['estado'] === 'todos' ? '' : $filtrosActivos['estado'], 'sucursal' => $sucursal, 'q' => trim($buscar)])) }}"><x-icon name="download" size="sm" /> {{ __('Exportar CSV') }}</a>
        </div>
        <div class="tabs" role="tablist">
            @foreach (['todos' => 'Todos', 'vencidos' => 'Vencidos', '30' => '30 días', '60' => '60 días', '90' => '90 días', 'sin_fecha' => 'Sin fecha'] as $clave => $etiqueta)
                <button type="button" role="tab" class="tab {{ $filtrosActivos['estado'] === (string) $clave ? 'active' : '' }}" aria-selected="{{ $filtrosActivos['estado'] === (string) $clave ? 'true' : 'false' }}" wire:click="$set('estado', '{{ $clave }}')">{{ __($etiqueta) }}</button>
            @endforeach
        </div>
        <div class="tool-row">
            <div class="search">
                <x-icon name="search" />
                <input class="input" type="search" wire:model.live.debounce.350ms="buscar" placeholder="{{ __('Buscar producto, código o lote') }}" aria-label="{{ __('Buscar lotes') }}">
            </div>
            <select class="select" wire:model.live="sucursal" @disabled($sucursalFija) aria-label="{{ __('Filtrar sucursal') }}">
                <option value="">{{ __('Todas las sucursales') }}</option>
                @foreach ($sucursales as $opcion)
                    <option value="{{ $opcion->id }}">{{ $opcion->nombre }}</option>
                @endforeach
            </select>
        </div>
        <div class="table-wrap lotes-tabla">
            <table>
                <thead><tr><th>{{ __('Producto') }}</th><th>{{ __('Lote') }}</th><th>{{ __('Sucursal') }}</th><th>{{ __('Stock') }}</th><th>{{ __('Estado') }}</th><th><span class="sr-only">{{ __('Acción') }}</span></th></tr></thead>
                <tbody>
                    @forelse ($lotes as $fila)
                        @php($lote = ReporteVencimientos::lote($fila))
                        <tr wire:key="stock-lote-{{ $fila->id }}" data-lote="{{ $lote['id'] }}">
                            <td data-label="{{ __('Producto') }}"><b>{{ Producto::formatoNombreCompleto($fila->producto, $fila->contenido, $fila->abreviatura) }}</b>@if ($fila->sku)<small style="display:block;color:var(--faint);margin-top:4px">{{ $fila->sku }}</small>@endif</td>
                            <td data-label="{{ __('Lote') }}"><a class="link" href="{{ route('inventario.lote', $lote['id']) }}">{{ StockLotes::etiqueta($lote) }}</a></td>
                            <td data-label="{{ __('Sucursal') }}">{{ $fila->sucursal }}</td>
                            <td data-label="{{ __('Stock') }}"><b>{{ Decimal::formatear($lote['cantidad']) }}</b></td>
                            <td data-label="{{ __('Estado') }}">@include('livewire.inventario.partials.estado-lote', ['lote' => $lote])</td>
                            <td data-label="{{ __('Acción') }}">
                                @if ($lote['regularizacion'])
                                    <button class="btn ghost sm" type="button" wire:click="regularizar({{ (int) $fila->sucursal_id }}, {{ (int) $fila->producto_id }})" data-test="regularizar">{{ __('Regularizar') }}</button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6"><div class="empty">{{ __('No hay lotes con stock que coincidan con el filtro.') }}</div></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($lotes->hasPages())
            <div class="table-footer">
                <span>{{ __('Página :currentpage de :lastpage', ['currentpage' => $lotes->currentPage(), 'lastpage' => $lotes->lastPage()]) }}</span>
                <span class="row">
                    @unless ($lotes->onFirstPage())<button class="btn ghost sm" type="button" wire:click="previousPage">{{ __('Anterior') }}</button>@endunless
                    @if ($lotes->hasMorePages())<button class="btn ghost sm" type="button" wire:click="nextPage">{{ __('Siguiente') }}</button>@endif
                </span>
            </div>
        @endif
    </section>
    <p class="subtle" style="margin-top:14px">{{ __('Vencido = la fecha ya pasó (el día del vencimiento todavía se vende). El stock sin fecha se vende primero y queda pendiente de regularizar.') }}</p>

    @if ($regularizando)
        <x-modal titulo="Regularizar lote" descripcion="Pasa el stock sin fecha a sus lotes reales. El total de la sucursal no cambia." cerrar="cerrarRegularizacion">
            <div class="confirm-grid">
                <div><span class="subtle">{{ __('Producto') }}</span><b>{{ $regularizando['producto'] }}</b></div>
                <div><span class="subtle">{{ __('Sucursal') }}</span><b>{{ $regularizando['sucursal'] }}</b></div>
                <div><span class="subtle">{{ __('Sin fecha') }}</span><b data-test="regularizar-disponible">{{ Decimal::formatear($regularizando['disponible']) }}</b></div>
            </div>
            <div class="lote-regularizar">
                @foreach ($regLineas as $indice => $linea)
                    <div class="lote-campos" wire:key="reg-linea-{{ $indice }}">
                        <label class="lote-campo">
                            <span>{{ __('N.º de lote') }}</span>
                            <input class="input" type="text" maxlength="40" autocomplete="off" wire:model.blur="regLineas.{{ $indice }}.lote" aria-label="{{ __('Número de lote, línea :n', ['n' => $indice + 1]) }}">
                        </label>
                        <label class="lote-campo">
                            <span>{{ __('Expiry') }}</span>
                            <input class="input" type="text" inputmode="numeric" placeholder="{{ __('mm/aaaa') }}" autocomplete="off" wire:model.live.debounce.500ms="regLineas.{{ $indice }}.vencimiento" aria-label="{{ __('Vencimiento, línea :n', ['n' => $indice + 1]) }}">
                        </label>
                        <label class="lote-campo">
                            <span>{{ __('Cantidad') }}</span>
                            <input class="input rapid-numeric" type="text" inputmode="decimal" wire:model.blur="regLineas.{{ $indice }}.cantidad" aria-label="{{ __('Cantidad, línea :n', ['n' => $indice + 1]) }}">
                        </label>
                        @if (count($regLineas) > 1)
                            <button class="icon-action" type="button" wire:click="quitarLinea({{ $indice }})" aria-label="{{ __('Quitar línea :n', ['n' => $indice + 1]) }}" title="{{ __('Quitar') }}"><x-icon name="x" size="sm" /></button>
                        @endif
                    </div>
                @endforeach
                <button class="btn ghost sm" type="button" wire:click="otroLote"><x-icon name="plus" size="sm" /> {{ __('Otro lote') }}</button>
            </div>
            @if ($regularizando['vencidos'] !== [])
                <div class="notice caja-alerta" style="margin-top:12px" role="status"><x-icon name="status-alert" /><div><b>{{ __('Lotes ya vencidos') }}</b><p>{{ __('Quedarán bloqueados para la venta desde el primer momento:') }} {{ implode('; ', $regularizando['vencidos']) }}</p></div></div>
            @endif
            <x-campo etiqueta="Nota *" campo="regNota" class="rapid-note-field">
                <input id="regNota" class="input" type="text" maxlength="250" wire:model.blur="regNota" placeholder="{{ __('Por ejemplo: conteo físico de lotes del estante') }}" required>
            </x-campo>
            @if ($errors->has('lineas'))
                <div class="rapid-message" role="alert">@foreach ($errors->get('lineas') as $mensaje)<p class="danger-text" style="margin:0 0 4px">{{ $mensaje }}</p>@endforeach</div>
            @endif
            <div class="modal-actions">
                <button type="button" class="btn ghost" wire:click="cerrarRegularizacion">{{ __('Cancelar') }}</button>
                <button type="button" class="btn primary" wire:click="confirmarRegularizacion" wire:loading.attr="disabled" wire:target="confirmarRegularizacion" data-test="regularizar-confirmar"><x-icon name="check" /> {{ __('Regularizar') }}</button>
            </div>
        </x-modal>
    @endif

    <x-aviso :mensaje="$aviso" :numero="$avisoNumero" />
</div>
