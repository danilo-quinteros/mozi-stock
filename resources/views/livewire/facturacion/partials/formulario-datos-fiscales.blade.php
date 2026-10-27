{{-- Datos fiscales: lo usan Facturación → Datos fiscales y el popup de la guía de configuración. --}}
<form wire:submit="guardarDatos">
    <fieldset @disabled(! $puedeConfigurar) style="border:0;padding:0;margin:0;min-width:0">
        <div class="fields">
            <x-campo etiqueta="NIT" campo="nit">
                <input id="nit" class="input" type="text" inputmode="numeric" wire:model="nit" maxlength="13" required>
            </x-campo>
            <x-campo etiqueta="Razón social" campo="razon_social">
                <input id="razon_social" class="input" type="text" wire:model="razon_social" maxlength="200" required>
            </x-campo>
            <x-campo etiqueta="Modo de facturación" campo="modo">
                <select id="modo" class="select" wire:model="modo">
                    @foreach ($modos as $opcion)
                        <option value="{{ $opcion->value }}">{{ $opcion->etiqueta() }} — {{ $opcion->descripcion() }}</option>
                    @endforeach
                </select>
            </x-campo>
            <x-campo etiqueta="Actividad económica" campo="actividad_economica">
                <select id="actividad_economica" class="select" wire:model.live="actividad_economica" @disabled($actividades->isEmpty())>
                    <option value="">{{ $actividades->isEmpty() ? __('Sincroniza los catálogos para elegirla') : __('Sin definir') }}</option>
                    @foreach ($actividades as $actividad)
                        <option value="{{ $actividad->codigo }}">{{ $actividad->codigo }} · {{ $actividad->descripcion }}</option>
                    @endforeach
                </select>
            </x-campo>
            <x-campo etiqueta="Producto SIN" campo="producto_sin">
                <select id="producto_sin" class="select" wire:model="producto_sin" @disabled($productos->isEmpty())>
                    <option value="">{{ $actividad_economica === '' ? __('Elige primero la actividad') : ($productos->isEmpty() ? __('Sin productos para esta actividad') : __('Sin definir')) }}</option>
                    @foreach ($productos as $producto)
                        <option value="{{ $producto->codigo }}">{{ $producto->codigo }} · {{ $producto->descripcion }}</option>
                    @endforeach
                </select>
            </x-campo>
        </div>
        <p class="subtle" style="margin:14px 0 0">{{ __('Actividad y producto SIN se usan por defecto en las líneas de la factura. La unidad de medida de cada producto se configura en Productos → Unidades y medidas.') }}</p>
        @if ($puedeConfigurar)
            <div class="row" style="margin-top:18px;justify-content:flex-end">
                <button class="btn primary" type="submit" wire:loading.attr="disabled" wire:target="guardarDatos"><x-icon name="check" /> {{ __('Guardar datos fiscales') }}</button>
            </div>
        @endif
    </fieldset>
</form>
