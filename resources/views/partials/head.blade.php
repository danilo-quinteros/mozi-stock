<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
{{-- Panel, acceso y errores no se indexan: el sitio público tiene su propio layout (x-sitio.layout). --}}
<meta name="robots" content="noindex, nofollow">
<meta name="csrf-token" content="{{ csrf_token() }}">
<meta name="theme-color" content="#df8341">
<meta name="msapplication-TileColor" content="#df8341">
{{-- Textos del aviso rojo de validación (resources/js/app.js). En formularios clásicos trae también el primer error. --}}
<meta name="mozistock-validacion" data-mas="{{ __('(y :n más por revisar)') }}" data-ver="{{ __('Ir al campo') }}"
    @if (isset($errors) && $errors->any()) data-primero="{{ $errors->first() }}" data-total="{{ count($errors->all()) }}" @endif>
<title>{{ isset($title) ? __($title).' · ' : '' }}{{ config('app.name') }}</title>
<link rel="icon" href="{{ asset('img/sitio/logo-mozistock.png') }}" type="image/png">
<link rel="apple-touch-icon" href="{{ asset('img/sitio/logo-mozistock.png') }}">
<script>
    try {
        const theme = localStorage.getItem('mozistock-theme') === 'light' ? 'light' : 'dark';
        document.documentElement.dataset.theme = theme;
        document.documentElement.classList.toggle('dark', theme === 'dark');
        // Pantallas de trabajo amplio (Agente Mozi) piden el menú contraído sin cambiar la preferencia guardada.
        document.documentElement.dataset.sidebar = document.documentElement.dataset.sidebarAuto === 'collapsed'
            || localStorage.getItem('mozistock-sidebar') === 'collapsed' ? 'collapsed' : 'expanded';
    } catch (_) {}
</script>
@vite(['resources/css/app.css', 'resources/js/app.js'])
@livewireStyles
