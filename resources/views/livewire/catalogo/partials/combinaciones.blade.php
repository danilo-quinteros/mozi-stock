{{-- Tallas, colores y cuadrícula de combinaciones (ArmaCombinaciones) de «Editar modelo». «Nuevo producto» usa las dos
     piezas por separado (variantes-eleccion y variantes-cuadricula) para poner su casilla en la misma tarjeta.
     Variables: las de ArmaCombinaciones::vistaCombinaciones(), $editando, $conSkuBase (muestra aquí el SKU base),
     $cancelarUrl y $textoGuardar. --}}
<section class="card card-pad" data-test="modelo-variantes">
    <div class="card-head"><div><div class="heading">{{ __('Tallas y colores') }}</div><p class="subtle" style="margin:9px 0 0">{{ __('Marca las tallas y los colores que maneja el modelo. Sin tallas o sin colores también vale.') }}</p></div>
        @can('administrarListas', \App\Models\Modelo::class)
            <a class="btn ghost sm" href="{{ route('configuracion.tallas-colores') }}" target="_blank" rel="noopener"><x-icon name="edit" size="sm" /> {{ __('Editar listas') }}</a>
        @endcan
    </div>

    @include('livewire.catalogo.partials.variantes-eleccion')
</section>

@include('livewire.catalogo.partials.variantes-cuadricula')
