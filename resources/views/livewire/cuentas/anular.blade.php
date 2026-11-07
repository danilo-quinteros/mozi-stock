<div>
    @if ($abierto)
        <x-modal :titulo="$this->resumen['titulo']" descripcion="No se borra: queda en el historial como anulado, con tu nombre, la fecha y el motivo." cerrar="$set('abierto', false)">
            <form wire:submit="confirmar">
                <div class="notice" style="margin-bottom:16px"><x-icon name="info" /><div><p>{{ $this->resumen['texto'] }}</p></div></div>
                <div class="fields">
                    <x-campo etiqueta="Motivo de la anulación" campo="motivo" style="grid-column:1/-1">
                        <input id="motivo" class="input" type="text" maxlength="200" wire:model="motivo" placeholder="{{ __('Ej. Se registró dos veces') }}" required>
                    </x-campo>
                    @if ($this->resumen['efectivo'])
                        @include('livewire.partials.caja', ['metodo' => 'efectivo'])
                    @endif
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn ghost" wire:click="$set('abierto', false)">{{ __('Cancelar') }}</button>
                    <button class="btn primary" type="submit" wire:loading.attr="disabled" wire:target="confirmar"><x-icon name="x" /> {{ __('Anular') }}</button>
                </div>
            </form>
        </x-modal>
    @endif
</div>
