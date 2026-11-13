<div>
    <section class="card card-pad" id="funciones-negocio">
        <div class="card-head"><div class="heading"><span class="heading-icon"><x-icon name="box" /></span>{{ __('Funciones del negocio') }}</div></div>
        <p class="subtle" style="margin:-8px 0 16px;line-height:1.6">{{ __('Elige tu tipo de negocio y el sistema activa solo las funciones que necesitas. Ventas, stock, caja, créditos y reportes son los mismos para todos.') }}</p>

        <x-campo etiqueta="Rubro" campo="rubro">
            <select id="rubro" class="select" wire:model.live="rubro">
                @foreach ($rubros as $opcion)
                    <option value="{{ $opcion->value }}">{{ $opcion->etiqueta() }}</option>
                @endforeach
            </select>
        </x-campo>

        <fieldset class="funciones-lista" @error('capacidades') aria-describedby="capacidades-error" @enderror>
            <legend class="field-label">{{ __('Funciones') }}</legend>
            @foreach ($funciones as $funcion)
                @php($bloqueadaPor = $bloqueadas[$funcion->value] ?? null)
                <label @class(['funcion-linea', 'is-bloqueada' => $bloqueadaPor]) wire:key="funcion-{{ $funcion->value }}">
                    <input type="checkbox" value="{{ $funcion->value }}" wire:model.live="capacidades" @disabled($bloqueadaPor) data-test="funcion-{{ $funcion->value }}">
                    <span class="funcion-texto">
                        <b>{{ $funcion->etiqueta() }}</b>
                        @unless ($funcion->disponible())<span class="badge info">{{ __('Próximamente') }}</span>@endunless
                        <small>{{ $funcion->descripcion() }}</small>
                        @if ($bloqueadaPor)
                            <small class="funcion-aviso">{{ __('No se puede usar junto con «:otra». Desmárcala primero.', ['otra' => $bloqueadaPor->etiqueta()]) }}</small>
                        @endif
                    </span>
                </label>
                @if ($funcion === \App\Enums\Capacidad::Cocina && in_array($funcion->value, $capacidades, true))
                    {{-- Cocina § 4.4: límite para resaltar pedidos demorados en el panel En cocina. --}}
                    <div class="funcion-extra" wire:key="funcion-cocina-minutos">
                        <label for="minutos-alerta-cocina">{{ __('Avisar pedidos con demora a partir de') }}</label>
                        <input id="minutos-alerta-cocina" type="number" inputmode="numeric" min="1" max="240" step="1" class="input funcion-minutos"
                            wire:model="minutosAlertaCocina" data-test="funciones-minutos-cocina"
                            @error('minutosAlertaCocina') aria-invalid="true" aria-describedby="minutos-alerta-cocina-error" @enderror>
                        <span>{{ __('minutos') }}</span>
                    </div>
                    @error('minutosAlertaCocina')<p class="auth-error" id="minutos-alerta-cocina-error" role="alert">{{ $message }}</p>@enderror
                @endif
            @endforeach
        </fieldset>
        @error('capacidades')<p class="auth-error" id="capacidades-error" role="alert">{{ $message }}</p>@enderror
        @error('capacidades.*')<p class="auth-error" role="alert">{{ $message }}</p>@enderror
        @error('confirmacion_lotes')<p class="auth-error" role="alert">{{ $message }}</p>@enderror

        <div class="funciones-pie">
            @if (collect($funciones)->contains(fn ($f) => ! $f->disponible()))
                <p class="subtle">{{ __('Las funciones marcadas como «Próximamente» quedan guardadas y se encienden solas cuando estén disponibles.') }}</p>
            @endif
            <button class="btn primary" type="button" wire:click="revisar" wire:loading.attr="disabled" wire:target="revisar" data-test="funciones-revisar">
                <x-icon name="check" /> {{ __('Guardar funciones') }}
            </button>
        </div>
    </section>

    @if ($revisando)
        <x-modal titulo="Revisa los cambios" cerrar="cancelar" descripcion="El stock, las ventas, los pagos, la caja y los créditos no cambian.">
            <div class="funciones-resumen">
                @if ($rubroActual->value !== $rubro)
                    <div class="list-line"><span class="subtle">{{ __('Rubro') }}</span><b>{{ $rubroActual->etiqueta() }} → {{ \App\Enums\Rubro::from($rubro)->etiqueta() }}</b></div>
                @endif
                @if ($activan !== [])
                    <div class="list-line"><span class="subtle">{{ __('Se activa') }}</span><b>{{ collect($activan)->map->etiqueta()->join(', ') }}</b></div>
                @endif
                @if ($desactivan !== [])
                    <div class="list-line"><span class="subtle">{{ __('Se desactiva') }}</span><b>{{ collect($desactivan)->map->etiqueta()->join(', ') }}</b></div>
                @endif
                @php($cambiaDemora = in_array(\App\Enums\Capacidad::Cocina->value, $capacidades, true) && (int) $minutosAlertaCocina !== $minutosActuales)
                @if ($cambiaDemora)
                    <div class="list-line"><span class="subtle">{{ __('Aviso de demora en cocina') }}</span><b>{{ __(':antes → :despues min', ['antes' => $minutosActuales, 'despues' => (int) $minutosAlertaCocina]) }}</b></div>
                @endif
                @if ($activan === [] && $desactivan === [] && ! $cambiaDemora)
                    <div class="list-line"><span class="subtle">{{ __('Funciones') }}</span><b>{{ __('Sin cambios') }}</b></div>
                @endif
            </div>
            @if ($mensajeLotes)
                {{-- Rubros § 5.2: apagar lotes pide escribir DESACTIVAR. --}}
                <div class="notice caja-alerta" style="margin-top:14px" data-test="funciones-confirmar-lotes"><x-icon name="status-alert" /><div><b>{{ __('Lotes y vencimientos') }}</b><p>{{ $mensajeLotes }}</p>
                    <input class="input" type="text" autocomplete="off" wire:model="confirmacionLotes" placeholder="{{ $palabraLotes }}" aria-label="{{ __('Escribe :palabra para continuar.', ['palabra' => $palabraLotes]) }}" data-test="funciones-palabra-lotes"
                        @error('confirmacionLotes') aria-invalid="true" @enderror style="margin-top:8px">
                    @error('confirmacionLotes')<p class="auth-error" role="alert">{{ $message }}</p>@enderror
                </div></div>
            @endif
            @if (collect($activan)->contains(fn ($f) => ! $f->disponible()))
                <div class="notice" style="margin-top:14px"><x-icon name="info" /><div><b>{{ __('Función en preparación') }}</b><p>{{ __('Guardamos tu elección. Hasta que la función esté disponible, el sistema sigue funcionando como hoy.') }}</p></div></div>
            @endif
            <div class="modal-actions">
                <button type="button" class="btn ghost" wire:click="cancelar">{{ __('Cancelar') }}</button>
                <button type="button" class="btn primary" wire:click="guardar" wire:loading.attr="disabled" wire:target="guardar" data-test="funciones-confirmar">
                    <x-icon name="check" /> {{ __('Confirmar cambios') }}
                </button>
            </div>
        </x-modal>
    @endif

    <x-aviso :mensaje="$aviso" :numero="$avisoNumero" :tipo="$avisoTipo" />
</div>
