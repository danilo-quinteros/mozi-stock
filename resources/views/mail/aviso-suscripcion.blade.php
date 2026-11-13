@php
    // Tokens del tema oscuro de MoziStock (resources/css/mozistock.css). Estilos en línea: los clientes de correo ignoran <style>.
    $fuente = "Inter,-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";
    $titulos = "'Space Grotesk',Inter,Helvetica,Arial,sans-serif";
    $mono = "'IBM Plex Mono',ui-monospace,SFMono-Regular,Consolas,monospace";
@endphp
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="dark">
<meta name="supported-color-schemes" content="dark">
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;700&amp;family=IBM+Plex+Mono:wght@400;500&amp;display=swap" rel="stylesheet">
<style>:root{color-scheme:dark;supported-color-schemes:dark}@media only screen and (max-width:600px){.mz-card{width:100%!important}.mz-pad{padding:24px!important}}</style>
<title>{{ __(':nombre · MoziStock', ['nombre' => $empresa->nombre]) }}</title>
</head>
<body style="margin:0;padding:0;background-color:#151411;font-family:{{ $fuente }};color:#e9e1d6">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#151411">
<tr><td align="center" style="padding:32px 14px">
    <table role="presentation" class="mz-card" width="560" cellpadding="0" cellspacing="0" style="width:560px;max-width:100%">
        <tr><td align="center" style="padding:0 0 22px">
            <a href="{{ $enlace }}" style="display:inline-block"><img src="{{ asset('img/marca/logo-mozistock-correo.png') }}" width="243" height="64" alt="MoziStock by MoziStudios" style="display:block;border:0;width:243px;height:64px"></a>
        </td></tr>
        <tr><td class="mz-pad" style="background-color:#211e19;border:1px solid #3b352d;border-top:3px solid #df8341;border-radius:18px;padding:32px 34px">
            <p style="margin:0 0 10px;font-family:{{ $mono }};font-size:11px;letter-spacing:.14em;text-transform:uppercase;color:#ffa456">{{ __('Mi suscripción') }} · {{ $receptor }}</p>
            <h1 style="margin:0 0 18px;font-family:{{ $titulos }};font-size:24px;line-height:1.25;letter-spacing:-.02em;color:#fff9ef">{{ $titulo }}</h1>
            <p style="margin:0 0 20px;font-size:15px;line-height:1.6;color:#e9e1d6">{{ __('Hola, equipo de') }} <b style="color:#fff9ef">{{ $empresa->nombre }}</b>:</p>

            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 22px;background-color:#29251f;border:1px solid #302b25;border-radius:12px">
                <tr>
                    <td style="padding:14px 16px;border-bottom:1px solid #302b25;font-family:{{ $mono }};font-size:11px;letter-spacing:.06em;text-transform:uppercase;color:#aaa198">{{ __('Plan mensual') }}</td>
                    <td align="right" style="padding:14px 16px;border-bottom:1px solid #302b25;font-family:{{ $mono }};font-size:15px;font-weight:600;color:#fff9ef">{{ $monto }}</td>
                </tr>
                <tr>
                    <td style="padding:14px 16px;font-family:{{ $mono }};font-size:11px;letter-spacing:.06em;text-transform:uppercase;color:#aaa198">{{ __('Vencimiento') }}</td>
                    <td align="right" style="padding:14px 16px;font-family:{{ $mono }};font-size:15px;font-weight:600;color:#ffa456">{{ $fecha }}</td>
                </tr>
            </table>

            @if ($enRevision)
                <p style="margin:0 0 22px;font-size:14px;line-height:1.6;color:#e9e1d6;background-color:#2e2219;border:1px solid #6b4426;border-radius:12px;padding:14px 16px">{{ __('Tenemos un comprobante tuyo en revisión. Te avisaremos en') }} <b style="color:#fff9ef">{{ __('Mi suscripción') }}</b> {{ __('cuando lo validemos.') }}</p>
            @else
                <p style="margin:0 0 22px;font-size:15px;line-height:1.6;color:#e9e1d6">{{ __('Para pagar, ingresa a') }} <b style="color:#fff9ef">{{ __('Mi suscripción') }}</b>{{ __(', escanea el QR de :receptor y sube el comprobante. Lo revisaremos manualmente.', ['receptor' => $receptor]) }}</p>
            @endif

            <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 4px"><tr>
                <td style="border-radius:11px;background-color:#df8341">
                    <a href="{{ $enlace }}" style="display:inline-block;padding:12px 22px;border-radius:11px;background-color:#df8341;color:#231409;font-family:{{ $fuente }};font-size:14px;font-weight:bold;text-decoration:none">{{ __('Ir a Mi suscripción') }}</a>
                </td>
            </tr></table>
        </td></tr>
        <tr><td align="center" style="padding:22px 20px 0">
            <p style="margin:0 0 8px;font-size:12px;line-height:1.6;color:#aaa198">{{ __('Recibes este mensaje porque figuras como contacto o administrador de :nombre en MoziStock.', ['nombre' => $empresa->nombre]) }}</p>
            <p style="margin:0;font-family:{{ $mono }};font-size:11px;letter-spacing:.04em;color:#777067">© {{ date('Y') }} MoziStock · by MoziStudios</p>
        </td></tr>
    </table>
</td></tr>
</table>
</body>
</html>
