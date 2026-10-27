<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="dark" class="dark">
<head>@include('partials.head')</head>
<body>
    <main class="login-page" id="contenido">
        <section class="card login-box" aria-label="{{ __('Acceso a MoziStock') }}">
            <div class="login-brand"><x-brand /></div>
            {{ $slot }}
            <div class="row between login-links">
                <span class="footnote">{{ __('MoziStudios') }}</span>
                <span class="row" style="gap:6px"><x-idioma-selector />
                <button type="button" class="btn sm ghost" data-theme-toggle data-label-light="{{ __('Modo claro') }}" data-label-dark="{{ __('Modo oscuro') }}">{{ __('Cambiar tema') }}</button></span>
            </div>
        </section>
    </main>
    @fluxScripts
</body>
</html>
