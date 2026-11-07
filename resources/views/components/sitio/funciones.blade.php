{{-- Rejilla de funciones: cada elemento es [icono, título, texto]. --}}
@props(['items', 'columnas' => 3])
<div @class(['features', 'features-2' => $columnas === 2, 'features-4' => $columnas === 4])>
    @foreach ($items as $i => [$icono, $titulo, $texto])
        <article class="feature reveal" @if ($i % 3) style="--d:{{ ($i % 3) * 80 }}ms" @endif>
            <span class="module-icon"><x-sitio.i :n="$icono" /></span>
            <h3>{{ $titulo }}</h3>
            <p>{{ $texto }}</p>
        </article>
    @endforeach
</div>
