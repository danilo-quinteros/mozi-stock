@use('App\Support\Decimal')
@php($zona = config('mozistock.business_timezone'))
@php($bs = fn (?string $monto): string => Decimal::formatear($monto ?? '0', 2, 2))
{{-- Representación gráfica de la factura compra-venta V3 (formato oficial del SIN), media página o rollo.
     Los importes son los de la factura validada, formateados como texto decimal (sin float). --}}
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ __('Factura :numero', ['numero' => $factura->numero]) }} · {{ $configuracion->razon_social }}</title>
    <style>
        body { margin: 0; background: #f4f1ec; color: #000; font: 12px/1.4 Arial, Helvetica, sans-serif; }
        .hoja { max-width: 800px; margin: 18px auto; background: #fff; padding: 28px 32px; border-radius: 10px; box-shadow: 0 10px 30px rgba(0,0,0,.08); }
        .rollo .hoja { max-width: 300px; padding: 14px; font-size: 11px; }
        .cabecera { display: flex; justify-content: space-between; gap: 18px; }
        .rollo .cabecera { display: block; text-align: center; }
        .emisor b { font-size: 13px; }
        .fiscal { display: grid; grid-template-columns: auto auto; gap: 2px 10px; align-content: start; font-size: 11.5px; }
        .rollo .fiscal { display: block; margin-top: 8px; }
        .fiscal span { font-weight: bold; }
        .cuf { word-break: break-all; max-width: 260px; }
        h1 { text-align: center; font-size: 17px; margin: 18px 0 0; letter-spacing: .04em; }
        .subtitulo { text-align: center; margin: 0 0 14px; }
        .comprador { display: grid; grid-template-columns: 1fr 1fr; gap: 3px 18px; margin-bottom: 10px; }
        .rollo .comprador { display: block; }
        table { width: 100%; border-collapse: collapse; margin-top: 6px; }
        th, td { border: 1px solid #000; padding: 4px 5px; vertical-align: top; }
        th { font-size: 10px; text-align: center; background: #f2f2f2; }
        .rollo th, .rollo td { border: 0; border-bottom: 1px dashed #000; padding: 3px 0; }
        .der { text-align: right; white-space: nowrap; }
        .totales td { border: 0; padding: 2px 5px; }
        .totales .etq { text-align: right; font-weight: bold; }
        .son { margin: 10px 0; }
        .pie { display: flex; gap: 18px; align-items: center; margin-top: 14px; }
        .rollo .pie { display: block; text-align: center; }
        .leyendas { flex: 1; text-align: center; font-size: 10.5px; }
        .leyendas p { margin: 0 0 7px; }
        .qr svg { width: 150px; height: 150px; display: block; }
        .rollo .qr svg { width: 130px; height: 130px; margin: 8px auto; }
        .acciones { max-width: 800px; margin: 18px auto 0; display: flex; flex-wrap: wrap; gap: 10px; justify-content: flex-end; font-size: 14px; }
        .acciones a, .acciones button { display: inline-flex; align-items: center; gap: 7px; font: inherit; padding: 9px 16px; border-radius: 9px; border: 1px solid #d5c9bb; background: #fff; color: #211b16; text-decoration: none; cursor: pointer; }
        .acciones button { background: #df8341; border-color: #cf742f; color: #1a120b; font-weight: bold; }
        .ico { width: 16px; height: 16px; stroke: currentColor; stroke-width: 1.8; fill: none; stroke-linecap: round; stroke-linejoin: round; }
        @media (max-width: 640px) { .hoja { margin: 10px; padding: 16px; } .cabecera, .comprador, .pie { display: block; } }
        @media print { body { background: #fff; } .acciones { display: none; } .hoja { box-shadow: none; margin: 0; max-width: none; padding: 0; border-radius: 0; } }
    </style>
    @if (request()->boolean('autoprint'))
        <script>
            window.addEventListener('load', () => {
                setTimeout(() => {
                    window.focus();
                    window.print();
                }, 100);
            });
        </script>
    @endif
</head>
<body @class(['rollo' => $rollo])>
    <div class="acciones">
        <a href="{{ route('ventas.ver', $venta) }}"><x-icon name="arrow-left" /> {{ __('Volver a la venta') }}</a>
        <a href="{{ route('facturas.imprimir', ['factura' => $factura, 'formato' => $rollo ? 'media' : 'rollo']) }}">{{ $rollo ? __('Media página') : __('Rollo') }}</a>
        <a href="{{ $enlace }}" target="_blank" rel="noopener noreferrer">{{ __('Ver en el SIN') }}</a>
        <button type="button" onclick="window.print()"><x-icon name="print" /> {{ __('Imprimir o guardar PDF') }}</button>
    </div>
    {{-- Documento fiscal: siempre en español (formato del SIN), sin importar el idioma de la interfaz. --}}
    <main class="hoja" lang="es">
        <div class="cabecera">
            <div class="emisor">
                <b>{{ $configuracion->razon_social }}</b><br>
                {{ $sucursal->codigo === 0 ? 'CASA MATRIZ' : 'Sucursal No. '.$sucursal->codigo }}<br>
                No. Punto de Venta {{ $punto->codigo }}<br>
                {{ $cufd->direccion }}<br>
                @if ($sucursal->telefono)Teléfono: {{ $sucursal->telefono }}<br>@endif
                {{ $sucursal->municipio }}
            </div>
            <div class="fiscal">
                <span>NIT</span><div>{{ $configuracion->nit }}</div>
                <span>FACTURA N°</span><div>{{ $factura->numero }}</div>
                <span>CÓD. AUTORIZACIÓN</span><div class="cuf">{{ $factura->cuf }}<br>{{ $cufd->codigo_interno }}</div>
            </div>
        </div>

        <h1>FACTURA</h1>
        <p class="subtitulo">(Con Derecho a Crédito Fiscal)</p>

        <div class="comprador">
            <div><b>Fecha:</b> {{ $factura->fecha_emision?->timezone($zona)->format('d/m/Y h:i A') }}</div>
            <div><b>NIT/CI/CEX:</b> {{ $factura->numero_documento }}</div>
            <div><b>Nombre/Razón Social:</b> {{ $factura->razon_social }}</div>
            <div><b>Complemento:</b> {{ $factura->complemento ?? '' }}</div>
            <div><b>Cod. Cliente:</b> {{ $factura->codigo_cliente }}</div>
        </div>

        <table>
            <thead>
                <tr>
                    @if ($rollo)
                        <th class="der">{{ __('C.') }}</th>
                        <th>{{ __('DESCRIPCIÓN') }}</th>
                        <th class="der">{{ __('PRECIO') }}</th>
                        <th class="der">{{ __('DESC.') }}</th>
                        <th class="der">{{ __('SUBTOTAL') }}</th>
                    @else
                        <th class="der">{{ __('CANTIDAD') }}</th>
                        <th>{{ __('DESCRIPCIÓN') }}</th>
                        <th>{{ __('CÓDIGO') }}</th>
                        <th class="der">{{ __('PRECIO') }}</th>
                        <th class="der">{{ __('DESCUENTO') }}</th>
                        <th class="der">{{ __('SUBTOTAL') }}</th>
                    @endif
                </tr>
            </thead>
            <tbody>
                @foreach ($factura->lineas ?? [] as $linea)
                    <tr>
                        <td class="der">{{ $bs($linea['cantidad']) }}</td>
                        <td>{{ $linea['descripcion'] }}</td>
                        @unless ($rollo)
                            <td>{{ $linea['codigo_producto'] }}</td>
                        @endunless
                        <td class="der">{{ $bs($linea['precio_neto_unitario']) }}</td>
                        <td class="der">{{ $bs($linea['monto_descuento']) }}</td>
                        <td class="der">{{ $bs($linea['sub_total']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <table class="totales">
            <tr><td class="etq">SUBTOTAL Bs</td><td class="der">{{ $bs($factura->sub_total_neto) }}</td></tr>
            <tr><td class="etq">MONTO GIFT CARD Bs</td><td class="der">{{ $bs('0.00') }}</td></tr>
            <tr><td class="etq">DESCUENTO Bs</td><td class="der">{{ $bs($factura->descuento_adicional) }}</td></tr>
            <tr><td class="etq">TOTAL PRECIO NETO Bs</td><td class="der">{{ $bs($factura->total_precio_neto) }}</td></tr>
            <tr><td class="etq">IVA Bs</td><td class="der">{{ $bs($factura->iva) }}</td></tr>
            <tr><td class="etq">MONTO A PAGAR Bs</td><td class="der"><b>{{ $bs($factura->total) }}</b></td></tr>
        </table>

        <p class="son"><b>Son:</b> {{ $enLetras }}</p>

        <div class="pie">
            <div class="leyendas">
                <p>ESTA FACTURA CONTRIBUYE AL DESARROLLO DEL PAÍS, EL USO ILÍCITO SERÁ SANCIONADO PENALMENTE DE ACUERDO A LEY</p>
                <p>{{ $factura->leyenda }}</p>
                <p>“Este documento es la Representación Gráfica de un Documento Fiscal Digital emitido en una modalidad de facturación en línea”</p>
            </div>
            <div class="qr" aria-label="Código QR de consulta en el SIN">{!! $qr !!}</div>
        </div>
    </main>
</body>
</html>
