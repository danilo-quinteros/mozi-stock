// Lado padre del puente sandbox_web (docs/AGENTE_MOZI_INTEGRACION.md § 5). La vista se sirve desde una ruta con
// CSP `sandbox allow-scripts` (origen opaco) y el iframe también lleva sandbox="allow-scripts": no puede leer
// cookies, DOM ni almacenamiento de MoziStock. Solo se aceptan mensajes de ESE iframe, con la sesión aleatoria de
// esta carga, de tipos permitidos y a ritmo acotado. Lo que llegue de la vista nunca ejecuta acciones de negocio.
import { el, icono, temaActual } from './dom.js';
import { montarTabla } from './tabla.js';

const TIPOS = new Set(['ready', 'height', 'interaction', 'error']);
const MENSAJES_POR_SEGUNDO = 60;
const ESPERA_LISTA_MS = 6000;
const ALTURA_MIN = 160;

function sesionAleatoria() {
    const bytes = crypto.getRandomValues(new Uint8Array(24));

    return Array.from(bytes, b => 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_'[b & 63]).join('');
}

/**
 * Monta la vista aislada y su alternativa verificable (tabla nativa con el mismo dataset).
 * Devuelve { tema(), destruir() }.
 */
export function montarSandbox(contenedor, { artefacto, columnas, filas, meta, textos, alturaMaxima }) {
    const esDemo = meta?.origin === 'demo' || meta?.quality === 'demo';
    const marco = el('div');
    const alterna = el('div', { hidden: true });
    const aviso = el('p', { class: 'lienzo-aviso', role: 'status', hidden: true });
    const botonAlternar = el('button', { type: 'button', class: 'btn soft sm', text: textos.verTabla });
    const botonRestablecer = el('button', { type: 'button', class: 'btn ghost sm', text: textos.restablecer });
    let iframe = null;
    let sesion = '';
    let lista = false;
    let cargas = 0;
    let temporizador = null;
    let ventana = { inicio: 0, cuenta: 0, excesos: 0 };
    let tablaMontada = false;
    let mostrandoTabla = false;

    contenedor.replaceChildren(
        el('div', { class: 'lienzo-tabla-barra' }, [
            el('span', { class: `badge ${esDemo ? 'warning' : 'info'}` }, [icono('sparkles'), ' ', esDemo ? textos.sandboxDemo : textos.sandboxIa]),
            el('span', { class: 'lienzo-espacio' }),
            botonRestablecer,
            botonAlternar,
        ]),
        aviso,
        marco,
        alterna,
    );

    function verTabla(mostrar) {
        mostrandoTabla = mostrar;
        if (mostrar && ! tablaMontada) {
            montarTabla(alterna, { columnas, filas, spec: null, textos });
            tablaMontada = true;
        }
        alterna.hidden = ! mostrar;
        marco.hidden = mostrar;
        botonAlternar.textContent = mostrar ? textos.verVista : textos.verTabla;
        botonAlternar.disabled = mostrar && iframe === null;
    }

    function fallar(motivo) {
        quitar();
        aviso.hidden = false;
        aviso.replaceChildren(icono('alert'), el('span', { text: `${motivo} ${textos.sandboxFallo}` }));
        verTabla(true);
    }

    function enviar(tipo, extra = {}) {
        iframe?.contentWindow?.postMessage({ v: 1, s: sesion, type: tipo, theme: temaActual(), ...extra }, '*');
    }

    function alMensaje(evento) {
        // Origen opaco ("null") y misma ventana que creamos: cualquier otro emisor se ignora.
        if (! iframe || evento.source !== iframe.contentWindow || evento.origin !== 'null') {
            return;
        }

        const datos = evento.data;
        if (! datos || typeof datos !== 'object' || datos.v !== 1 || datos.s !== sesion || ! TIPOS.has(datos.type)) {
            return;
        }

        const ahora = performance.now();
        if (ahora - ventana.inicio > 1000) {
            ventana = { inicio: ahora, cuenta: 0, excesos: ventana.cuenta > MENSAJES_POR_SEGUNDO ? ventana.excesos + 1 : 0 };
        }
        if (++ventana.cuenta > MENSAJES_POR_SEGUNDO) {
            if (ventana.excesos >= 2) {
                fallar(textos.sandboxRuido);
            }

            return;
        }

        if (datos.type === 'ready' && ! lista) {
            lista = true;
            clearTimeout(temporizador);
            enviar('mozi:data', { columns: columnas, rows: filas, meta });
        } else if (datos.type === 'height') {
            const alto = Number(datos.payload?.h);
            if (Number.isFinite(alto)) {
                iframe.style.height = `${Math.round(Math.min(alturaMaxima, Math.max(ALTURA_MIN, alto)))}px`;
            }
        } else if (datos.type === 'error') {
            fallar(textos.sandboxError);
        }
        // 'interaction' se acepta pero no dispara nada en la fase 1 (no hay acciones desde la vista).
    }

    function quitar() {
        clearTimeout(temporizador);
        window.removeEventListener('message', alMensaje);
        iframe?.remove();
        iframe = null;
        sesion = '';
    }

    function cargar() {
        quitar();
        aviso.hidden = true;
        lista = false;
        cargas = 0;
        ventana = { inicio: 0, cuenta: 0, excesos: 0 };
        sesion = sesionAleatoria();
        iframe = el('iframe', {
            class: 'lienzo-sandbox-frame',
            title: artefacto.title,
            sandbox: 'allow-scripts',
            referrerpolicy: 'no-referrer',
            allow: "accelerometer 'none'; camera 'none'; geolocation 'none'; gyroscope 'none'; microphone 'none'; payment 'none'; usb 'none'",
            loading: 'eager',
        });
        // Una segunda carga significa que la vista navegó por su cuenta: se retira.
        iframe.addEventListener('load', () => {
            if (++cargas > 1) {
                fallar(textos.sandboxNavego);
            }
        });
        window.addEventListener('message', alMensaje);
        temporizador = setTimeout(() => lista || fallar(textos.sandboxTiempo), ESPERA_LISTA_MS);
        iframe.src = `${artefacto.view_url}#s=${sesion}`;
        marco.replaceChildren(iframe);
        verTabla(false);
    }

    botonAlternar.addEventListener('click', () => verTabla(! mostrandoTabla));
    botonRestablecer.addEventListener('click', cargar);
    cargar();

    return {
        tema() {
            if (lista) {
                enviar('mozi:theme');
            }
        },
        destruir: quitar,
    };
}
