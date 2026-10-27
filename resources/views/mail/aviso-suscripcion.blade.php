<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{{ __(':nombre · MoziStock', ['nombre' => $empresa->nombre]) }}</title></head>
<body style="margin:0;padding:24px;background:#f4f1ec;font-family:Arial,Helvetica,sans-serif;color:#24211c">
    <div style="max-width:520px;margin:0 auto;background:#ffffff;border-radius:14px;padding:28px;border:1px solid #e6dfd4">
        <p style="margin:0 0 6px;font-size:12px;letter-spacing:.08em;text-transform:uppercase;color:#d9682b"><b>{{ __('MoziStock') }}</b> · {{ $receptor }}</p>
        <h1 style="margin:0 0 16px;font-size:20px">{{ $titulo }}</h1>
        <p style="margin:0 0 16px;line-height:1.6">{{ __('Hola, equipo de') }} <b>{{ $empresa->nombre }}</b>:</p>
        <table role="presentation" style="width:100%;border-collapse:collapse;margin:0 0 18px;font-size:14px">
            <tr><td style="padding:8px 0;border-bottom:1px solid #eee;color:#6b655c">{{ __('Plan mensual') }}</td><td style="padding:8px 0;border-bottom:1px solid #eee;text-align:right"><b>{{ $monto }}</b></td></tr>
            <tr><td style="padding:8px 0;color:#6b655c">{{ __('Vencimiento') }}</td><td style="padding:8px 0;text-align:right"><b>{{ $fecha }}</b></td></tr>
        </table>
        @if ($enRevision)
            <p style="margin:0 0 16px;line-height:1.6;background:#fff4e8;padding:12px;border-radius:10px">{{ __('Tenemos un comprobante tuyo en revisión. Te avisaremos en') }} <b>{{ __('Mi suscripción') }}</b> {{ __('cuando lo validemos.') }}</p>
        @else
            <p style="margin:0 0 16px;line-height:1.6">{{ __('Para pagar, ingresa a') }} <b>{{ __('Mi suscripción') }}</b>{{ __(', escanea el QR de :receptor y sube el comprobante. Lo revisaremos manualmente.', ['receptor' => $receptor]) }}</p>
        @endif
        <p style="margin:0 0 22px"><a href="{{ $enlace }}" style="display:inline-block;background:#d9682b;color:#ffffff;text-decoration:none;padding:11px 18px;border-radius:10px;font-weight:bold">{{ __('Ir a Mi suscripción') }}</a></p>
        <p style="margin:0;font-size:12px;color:#8a8378;line-height:1.6">{{ __('Recibes este mensaje porque figuras como contacto o administrador de :nombre en MoziStock.', ['nombre' => $empresa->nombre]) }}</p>
    </div>
</body>
</html>
