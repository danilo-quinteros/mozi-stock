// Agente Mozi (docs/AGENTE_MOZI_IA.md § 3): estado visual del chat y del Lienzo. Las preguntas viajan a
// Livewire con una clave idempotente; respuestas, permisos y datos los resuelve siempre el servidor.
import { Lienzo } from './agente-mozi/lienzo.js';

const CLAVE_ANCHO = 'mozistock-agente-chat';
const ANCHO_MIN = 320;
const ANCHO_MAX = 560;
const movil = window.matchMedia('(max-width: 760px)');

const acotar = ancho => Math.min(ANCHO_MAX, Math.max(ANCHO_MIN, Math.round(ancho)));

// crypto.randomUUID solo existe en contextos seguros; en http de red local se arma un UUID v4 equivalente.
function uuid() {
    if (typeof crypto.randomUUID === 'function' && window.isSecureContext) {
        return crypto.randomUUID();
    }

    const b = crypto.getRandomValues(new Uint8Array(16));
    b[6] = (b[6] & 0x0f) | 0x40;
    b[8] = (b[8] & 0x3f) | 0x80;
    const h = Array.from(b, x => x.toString(16).padStart(2, '0')).join('');

    return `${h.slice(0, 8)}-${h.slice(8, 12)}-${h.slice(12, 16)}-${h.slice(16, 20)}-${h.slice(20)}`;
}

function registrar(Alpine) {
    Alpine.data('agenteMoziChat', () => {
        // Fuera del estado reactivo: Alpine no debe envolver el Lienzo ni las instancias de ECharts en proxies.
        let lienzo = null;
        let pendiente = null;
        let empujado = false;
        let scrollChat = 0;

        return {
            borrador: '',
            enviando: null,
            vista: 'chat',
            historialAbierto: false,
            ampliado: false,
            anchoChat: 380,
            limite: 2000,

            init() {
                this.limite = Number(this.$el.dataset.limite) || 2000;
                try {
                    const guardado = Number(localStorage.getItem(CLAVE_ANCHO));
                    if (guardado > 0) {
                        this.anchoChat = acotar(guardado);
                    }
                } catch (_) {}
                this.aplicarAncho();

                lienzo = new Lienzo(this.$refs.lienzo, {
                    alAmpliar: () => this.alternarAmpliado(),
                    estaAmpliado: () => this.ampliado,
                });

                this.$nextTick(() => this.alFinal());
            },

            destroy() {
                lienzo?.destruir();
                lienzo = null;
            },

            async enviar() {
                const texto = this.borrador.trim();

                if (! texto || this.enviando) {
                    return;
                }

                // Reintentar el mismo texto tras un fallo reutiliza la clave: el servidor no duplica la consulta.
                const clave = pendiente?.texto === texto ? pendiente.clave : uuid();
                pendiente = { texto, clave };
                this.enviando = texto;
                this.borrador = '';
                this.historialAbierto = false;
                this.$nextTick(() => {
                    this.ajustarAltura();
                    this.alFinal();
                });

                let aceptado = false;
                try {
                    aceptado = (await this.$wire.enviar(texto, clave)) === true;
                } catch (_) {
                    aceptado = false;
                }

                this.enviando = null;
                if (aceptado) {
                    pendiente = null;
                } else if (this.borrador === '') {
                    this.borrador = texto;
                }

                this.$nextTick(() => {
                    this.ajustarAltura();
                    this.alFinal();
                    if (! movil.matches) {
                        this.$refs.texto?.focus({ preventScroll: true });
                    }
                });
            },

            teclaEnter(evento) {
                if (evento.shiftKey || evento.isComposing || movil.matches) {
                    return;
                }
                evento.preventDefault();
                this.enviar();
            },

            ajustarAltura() {
                const campo = this.$refs.texto;
                if (! campo) {
                    return;
                }
                campo.style.height = 'auto';
                campo.style.height = `${Math.min(campo.scrollHeight, 150)}px`;
                // scrollHeight se redondea: sin esto, medio píxel de desborde muestra una barra de desplazamiento vacía.
                campo.style.overflowY = campo.scrollHeight > 150 ? 'auto' : 'hidden';
            },

            usarSugerencia(texto) {
                this.borrador = texto;
                this.enviar();
            },

            alFinal() {
                const mensajes = this.$refs.mensajes;
                if (mensajes) {
                    mensajes.scrollTop = mensajes.scrollHeight;
                }
            },

            recibirLienzo(detalle) {
                lienzo?.actualizar(detalle ?? {});
            },

            verArtefacto(detalle) {
                if (movil.matches) {
                    this.abrirLienzo();
                }
                this.$nextTick(() => lienzo?.enfocar(detalle ?? {}));
            },

            // Móvil: el Lienzo es una vista propia; «Atrás» del sistema vuelve al chat en el mismo punto.
            abrirLienzo() {
                if (this.vista === 'lienzo') {
                    return;
                }
                scrollChat = this.$refs.mensajes?.scrollTop ?? 0;
                this.vista = 'lienzo';
                try {
                    history.pushState({ ...(history.state ?? {}), agenteMozi: 'lienzo' }, '');
                    empujado = true;
                } catch (_) {
                    empujado = false;
                }
            },

            volverAlChat() {
                if (empujado) {
                    history.back();

                    return;
                }
                this.mostrarChat();
            },

            alVolverHistorial() {
                if (this.vista === 'lienzo') {
                    empujado = false;
                    this.mostrarChat();
                }
            },

            mostrarChat() {
                this.vista = 'chat';
                this.$nextTick(() => {
                    if (this.$refs.mensajes) {
                        this.$refs.mensajes.scrollTop = scrollChat;
                    }
                });
            },

            alternarAmpliado() {
                this.ampliado = ! this.ampliado;
                lienzo?.ampliado(this.ampliado);
            },

            aplicarAncho() {
                this.$el.style.setProperty('--agente-chat', `${this.anchoChat}px`);
            },

            guardarAncho() {
                try {
                    localStorage.setItem(CLAVE_ANCHO, String(this.anchoChat));
                } catch (_) {}
            },

            ajustarAncho(delta) {
                this.anchoChat = acotar(this.anchoChat + delta);
                this.aplicarAncho();
                this.guardarAncho();
            },

            arrastrar(evento) {
                if (evento.button !== 0 || movil.matches) {
                    return;
                }
                evento.preventDefault();
                const inicio = this.$el.getBoundingClientRect().left;
                const separador = evento.currentTarget;
                separador.setPointerCapture?.(evento.pointerId);
                document.body.classList.add('agente-arrastrando');

                const mover = e => {
                    this.anchoChat = acotar(e.clientX - inicio);
                    this.aplicarAncho();
                };
                const soltar = () => {
                    document.body.classList.remove('agente-arrastrando');
                    separador.removeEventListener('pointermove', mover);
                    separador.removeEventListener('pointerup', soltar);
                    separador.removeEventListener('pointercancel', soltar);
                    this.guardarAncho();
                };

                separador.addEventListener('pointermove', mover);
                separador.addEventListener('pointerup', soltar);
                separador.addEventListener('pointercancel', soltar);
            },
        };
    });
}

if (window.Alpine) {
    registrar(window.Alpine);
} else {
    document.addEventListener('alpine:init', () => registrar(window.Alpine));
}
