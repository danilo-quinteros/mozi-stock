@props(['titulo', 'volver', 'volverEtiqueta' => 'Volver', 'empresa', 'documento', 'detalle' => null])
{{-- Hoja imprimible (o guardable como PDF desde el navegador) compartida por pedidos, créditos, compras y caja. --}}
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __($titulo) }} · {{ $empresa->nombre }}</title>
    <style>
        body { margin: 0; background: #f4f1ec; color: #211b16; font: 14px/1.5 Arial, Helvetica, sans-serif; }
        .hoja { max-width: 820px; margin: 24px auto; background: #fff; padding: 36px 40px; border-radius: 10px; box-shadow: 0 10px 30px rgba(0,0,0,.08); }
        .cabecera { display: flex; justify-content: space-between; gap: 20px; border-bottom: 2px solid #df8341; padding-bottom: 16px; }
        h1 { font-size: 24px; margin: 0 0 4px; }
        h2 { font-size: 14px; margin: 26px 0 0; text-transform: uppercase; letter-spacing: .04em; color: #6f665c; }
        .muted { color: #6f665c; font-size: 12px; }
        table { width: 100%; border-collapse: collapse; margin-top: 14px; }
        th, td { padding: 9px 8px; border-bottom: 1px solid #e3ddd4; text-align: left; font-size: 12.5px; vertical-align: top; }
        th { font-size: 11px; text-transform: uppercase; color: #6f665c; }
        tfoot td { font-weight: bold; border-top: 2px solid #211b16; border-bottom: 0; }
        .der { text-align: right; }
        .totales { margin-left: auto; width: 320px; margin-top: 16px; }
        .totales div { display: flex; justify-content: space-between; padding: 5px 0; }
        .total { font-size: 18px; font-weight: bold; border-top: 2px solid #211b16; margin-top: 6px; padding-top: 8px !important; }
        .aviso { margin-top: 26px; font-size: 11px; color: #6f665c; }
        .acciones { max-width: 820px; margin: 18px auto 0; display: flex; gap: 10px; justify-content: flex-end; }
        .acciones a, .acciones button { display: inline-flex; align-items: center; gap: 7px; font: inherit; padding: 9px 16px; border-radius: 9px; border: 1px solid #d5c9bb; background: #fff; color: #211b16; text-decoration: none; cursor: pointer; }
        .acciones button { background: #df8341; border-color: #cf742f; color: #1a120b; font-weight: bold; }
        .ico { width: 16px; height: 16px; stroke: currentColor; stroke-width: 1.8; fill: none; stroke-linecap: round; stroke-linejoin: round; }
        @media print { body { background: #fff; } .acciones { display: none; } .hoja { box-shadow: none; margin: 0; max-width: none; padding: 0; } }
    </style>
</head>
<body>
    <div class="acciones">
        <a href="{{ $volver }}"><x-icon name="arrow-left" /> {{ __($volverEtiqueta) }}</a>
        <button type="button" onclick="window.print()"><x-icon name="print" /> {{ __('Imprimir o guardar PDF') }}</button>
    </div>
    <main class="hoja">
        <div class="cabecera">
            <div>
                <h1>{{ $empresa->nombre }}</h1>
                <div class="muted">
                    @if ($empresa->nit)NIT {{ $empresa->nit }}@endif
                    @if ($empresa->telefono) {{ __('· Tel. :telefono', ['telefono' => $empresa->telefono]) }}@endif
                </div>
            </div>
            <div class="der">
                <h1>{{ __($documento) }}</h1>
                @if ($detalle)<div class="muted">{{ $detalle }}</div>@endif
            </div>
        </div>
        {{ $slot }}
    </main>
</body>
</html>
