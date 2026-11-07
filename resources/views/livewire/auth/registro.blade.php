@php
    $nombresPasos = [1 => __('Tus datos'), 2 => __('Tu negocio'), 3 => __('Tu plan')];
    $titulos = [
        1 => [__('Crea tu cuenta'), __('Empecemos por ti. Con este correo y contraseña entrarás al sistema.')],
        2 => [__('Cuéntanos de tu negocio'), __('Con esto preparamos tu empresa, tu sucursal principal y tu caja.')],
        3 => [__('Elige tu plan'), __('Los primeros :dias días son gratis con cualquier plan. Podrás cambiarlo después.', ['dias' => $dias])],
    ];
@endphp
<div>
    <ol class="registro-pasos" aria-label="{{ __('Progreso del registro') }}">
        @foreach ($nombresPasos as $numero => $nombre)
            <li @class(['is-actual' => $paso === $numero, 'is-hecho' => $paso > $numero]) @if ($paso === $numero) aria-current="step" @endif>
                @if ($paso > $numero)
                    <button type="button" wire:click="irA({{ $numero }})" aria-label="{{ __('Volver a :paso', ['paso' => $nombre]) }}">
                        <span class="registro-num"><x-icon name="check" /></span><span>{{ $nombre }}</span>
                    </button>
                @else
                    <span class="registro-num">{{ $numero }}</span><span>{{ $nombre }}</span>
                @endif
            </li>
        @endforeach
    </ol>
    <div class="registro-barra" role="progressbar" aria-valuemin="1" aria-valuemax="{{ $this::PASOS }}" aria-valuenow="{{ $paso }}"
        aria-valuetext="{{ __('Paso :n de :total', ['n' => $paso, 'total' => $this::PASOS]) }}">
        <span style="width: {{ intdiv($paso * 100, $this::PASOS) }}%"></span>
    </div>

    <p class="registro-paso-texto">{{ __('Paso :n de :total', ['n' => $paso, 'total' => $this::PASOS]) }}</p>
    <h1>{{ $titulos[$paso][0] }}<span class="accent-text">.</span></h1>
    <p class="lead" style="margin-bottom:24px">{{ $titulos[$paso][1] }}</p>

    <form wire:submit="avanzar" class="stack" novalidate>
        <div class="registro-trampa" aria-hidden="true">
            <label for="sitio_web">{{ __('No llenar este campo') }}</label>
            <input id="sitio_web" type="text" wire:model="sitio_web" tabindex="-1" autocomplete="off">
        </div>

        @if ($paso === 1)
            <div class="field">
                <label class="field-label" for="name">{{ __('Tu nombre y apellido') }}</label>
                <input id="name" class="input" type="text" wire:model="name" autocomplete="name" maxlength="120" autofocus required
                    placeholder="{{ __('Ej. María Quispe') }}" @error('name') aria-invalid="true" aria-describedby="name-error" @enderror>
                @error('name')<p class="auth-error" id="name-error" role="alert">{{ $message }}</p>@enderror
            </div>
            <div class="field">
                <label class="field-label" for="email">{{ __('Correo electrónico') }}</label>
                <input id="email" class="input" type="email" wire:model="email" autocomplete="email" maxlength="255" required inputmode="email"
                    placeholder="{{ __('tucorreo@ejemplo.com') }}" @error('email') aria-invalid="true" aria-describedby="email-error" @enderror>
                @error('email')
                    <p class="auth-error" id="email-error" role="alert">{{ $message }}</p>
                @else
                    <p class="registro-ayuda">{{ __('Te enviaremos un enlace para confirmarlo.') }}</p>
                @enderror
            </div>
            <div class="field" x-data="{ ver: false }">
                <label class="field-label" for="password">{{ __('Contraseña') }}</label>
                <div class="registro-clave">
                    <input id="password" class="input" :type="ver ? 'text' : 'password'" type="password" wire:model="password" autocomplete="new-password" required
                        @error('password') aria-invalid="true" aria-describedby="password-error" @enderror>
                    <button type="button" x-on:click="ver = !ver" :aria-label="ver ? '{{ __('Ocultar contraseña') }}' : '{{ __('Mostrar contraseña') }}'" aria-label="{{ __('Mostrar contraseña') }}">
                        <x-icon name="eye" />
                    </button>
                </div>
                @error('password')<p class="auth-error" id="password-error" role="alert">{{ $message }}</p>@enderror
                <p class="registro-ayuda">{{ $requisitosClave }}</p>
            </div>
        @elseif ($paso === 2)
            <div class="field">
                <label class="field-label" for="empresa">{{ __('Nombre de tu negocio') }}</label>
                <input id="empresa" class="input" type="text" wire:model="empresa" autocomplete="organization" maxlength="150" required x-init="$nextTick(() => $el.focus())"
                    placeholder="{{ __('Ej. Ferretería El Constructor') }}" @error('empresa') aria-invalid="true" aria-describedby="empresa-error" @enderror>
                @error('empresa')<p class="auth-error" id="empresa-error" role="alert">{{ $message }}</p>@enderror
            </div>
            <fieldset class="registro-rubros" @error('rubro') aria-describedby="rubro-error" @enderror>
                <legend class="field-label">{{ __('¿Qué tipo de negocio tienes?') }}</legend>
                @foreach ($rubros as $opcion)
                    <label class="registro-plan" wire:key="rubro-{{ $opcion->value }}">
                        <input type="radio" name="rubro" value="{{ $opcion->value }}" wire:model="rubro" data-test="rubro-{{ $opcion->value }}">
                        <span class="registro-radio" aria-hidden="true"></span>
                        <span class="registro-plan-texto">
                            <b>{{ $opcion->etiqueta() }}</b>
                            <small>{{ $opcion->descripcion() }}</small>
                        </span>
                    </label>
                @endforeach
                @error('rubro')<p class="auth-error" id="rubro-error" role="alert">{{ $message }}</p>@enderror
                <p class="registro-ayuda">{{ __('Así preparamos el sistema para tu tipo de negocio. Puedes cambiarlo después en «Mi empresa».') }}</p>
            </fieldset>
            <div class="registro-dos">
                <div class="field">
                    <label class="field-label" for="telefono">{{ __('Celular o WhatsApp') }}</label>
                    <input id="telefono" class="input" type="tel" wire:model="telefono" autocomplete="tel" inputmode="tel" maxlength="20" required
                        placeholder="{{ __('Ej. 71234567') }}" @error('telefono') aria-invalid="true" aria-describedby="telefono-error" @enderror>
                    @error('telefono')<p class="auth-error" id="telefono-error" role="alert">{{ $message }}</p>@enderror
                </div>
                <div class="field">
                    <label class="field-label" for="nit">{{ __('NIT') }} <span class="registro-ayuda" style="display:inline">({{ __('opcional') }})</span></label>
                    <input id="nit" class="input" type="text" wire:model="nit" inputmode="numeric" maxlength="20"
                        @error('nit') aria-invalid="true" aria-describedby="nit-error" @enderror>
                    @error('nit')<p class="auth-error" id="nit-error" role="alert">{{ $message }}</p>@enderror
                </div>
            </div>
            <p class="registro-ayuda" style="margin-top:-6px">{{ __('El NIT solo hace falta para facturar con el SIAT. Puedes agregarlo después en «Mi empresa».') }}</p>
        @else
            <fieldset class="registro-planes" style="border:0;margin:0;padding:0">
                <legend class="sr-only">{{ __('Plan') }}</legend>
                @foreach ($planes as $opcion)
                    <label class="registro-plan">
                        <input type="radio" name="plan" value="{{ $opcion['codigo'] }}" wire:model.live="plan">
                        <span class="registro-radio" aria-hidden="true"></span>
                        <span class="registro-plan-texto">
                            <b>{{ $opcion['nombre'] }}</b>
                            <small>{{ $opcion['siat'] ? __('Inventario, ventas, caja, reportes y facturación electrónica SIAT.') : __('Inventario, ventas, caja, créditos y reportes, sin facturación SIAT.') }}</small>
                        </span>
                        <span class="registro-plan-precio">Bs {{ $opcion['entero'] }}@if ($opcion['centavos'] !== '00'),{{ $opcion['centavos'] }}@endif<small>{{ __('al mes, después de la prueba') }}</small></span>
                    </label>
                @endforeach
            </fieldset>
            @error('plan')<p class="auth-error" role="alert">{{ $message }}</p>@enderror

            <div class="registro-resumen">
                <div><span>{{ __('Hoy pagas') }}</span><b>Bs 0</b></div>
                <div><span>{{ __('Prueba gratis hasta') }}</span><b>{{ $finPrueba->translatedFormat('j \d\e F \d\e Y') }}</b></div>
                @if ($elegido)
                    <div><span>{{ __('Si decides continuar') }}</span><b>Bs {{ $elegido['entero'] }}@if ($elegido['centavos'] !== '00'),{{ $elegido['centavos'] }}@endif {{ __('al mes') }}</b></div>
                @endif
            </div>

            <label class="registro-acepto">
                <input type="checkbox" wire:model="acepta" @error('acepta') aria-invalid="true" aria-describedby="acepta-error" @enderror>
                <span>{!! __('Acepto los :terminos y la :privacidad de MoziStock.', [
                    'terminos' => '<a class="link" href="'.e(route('sitio.terminos')).'" target="_blank" rel="noopener">'.e(__('términos y condiciones')).'</a>',
                    'privacidad' => '<a class="link" href="'.e(route('sitio.privacidad')).'" target="_blank" rel="noopener">'.e(__('política de privacidad')).'</a>',
                ]) !!}</span>
            </label>
            @error('acepta')<p class="auth-error" id="acepta-error" role="alert">{{ $message }}</p>@enderror
        @endif

        <div class="registro-acciones">
            @if ($paso > 1)
                <button type="button" class="btn ghost" wire:click="volver" wire:loading.attr="disabled"><x-icon name="arrow-left" size="sm" /> {{ __('Atrás') }}</button>
            @endif
            <button type="submit" class="btn primary" wire:loading.attr="disabled" wire:target="avanzar" data-test="registro-continuar">
                <span wire:loading.remove wire:target="avanzar">{{ $paso < $this::PASOS ? __('Continuar') : __('Crear mi cuenta gratis') }}</span>
                <span wire:loading wire:target="avanzar">{{ $paso < $this::PASOS ? __('Revisando…') : __('Creando tu cuenta…') }}</span>
                <x-icon name="arrow" size="sm" wire:loading.remove wire:target="avanzar" />
            </button>
        </div>
    </form>
</div>
