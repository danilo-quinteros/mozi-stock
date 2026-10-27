{{-- Selector de idioma de la interfaz; se envía sin JS con el botón de respaldo. --}}
<form method="POST" action="{{ route('idioma') }}" class="lang-form">@csrf
    <label class="top-icon lang-toggle"><x-icon name="globe" />
        <select name="idioma" aria-label="{{ __('Idioma') }}" onchange="this.form.submit()">
            @foreach (config('mozistock.idiomas') as $codigo => $nombre)
                <option value="{{ $codigo }}" @selected($codigo === app()->getLocale())>{{ $nombre }}</option>
            @endforeach
        </select>
    </label>
    <noscript><button class="btn ghost sm" type="submit">OK</button></noscript>
</form>
