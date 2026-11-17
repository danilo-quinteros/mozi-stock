{{-- Formulario de materia prima (ESPECIFICACION_PRODUCCION.md § 4.1, Excel § 1.1). Más simple que el de productos:
     sin precios, categoría, contenido, foto ni variantes. --}}
<div>
    <div class="page-head">
        <div>
            <div class="eyebrow">{{ __('Producción') }}</div>
            <h1>{{ $materiaId ? __('Editar materia prima') : __('Nueva materia prima') }}<span class="accent-text">.</span></h1>
            <p class="lead">{{ __('Materia prima, insumo o envase. No se vende: entra con un ingreso (compra) y sale con la producción.') }}</p>
        </div>
    </div>

    <form wire:submit="guardar">
        <div class="stack" style="max-width:860px">
            <section class="card card-pad" data-test="materia-datos">
                <div class="card-head"><div><div class="heading">{{ __('Datos') }}</div><p class="subtle" style="margin:9px 0 0">{{ __('Cómo se llama, en qué unidad se compra y se usa, y para qué sirve.') }}</p></div></div>
                <div class="fields">
                    <x-campo etiqueta="Nombre *" campo="nombre">
                        <input id="nombre" class="input" type="text" maxlength="150" wire:model="nombre" placeholder="{{ __('Ej. Fragancia floral') }}" required autofocus>
                    </x-campo>
                    <x-campo etiqueta="Código" campo="sku">
                        <input id="sku" class="input" type="text" maxlength="40" wire:model="sku" placeholder="{{ __('Automático (MP-0001)') }}">
                    </x-campo>
                    <x-campo etiqueta="Unidad *" campo="unidad_medida_id">
                        <select id="unidad_medida_id" class="select" wire:model="unidad_medida_id" required>
                            @foreach ($unidades as $unidad)
                                <option value="{{ $unidad->id }}">{{ $unidad->nombre }} ({{ $unidad->abreviatura }})</option>
                            @endforeach
                        </select>
                    </x-campo>
                    <x-campo etiqueta="Código de barras" campo="codigo_barras">
                        <input id="codigo_barras" class="input" type="text" maxlength="64" wire:model="codigo_barras" placeholder="{{ __('Opcional, para el escáner') }}">
                    </x-campo>
                </div>
                <p class="subtle" style="margin:6px 0 14px">{{ __('Se compra y se usa en la misma unidad. Si en las recetas usas cantidades muy pequeñas, elige una unidad menor (g en vez de kg).') }}</p>
                <x-campo etiqueta="Usos y aplicaciones" campo="usos">
                    <textarea id="usos" class="input" rows="3" maxlength="2000" wire:model="usos" placeholder="{{ __('Para qué sirve en producción') }}"></textarea>
                </x-campo>
                <x-campo etiqueta="Observación" campo="observacion">
                    <input id="observacion" class="input" type="text" maxlength="250" wire:model="observacion" placeholder="{{ __('Libre anotación') }}">
                </x-campo>
            </section>

            <section class="card card-pad" data-test="materia-inventario">
                <div class="card-head"><div><div class="heading">{{ __('Inventario y costo') }}</div><p class="subtle" style="margin:9px 0 0">{{ __('Avisos de stock y costo de referencia.') }}</p></div></div>
                <div class="fields">
                    <x-campo etiqueta="Stock mínimo" campo="stock_minimo">
                        <input id="stock_minimo" class="input" type="text" inputmode="decimal" wire:model="stock_minimo" placeholder="0">
                    </x-campo>
                    <x-campo etiqueta="Stock máximo" campo="stock_maximo">
                        <input id="stock_maximo" class="input" type="text" inputmode="decimal" wire:model="stock_maximo" placeholder="{{ __('Sin máximo') }}">
                    </x-campo>
                    <x-campo etiqueta="Costo unitario inicial (Bs)" campo="costo_inicial">
                        <input id="costo_inicial" class="input" type="text" inputmode="decimal" wire:model="costo_inicial" @disabled($conCompras) placeholder="0,00" data-test="materia-costo-inicial">
                    </x-campo>
                </div>
                <p class="subtle" style="margin:6px 0 14px">
                    @if ($conCompras)
                        {{ __('Ya tiene compras: el costo promedio lo calcula el sistema con cada ingreso.') }}
                    @else
                        {{ __('Solo para el stock que cargues sin compra (ajuste o inventario inicial). Desde la primera compra, el costo promedio lo calcula el sistema.') }}
                    @endif
                </p>
                <div class="producto-opciones">
                    @if ($conLotes)
                        <label class="producto-opcion {{ $controla_vencimiento ? 'is-activo' : '' }}">
                            <input type="checkbox" class="toggle" wire:model.live="controla_vencimiento" data-test="materia-controla-vencimiento">
                            <span>
                                <b>{{ __('Controla lote y vencimiento') }}</b>
                                <small>{{ __('Los ingresos piden N.º de lote y vencimiento, y producción usa primero lo que vence antes. Desmárcala en envases y materiales que no vencen.') }}</small>
                            </span>
                        </label>
                        @error('controla_vencimiento')<p class="auth-error" role="alert">{{ $message }}</p>@enderror
                    @endif
                    @if ($materiaId)
                        <label class="producto-opcion {{ $activo ? 'is-activo' : '' }}">
                            <input type="checkbox" class="toggle" wire:model.live="activo">
                            <span><b>{{ __('Activa') }}</b><small>{{ __('Las inactivas no aparecen al buscar en compras, ajustes ni recetas.') }}</small></span>
                        </label>
                    @endif
                </div>
            </section>

            <div class="product-form-actions producto-form-final">
                <a class="btn ghost" href="{{ route('produccion.materias') }}">{{ __('Cancelar') }}</a>
                <button class="btn primary" type="submit" wire:loading.attr="disabled" wire:target="guardar" data-test="guardar-materia">
                    <x-icon name="check" /> {{ __('Guardar materia prima') }}
                </button>
            </div>
        </div>
    </form>
</div>
