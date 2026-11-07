<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
{{-- Panel, acceso y errores no se indexan: el sitio público tiene su propio layout (x-sitio.layout). --}}
<meta name="robots" content="noindex, nofollow">
<meta name="csrf-token" content="{{ csrf_token() }}">
<meta name="theme-color" content="#151411">
<title>{{ isset($title) ? __($title).' · ' : '' }}{{ config('app.name') }}</title>
<link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
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
