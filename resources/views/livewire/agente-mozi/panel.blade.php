@use('App\Livewire\AgenteMozi\Panel')
@use('App\Models\AgenteMensaje')
@use('Illuminate\Support\Str')
@assets
@vite(['resources/css/agente-mozi.css', 'resources/js/agente-mozi.js'])
@endassets
@php($zona = config('mozistock.business_timezone'))
{{-- Escritorio: chat (izquierda) + Lienzo (derecha). Móvil: solo chat; los botones «Ver …» abren la vista Lienzo. --}}
<div class="agente" x-data="agenteMoziChat" :data-vista="vista" :class="{ 'lienzo-ampliado': ampliado }"
    data-limite="{{ config('agente_mozi.limites.caracteres_mensaje') }}"
    @agente-mozi-lienzo.window="recibirLienzo($event.detail)"
    @agente-mozi-ver.window="verArtefacto($event.detail)"
    @popstate.window="alVolverHistorial($event)">

    <section class="agente-chat" aria-label="{{ __('Conversación con Agente Mozi') }}">
        <header class="agente-chat-head">
            <div class="agente-identidad">
                <span class="agente-marca" aria-hidden="true"><x-icon name="sparkles" /></span>
                <div class="agente-identidad-texto">
                    <h1>{{ __('Agente Mozi') }}</h1>
                    <small>{{ $empresa }} · {{ $sucursal }}</small>
                </div>
            </div>
            <div class="agente-acciones">
                <button type="button" class="icon-action" wire:click="nueva" title="{{ __('Nueva charla') }}" aria-label="{{ __('Nueva charla') }}"><x-icon name="plus" size="sm" /></button>
                <button type="button" class="icon-action" @click="historialAbierto = ! historialAbierto" :aria-expanded="historialAbierto" aria-controls="agente-historial" title="{{ __('Historial') }}" aria-label="{{ __('Historial') }}"><x-icon name="history" size="sm" /></button>
            </div>
        </header>
        <p class="agente-aviso" role="note"><span class="agente-aviso-punto" aria-hidden="true"></span>{{ $conIa
            ? __('IA en pruebas: el asistente consulta los datos reales de tu empresa. Revisa las cifras importantes antes de decidir.')
            : __('Modo demostración: las respuestas usan datos sintéticos, no los de tu empresa.') }}</p>

        <div class="agente-historial" id="agente-historial" x-show="historialAbierto" x-transition.opacity.duration.150ms x-cloak
            @click.outside="historialAbierto = false" @keydown.escape.stop="historialAbierto = false">
            <div class="agente-historial-head"><b>{{ __('Tus charlas') }}</b><small>{{ __('Privadas: solo tú las ves') }}</small></div>
            <div class="agente-historial-lista">
                @forelse ($historial as $charla)
                    <button type="button" wire:key="h{{ $charla->id }}" class="agente-historial-item {{ $charla->id === $conversacionId ? 'active' : '' }}"
                        wire:click="abrir({{ $charla->id }})" @click="historialAbierto = false">
                        <span>{{ $charla->titulo }}</span>
                        <small>{{ $charla->ultima_actividad_en->timezone($zona)->format('d/m/Y H:i') }}</small>
                    </button>
                @empty
                    <p class="agente-historial-vacio">{{ __('Todavía no tienes charlas.') }}</p>
                @endforelse
            </div>
        </div>

        <div class="agente-mensajes" x-ref="mensajes">
            @if ($mensajes->isEmpty())
                <div class="agente-bienvenida" x-show="! enviando">
                    <span class="agente-orbe" aria-hidden="true"><x-icon name="sparkles" /></span>
                    <h2>{{ __('¿Qué quieres descubrir de tu negocio hoy?') }}</h2>
                    <p>{{ __('Pregunta en lenguaje natural. Te respondo aquí y dibujo indicadores, gráficos y tablas en el Lienzo.') }}</p>
                    <div class="agente-sugerencias">
                        @foreach ($sugerencias as $sugerencia)
                            <button type="button" class="agente-sugerencia" @click="usarSugerencia(@js($sugerencia))"><x-icon name="arrow" size="sm" /><span>{{ $sugerencia }}</span></button>
                        @endforeach
                    </div>
                </div>
            @endif

            <div class="agente-hilo" aria-live="polite">
                @foreach ($mensajes as $mensaje)
                    @if ($mensaje->esDelAgente())
                        @php($botones = Panel::botones($mensaje))
                        <article class="agente-msg ia {{ $mensaje->estado === AgenteMensaje::FALLIDO ? 'fallido' : '' }}" wire:key="m{{ $mensaje->id }}">
                            <span class="agente-avatar" aria-hidden="true"><x-icon name="sparkles" size="sm" /></span>
                            <div class="agente-burbuja">
                                <div class="agente-md">{!! Str::markdown($mensaje->texto, ['html_input' => 'escape', 'allow_unsafe_links' => false]) !!}</div>
                                @if ($botones !== [])
                                    <div class="agente-ver {{ count($botones) > 1 ? 'varios' : '' }}" x-data="{ abierto: false }">
                                        @if (count($botones) > 1)
                                            <button type="button" class="btn soft sm agente-ver-resumen" @click="abierto = ! abierto" :aria-expanded="abierto">
                                                <x-icon name="eye" size="sm" /> {{ __('Ver resultados (:n)', ['n' => $mensaje->artefactos->count()]) }}
                                            </button>
                                        @endif
                                        <div class="agente-ver-lista" :class="{ abierto }">
                                            @foreach ($botones as $boton)
                                                <button type="button" class="btn soft sm" @click="$dispatch('agente-mozi-ver', { mensaje: {{ $mensaje->id }}, artefacto: {{ $boton['artefacto'] }} })">
                                                    <x-icon :name="$boton['icono']" size="sm" /> {{ $boton['etiqueta'] }}@if ($boton['cantidad'] > 1) ({{ $boton['cantidad'] }})@endif
                                                </button>
                                            @endforeach
                                        </div>
                                    </div>
                                @endif
                                <div class="agente-msg-meta">
                                    @if ($mensaje->proveedor === 'demo')<span class="badge warning">{{ __('Demo') }}</span>@elseif ($mensaje->proveedor !== null)<span class="badge info">{{ __('IA') }}</span>@endif
                                    <time datetime="{{ $mensaje->created_at->toIso8601String() }}">{{ $mensaje->created_at->timezone($zona)->format('H:i') }}</time>
                                </div>
                            </div>
                        </article>
                    @else
                        <article class="agente-msg usuario" wire:key="m{{ $mensaje->id }}"><div class="agente-burbuja">{{ $mensaje->texto }}</div></article>
                    @endif
                @endforeach

                <template x-if="enviando">
                    <article class="agente-msg usuario pendiente"><div class="agente-burbuja" x-text="enviando"></div></article>
                </template>
                <div class="agente-pensando" x-show="enviando" x-cloak role="status">
                    <span class="agente-avatar" aria-hidden="true"><x-icon name="sparkles" size="sm" /></span>
                    <span class="agente-puntos" aria-hidden="true"><i></i><i></i><i></i></span>
                    <span wire:stream="agente-fase">{{ __('Analizando tu consulta…') }}</span>
                </div>
            </div>
        </div>

        <form class="agente-compositor" @submit.prevent="enviar()">
            @error('texto')<p class="agente-error" role="alert"><x-icon name="info" size="sm" /> {{ $message }}</p>@enderror
            <label for="agente-texto" class="agente-sr">{{ __('Escribe tu pregunta para Agente Mozi') }}</label>
            <div class="agente-caja">
                <textarea id="agente-texto" x-ref="texto" x-model="borrador" rows="1" maxlength="{{ config('agente_mozi.limites.caracteres_mensaje') }}"
                    placeholder="{{ __('Pregúntale algo a Mozi…') }}" autocomplete="off"
                    @keydown.enter="teclaEnter($event)" @input="ajustarAltura()" :readonly="!! enviando"></textarea>
                <button type="submit" class="agente-enviar" :disabled="! borrador.trim() || !! enviando" aria-label="{{ __('Enviar') }}" title="{{ __('Enviar') }}"><x-icon name="send" /></button>
            </div>
            <div class="agente-compositor-pie">
                <span class="agente-atajo">{{ __('Enter para enviar · Shift+Enter para nueva línea') }}</span>
                <span x-show="borrador.length > limite * 0.8" x-cloak x-text="borrador.length + ' / ' + limite"></span>
            </div>
        </form>
    </section>

    <div class="agente-separador" role="separator" tabindex="0" aria-orientation="vertical" aria-controls="agente-lienzo"
        aria-label="{{ __('Ajustar el ancho del chat') }}" aria-valuemin="320" aria-valuemax="560" :aria-valuenow="anchoChat"
        @pointerdown="arrastrar($event)" @keydown.left.prevent="ajustarAncho(-24)" @keydown.right.prevent="ajustarAncho(24)"></div>

    <section class="agente-lienzo" id="agente-lienzo" aria-label="{{ __('Lienzo') }}">
        <div class="agente-lienzo-movil">
            <button type="button" class="btn ghost sm" @click="volverAlChat()"><x-icon name="arrow-left" size="sm" /> {{ __('Volver al chat') }}</button>
        </div>
        <div class="lienzo" x-ref="lienzo" wire:ignore tabindex="-1" data-sandbox-altura="{{ config('agente_mozi.limites.sandbox_altura_max') }}">
            <script type="application/json" data-lienzo-datos>@json($lienzo)</script>
            <script type="application/json" data-lienzo-textos>@json($textos)</script>
        </div>
    </section>
</div>
