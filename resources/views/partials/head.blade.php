<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<meta name="theme-color" content="#151411">
<title>{{ isset($title) ? __($title).' · ' : '' }}{{ config('app.name') }}</title>
<link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
<script>
    try {
        const theme = localStorage.getItem('mozistock-theme') === 'light' ? 'light' : 'dark';
        document.documentElement.dataset.theme = theme;
        document.documentElement.classList.toggle('dark', theme === 'dark');
        document.documentElement.dataset.sidebar = localStorage.getItem('mozistock-sidebar') === 'collapsed' ? 'collapsed' : 'expanded';
    } catch (_) {}
</script>
@vite(['resources/css/app.css', 'resources/js/app.js'])
@livewireStyles
