{!! '<'.'?xml version="1.0" encoding="UTF-8"?'.'>' !!}
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
@foreach ($entradas as $entrada)
    <url>
        <loc>{{ $entrada['url'] }}</loc>
        <lastmod>{{ $entrada['fecha'] }}</lastmod>
        <changefreq>{{ $entrada['frecuencia'] }}</changefreq>
        <priority>{{ $entrada['prioridad'] }}</priority>
    </url>
@endforeach
</urlset>
