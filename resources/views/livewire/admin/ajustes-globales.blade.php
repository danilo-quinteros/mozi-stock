<div>
    <div class="page-head">
        <div>
            <div class="eyebrow">{{ __('Superadministración') }}</div>
            <h1>{{ __('Configuración global') }}<span class="accent-text">.</span></h1>
            <p class="lead">{{ __('Parámetros del servicio SaaS y facturación futura.') }}</p>
        </div>
        <div class="page-actions">
            <button class="btn primary" type="submit" form="ajustes-form" wire:loading.attr="disabled" wire:target="guardar,qr"><x-icon name="check" /> {{ __('Guardar configuración') }}</button>
        </div>
    </div>

    <div class="wide-left">
        <div class="stack">
            <section class="card card-pad">
                <div class="card-head"><div class="heading"><span class="heading-icon"><x-icon name="credit" /></span>{{ __('Cobros de MoziStudios') }}</div></div>
                <form id="ajustes-form" wire:submit="guardar">
                    <div class="fields">
                        <x-campo etiqueta="Nombre del receptor" campo="receptor_nombre">
                            <input id="receptor_nombre" class="input" type="text" wire:model="receptor_nombre" maxlength="150" placeholder="{{ __('MoziStudios') }}">
                        </x-campo>
                        <x-campo etiqueta="Correo de contacto" campo="correo_contacto">
                            <input id="correo_contacto" class="input" type="email" wire:model="correo_contacto" maxlength="255" placeholder="{{ __('soporte@mozistudios.com') }}">
                        </x-campo>
                        <div class="field"><span class="field-label">{{ __('Moneda') }}</span><div class="input" style="display:flex;align-items:center">{{ __('Bolivianos (Bs)') }}</div></div>
                        <div class="field"><span class="field-label">{{ __('Método activo') }}</span><div class="input" style="display:flex;align-items:center">{{ __('QR + comprobante') }}</div></div>
                    </div>
                    <p class="subtle" style="margin:10px 0 0">{{ __('Las respuestas a los correos de aviso llegan al correo de contacto.') }}</p>
                    <div class="divider"></div>
                    <div class="product-upload">
                        <label class="upload-box" for="qr">
                            @if ($qr && $qr->isPreviewable())
                                <img src="{{ $qr->temporaryUrl() }}" alt="{{ __('Vista previa del QR') }}" style="max-width:100%;max-height:160px;object-fit:contain;border-radius:9px;background:#fff">
                            @elseif ($ajustes->qr_path && ! $quitarQr)
                                <img src="{{ route('admin.ajustes.qr') }}" alt="{{ __('QR de cobro actual') }}" style="max-width:100%;max-height:160px;object-fit:contain;border-radius:9px;background:#fff">
                            @else
                                <x-icon name="qr" size="lg" style="width:32px;height:32px;color:var(--brand-bright)" />
                            @endif
                            <b>{{ __('Código QR receptor') }}</b>
                            <small wire:loading.remove wire:target="qr">{{ __('Haz clic para elegir la imagen del QR') }}</small>
                            <small wire:loading wire:target="qr">{{ __('Subiendo…') }}</small>
                            <small>{{ __('JPG, PNG o WebP · máx. 2 MB · lo ven solo los administradores de empresa') }}</small>
                        </label>
                        <input id="qr" type="file" accept="image/png,image/jpeg,image/webp" wire:model="qr" hidden>
                        @error('qr')<p class="auth-error" role="alert">{{ $message }}</p>@enderror
                        @if ($qr || ($ajustes->qr_path && ! $quitarQr))
                            <button class="btn ghost sm" type="button" wire:click="quitar">{{ __('Quitar QR') }}</button>
                        @endif
                    </div>
                    <p class="footnote">{{ __('Los medios de cobro adicionales se implementarán más adelante.') }}</p>
                </form>
            </section>
            <section class="card card-pad">
                <div class="card-head"><div class="heading"><span class="heading-icon"><x-icon name="bell" /></span>{{ __('Recordatorios automáticos') }}</div></div>
                <div class="list-line"><span>{{ __('Antes del vencimiento') }}</span><b>{{ __('5 días') }}</b></div>
                <div class="list-line"><span>{{ __('El día del vencimiento') }}</span><b>{{ __('Día 0') }}</b></div>
                <div class="list-line"><span>{{ __('Después del vencimiento') }}</span><b>{{ __('3 días') }}</b></div>
                <div class="list-line">
                    <span>{{ __('Envío por correo') }}<small style="display:block;color:var(--faint)">{{ __('Todos los días a las 08:00 (La Paz)') }}</small></span>
                    @if ($correoConfigurado)
                        <span class="badge success">{{ __('Configurado · :remitente', ['remitente' => $remitente]) }}</span>
                    @else
                        <span class="badge warning">{{ __('Sin configurar · solo panel') }}</span>
                    @endif
                </div>
                @unless ($correoConfigurado)
                    <p class="subtle" style="margin:10px 0 0">{{ __('Configura SMTP en el servidor (MAIL_MAILER, MAIL_HOST, MAIL_FROM_ADDRESS…) para enviar los avisos por correo.') }}</p>
                @endunless
            </section>
        </div>
        <div class="stack">
            <section class="card card-pad">
                <div class="card-head"><div class="heading"><span class="heading-icon"><x-icon name="shield" /></span>{{ __('Reglas de acceso') }}</div></div>
                <div class="notice"><x-icon name="info" /><div><b>{{ __('Desactivación manual obligatoria') }}</b><p>{{ __('Ninguna tarea de recordatorio, pago rechazado o fecha vencida cambiará el estado de una empresa.') }}</p></div></div>
            </section>
            <section class="card card-pad">
                <div class="card-head"><div class="heading"><span class="heading-icon"><x-icon name="settings" /></span>{{ __('Integraciones futuras') }}</div></div>
                <div class="list-line"><div><b>{{ __('Facturación SIAT Bolivia') }}</b><div class="subtle">{{ __('No implementado') }}</div></div><span class="badge info">{{ __('Futuro') }}</span></div>
                <div class="list-line"><div><b>{{ __('Instalación privada') }}</b><div class="subtle">{{ __('Mismo código Laravel, modo standalone') }}</div></div><span class="badge info">{{ __('Futuro') }}</span></div>
                <div class="list-line"><div><b>{{ __('Pasarelas electrónicas') }}</b><div class="subtle">{{ __('Además de QR y comprobante') }}</div></div><span class="badge info">{{ __('Futuro') }}</span></div>
            </section>
        </div>
    </div>

    <x-aviso :mensaje="$aviso" :numero="$avisoNumero" />
</div>
