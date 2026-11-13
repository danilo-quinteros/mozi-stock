@use('App\Support\Decimal')
{{-- Precio consumidor editable en el listado (clic → campo → Enter). Lo usan la fila de un producto suelto y la de una
     combinación de tallas y colores; Productos::guardarPrecio elige la acción que corresponde a cada uno. --}}
    @if ($administra && $editandoProductoId === $producto->id)
        <div style="display:inline-flex;flex-direction:column;gap:4px">
            <div style="display:inline-flex;align-items:center;gap:4px">
                <span style="font-size:11px;font-weight:700;color:var(--faint)">Bs</span>
                <input type="text"
                       inputmode="decimal"
                       class="input"
                       style="min-height:28px;height:28px;padding:0 6px;font-size:12px;font-weight:700;width:75px"
                       wire:model="editandoPrecio"
                       wire:keydown.enter.prevent="guardarPrecio"
                       wire:keydown.escape="cancelarEdicionPrecio"
                       autofocus
                       x-init="$el.focus(); $el.select()">
                <button type="button"
                        class="icon-action"
                        style="height:28px;width:28px;border-radius:7px;color:var(--brand-bright);background:var(--accent-soft)"
                        wire:click="guardarPrecio"
                        wire:loading.attr="disabled"
                        title="{{ __('Guardar') }}">
                    <x-icon name="check" size="sm" />
                </button>
                <button type="button"
                        class="icon-action"
                        style="height:28px;width:28px;border-radius:7px"
                        wire:click="cancelarEdicionPrecio"
                        title="{{ __('Cancelar') }}">
                    <x-icon name="x" size="sm" />
                </button>
            </div>
            @error('editandoPrecio')
                <small class="danger-text" style="font-size:10px;margin-top:2px">{{ $message }}</small>
            @enderror
        </div>
    @else
        <div style="display:inline-flex;align-items:center;gap:6px">
            @if ($administra)
                <button type="button"
                        wire:click="iniciarEdicionPrecio({{ $producto->id }})"
                        style="all:unset;cursor:pointer;display:inline-flex;align-items:center;gap:5px"
                        title="{{ __('Clic para editar precio') }}">
                    <b>{{ Decimal::bs($producto->precio_consumidor) }}</b>
                    <span class="icon-action" style="height:22px;width:22px;border-radius:6px;border:1px solid var(--border-soft);opacity:0.7">
                        <x-icon name="edit" size="sm" />
                    </span>
                </button>
            @else
                <b>{{ Decimal::bs($producto->precio_consumidor) }}</b>
            @endif
        </div>
    @endif
