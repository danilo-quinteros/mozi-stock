<x-layouts::centrado :title="__($titulo)">
    <div class="eyebrow" style="justify-content:center">{{ __('Error :codigo', ['codigo' => $codigo]) }}</div>
    <h1 style="margin-top:12px">{{ __($titulo) }}<span class="accent-text">.</span></h1>
    <p class="lead" style="margin-bottom:24px">{{ __($mensaje) }}</p>
    <a class="btn primary" href="{{ url('/') }}">{{ __('Volver al inicio') }} <x-icon name="arrow" size="sm" /></a>
</x-layouts::centrado>
