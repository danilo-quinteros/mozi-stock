{{-- Elección de tallas y colores (ArmaCombinaciones). Variables: las de vistaCombinaciones() y $conSkuBase. --}}
<div class="variantes-eleccion">
    @if ($conSkuBase)
        <x-campo etiqueta="SKU base (opcional)" campo="sku_base">
            <input id="sku_base" class="input" type="text" maxlength="{{ \App\Domain\Catalogo\Variantes::MAX_SKU_BASE }}" wire:model.live.debounce.400ms="sku_base" placeholder="{{ __('Ej. POL001') }}">
        </x-campo>
    @endif

    <div class="field">
        <label class="field-label" for="grupo_talla_id">{{ __('Grupo de tallas') }}</label>
        <select id="grupo_talla_id" class="select" wire:model.live="grupo_talla_id">
            <option value="">{{ __('Sin talla') }}</option>
            @foreach ($grupos as $grupo)
                <option value="{{ $grupo->id }}">{{ $grupo->nombre }}</option>
            @endforeach
        </select>
        @error('grupo_talla_id')<p class="auth-error" role="alert">{{ $message }}</p>@enderror
    </div>

    @if ($grupo_talla_id !== '')
        <div class="field">
            <div class="variantes-eleccion-head">
                <span class="field-label">{{ __('Tallas') }}</span>
                <button class="btn ghost sm" type="button" wire:click="todasLasTallas">{{ __('Todas') }}</button>
                <button class="btn ghost sm" type="button" wire:click="ningunaTalla">{{ __('Ninguna') }}</button>
            </div>
            <div class="variante-opciones" role="group" aria-label="{{ __('Tallas') }}">
                @forelse ($tallasGrupo as $talla)
                    <label class="variante-opcion {{ $talla->activa ? '' : 'is-inactiva' }}" wire:key="opcion-talla-{{ $talla->id }}">
                        <input type="checkbox" value="{{ $talla->id }}" wire:model.live="tallas">
                        <span>{{ $talla->nombre }}</span>
                    </label>
                @empty
                    <span class="subtle">{{ __('Este grupo no tiene tallas activas.') }}</span>
                @endforelse
            </div>
            @error('tallas')<p class="auth-error" role="alert">{{ $message }}</p>@enderror
        </div>
    @endif

    <div class="field">
        <span class="field-label">{{ __('Colores') }}</span>
        <div class="variante-opciones" role="group" aria-label="{{ __('Colores') }}">
            @forelse ($coloresDisponibles as $color)
                <label class="variante-opcion {{ $color->activo ? '' : 'is-inactiva' }}" wire:key="opcion-color-{{ $color->id }}">
                    <input type="checkbox" value="{{ $color->id }}" wire:model.live="colores">
                    <span class="color-muestra" @if ($color->muestra_hex) style="background:{{ $color->muestra_hex }}" @endif aria-hidden="true"></span>
                    <span>{{ $color->nombre }}</span>
                </label>
            @empty
                <span class="subtle">{{ __('Aún no hay colores. Sin colores, el modelo solo se arma por talla.') }}</span>
            @endforelse
        </div>
        @error('colores')<p class="auth-error" role="alert">{{ $message }}</p>@enderror
    </div>
</div>
