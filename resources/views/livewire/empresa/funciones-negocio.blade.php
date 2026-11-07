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
            @endforeach
        </fieldset>
        @error('capacidades')<p class="auth-error" id="capacidades-error" role="alert">{{ $message }}</p>@enderror
        @error('capacidades.*')<p class="auth-error" role="alert">{{ $message }}</p>@enderror

        <div class="funciones-pie">
            <p class="subtle">{{ __('Las funciones marcadas como «Próximamente» quedan guardadas y se encienden solas cuando estén disponibles.') }}</p>
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
                @if ($activan === [] && $desactivan === [])
                    <div class="list-line"><span class="subtle">{{ __('Funciones') }}</span><b>{{ __('Sin cambios') }}</b></div>
                @endif
            </div>
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
