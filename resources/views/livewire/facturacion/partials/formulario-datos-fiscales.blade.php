{{-- Datos fiscales: lo usan Facturación → Datos fiscales y el popup de la guía de configuración. --}}
<form wire:submit="guardarDatos">
    <fieldset @disabled(! $puedeConfigurar) style="border:0;padding:0;margin:0;min-width:0">
        <div class="fields">
            <x-campo etiqueta="NIT" campo="nit">
                <input id="nit" class="input" type="text" inputmode="numeric" wire:model="nit" maxlength="13" required>
            </x-campo>
            <x-campo etiqueta="Razón social" campo="razon_social">
                <input id="razon_social" class="input" type="text" wire:model="razon_social" maxlength="200" required>
            </x-campo>
            <x-campo etiqueta="Modo de facturación" campo="modo">
                <select id="modo" class="select" wire:model="modo">
                    @foreach ($modos as $opcion)
                        <option value="{{ $opcion->value }}">{{ $opcion->etiqueta() }} — {{ $opcion->descripcion() }}</option>
                    @endforeach
                </select>
            </x-campo>
            <x-campo etiqueta="Modalidad" campo="modalidad">
                <select id="modalidad" class="select" wire:model.live="modalidad">
                    @foreach ($modalidades as $opcion)
                        <option value="{{ $opcion->value }}">{{ $opcion->etiqueta() }}</option>
                    @endforeach
                </select>
            </x-campo>
            <x-campo etiqueta="Actividad económica" campo="actividad_economica">
                <select id="actividad_economica" class="select" wire:model.live="actividad_economica" @disabled($actividades->isEmpty())>
                    <option value="">{{ $actividades->isEmpty() ? __('Sincroniza los catálogos para elegirla') : __('Sin definir') }}</option>
                    @foreach ($actividades as $actividad)
                        <option value="{{ $actividad->codigo }}">{{ $actividad->codigo }} · {{ $actividad->descripcion }}</option>
                    @endforeach
                </select>
            </x-campo>
            <x-campo etiqueta="Producto SIN" campo="producto_sin">
                <select id="producto_sin" class="select" wire:model="producto_sin" @disabled($productos->isEmpty())>
                    <option value="">{{ $actividad_economica === '' ? __('Elige primero la actividad') : ($productos->isEmpty() ? __('Sin productos para esta actividad') : __('Sin definir')) }}</option>
                    @foreach ($productos as $producto)
                        <option value="{{ $producto->codigo }}">{{ $producto->codigo }} · {{ $producto->descripcion }}</option>
                    @endforeach
                </select>
            </x-campo>

            @if ($modalidad === 'electronica')
                <div style="grid-column: 1 / -1; background: var(--bg-surface-elevated, rgba(255,255,255,0.03)); border: 1px solid var(--border-color, rgba(255,255,255,0.1)); border-radius: 8px; padding: 14px 16px; margin: 6px 0;">
                    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; gap: 8px; flex-wrap: wrap;">
                        <div style="font-weight: 600; display: flex; align-items: center; gap: 8px;">
                            <x-icon name="key" /> {{ __('Certificado digital PKCS#12 (.p12) — Modalidad Electrónica en Línea') }}
                        </div>
                        @if ($configuracion?->tieneCertificado())
                            @if ($configuracion->certificadoVencido())
                                <span class="badge danger">{{ __('Certificado vencido') }}</span>
                            @elseif ($configuracion->certificadoPorVencer())
                                <span class="badge warning">{{ __('Vence pronto') }} ({{ $configuracion->certificado_vence_en?->format('d/m/Y') }})</span>
                            @else
                                <span class="badge success">{{ __('Certificado cargado') }} ({{ __('vence :fecha', ['fecha' => $configuracion->certificado_vence_en?->format('d/m/Y')]) }})</span>
                            @endif
                        @else
                            <span class="badge info">{{ __('Sin certificado') }}</span>
                        @endif
                    </div>

                    @if ($configuracion?->tieneCertificado())
                        <div style="font-size: 0.875rem; color: var(--text-muted, #a0aec0); margin-bottom: 12px;">
                            {{ __('Archivo actual:') }} <b>{{ $configuracion->certificado_nombre ?? 'certificado.p12' }}</b>
                            @if ($configuracion->certificado_vence_en)
                                · {{ __('Vence:') }} {{ $configuracion->certificado_vence_en->timezone(config('mozistock.business_timezone'))->format('d/m/Y H:i') }}
                            @endif
                        </div>
                    @endif

                    <div class="fields" style="margin: 0; padding: 0;">
                        <x-campo etiqueta="Subir archivo .p12" campo="certificado">
                            <input id="certificado" class="input" type="file" wire:model="certificado" accept=".p12,.pfx" @required(! $configuracion?->tieneCertificado())>
                            <div wire:loading wire:target="certificado" class="subtle" style="font-size:0.75rem;margin-top:4px">
                                {{ __('Cargando archivo...') }}
                            </div>
                        </x-campo>

                        <x-campo etiqueta="Contraseña del certificado" campo="certificado_pass">
                            <input id="certificado_pass" class="input" type="password" wire:model="certificado_pass" placeholder="{{ $configuracion?->tieneCertificado() ? __('Dejar en blanco para mantener la actual') : __('Contraseña del archivo .p12') }}" @required(! $configuracion?->tieneCertificado())>
                        </x-campo>
                    </div>

                    <p class="subtle" style="margin: 10px 0 0; font-size: 0.8125rem;">
                        {{ __('El archivo se almacena de forma privada (storage/app/private/certificados) y la contraseña se cifra en la base de datos con APP_KEY. La firma digital XMLDSig se generará automáticamente en cada venta facturada.') }}
                    </p>
                </div>
            @endif
        </div>
        <p class="subtle" style="margin:14px 0 0">{{ __('Actividad y producto SIN se usan por defecto en las líneas de la factura. La unidad de medida de cada producto se configura en Productos → Unidades y medidas.') }}</p>
        @if ($puedeConfigurar)
            <div class="row" style="margin-top:18px;justify-content:flex-end">
                <button class="btn primary" type="submit" wire:loading.attr="disabled" wire:target="guardarDatos"><x-icon name="check" /> {{ __('Guardar datos fiscales') }}</button>
            </div>
        @endif
    </fieldset>
</form>
