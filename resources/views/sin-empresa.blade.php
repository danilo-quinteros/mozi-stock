<x-layouts::app title="{{ __('Inicio') }}">
    <div class="page-head">
        <div><div class="eyebrow">{{ __('Tu cuenta') }}</div>
            <h1>{{ __('Hola, :name', ['name' => auth()->user()->name]) }}<span class="accent-text">.</span></h1>
            <p class="lead">{{ __('Tu acceso está listo, pero todavía no perteneces a ninguna empresa.') }}</p>
        </div>
    </div>
    <section class="card card-pad foundation-state">
        <div class="notice"><x-icon name="info" /><div><b>{{ __('Sin empresa asignada') }}</b><p>{{ __('Pide al administrador de tu empresa o a MoziStudios que te asignen un rol. Mientras tanto solo puedes gestionar tu cuenta personal.') }}</p></div></div>
        <div style="margin-top:18px"><a class="btn primary" href="{{ route('profile.edit') }}">{{ __('Mi cuenta') }} <x-icon name="arrow" size="sm" /></a></div>
    </section>
</x-layouts::app>
