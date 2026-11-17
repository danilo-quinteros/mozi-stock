{{-- Modal «Regularizar lote» (RegularizaLotes): lo incluyen Vencimientos y la trazabilidad del lote. Necesita
     $regularizando (datos del modal o null) y $regLineas del componente. --}}
@if ($regularizando)
    <x-modal titulo="Regularizar lote" descripcion="Pasa el stock sin fecha a sus lotes reales. El total de la sucursal no cambia." cerrar="cerrarRegularizacion">
        <div class="confirm-grid">
            <div><span class="subtle">{{ __('Producto') }}</span><b>{{ $regularizando['producto'] }}</b></div>
            <div><span class="subtle">{{ __('Sucursal') }}</span><b>{{ $regularizando['sucursal'] }}</b></div>
            <div><span class="subtle">{{ __('Sin fecha') }}</span><b data-test="regularizar-disponible">{{ \App\Support\Decimal::formatear($regularizando['disponible']) }}</b></div>
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
