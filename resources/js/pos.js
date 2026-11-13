import { CAJA_MP3_B64 } from './pos-caja-audio.js';

// Atajos del punto de venta (pos.html): F2 enfoca el buscador/escáner y F4 pulsa «Confirmar venta» si está
// habilitado. Solo enfoca y pulsa botones de la pantalla; importes, stock y cobros los valida el servidor.
document.addEventListener('keydown', event => {
    const pos = document.querySelector('[data-pos]');

    if (! pos || (event.key !== 'F2' && event.key !== 'F4' && event.key !== 'F8')) {
        return;
    }

    event.preventDefault();

    // F8: abre otra venta en una pestaña nueva (la actual queda en espera).
    if (event.key === 'F8') {
        const nueva = pos.querySelector('[data-pos-nueva]');

        if (nueva && ! nueva.disabled) {
            nueva.click();
        }

        return;
    }

    if (event.key === 'F2') {
        pos.querySelector('[data-pos-buscar]')?.focus();

        return;
    }

    const confirmar = pos.querySelector('[data-pos-confirmar]');

    if (confirmar && ! confirmar.disabled) {
        confirmar.click();
    }
});

// ==========================================================================
// Sonidos de Caja Registradora con Web Audio API (Sin archivos externos)
// ==========================================================================
let audioCtx = null;
let bufferCaja = null;
let promesaBufferCaja = null;
let ultimoSonidoVentaMs = 0;

function cargarBufferCaja(ctx) {
    if (bufferCaja) {
        return Promise.resolve(bufferCaja);
    }
    if (! promesaBufferCaja && ctx) {
        try {
            const bin = atob(CAJA_MP3_B64);
            const len = bin.length;
            const bytes = new Uint8Array(len);
            for (let i = 0; i < len; i++) {
                bytes[i] = bin.charCodeAt(i);
            }
            promesaBufferCaja = ctx.decodeAudioData(bytes.buffer)
                .then(decoded => {
                    bufferCaja = decoded;
                    return decoded;
                })
                .catch(() => null);
        } catch (_) {
            promesaBufferCaja = Promise.resolve(null);
        }
    }
    return promesaBufferCaja || Promise.resolve(null);
}

function getAudioContext() {
    if (! audioCtx) {
        const AudioContextClass = window.AudioContext || window.webkitAudioContext;
        if (AudioContextClass) {
            audioCtx = new AudioContextClass();
        }
    }
    if (audioCtx && audioCtx.state === 'suspended') {
        audioCtx.resume();
    }
    if (audioCtx && ! bufferCaja && ! promesaBufferCaja) {
        cargarBufferCaja(audioCtx);
    }
    return audioCtx;
}

// Desbloqueo proactivo en la primera interacción del usuario
const desbloquearAudio = () => {
    const ctx = getAudioContext();
    if (ctx) {
        cargarBufferCaja(ctx);
    }
    document.removeEventListener('pointerdown', desbloquearAudio);
    document.removeEventListener('keydown', desbloquearAudio);
};
document.addEventListener('pointerdown', desbloquearAudio, { once: true });
document.addEventListener('keydown', desbloquearAudio, { once: true });

/**
 * Sintetiza el golpe metálico característico de una campana de caja registradora clásica ("Trin").
 */
function sonarCampana(ctx, t, frecuenciaBase = 2093, duracion = 0.45) {
    // 1. Golpe percusivo del martillo (transitorio inicial de 25ms)
    try {
        const oscGolpe = ctx.createOscillator();
        const gananciaGolpe = ctx.createGain();
        oscGolpe.type = 'triangle';
        oscGolpe.frequency.setValueAtTime(frecuenciaBase * 1.5, t);
        oscGolpe.frequency.exponentialRampToValueAtTime(frecuenciaBase * 0.4, t + 0.025);
        gananciaGolpe.gain.setValueAtTime(0.25, t);
        gananciaGolpe.gain.exponentialRampToValueAtTime(0.001, t + 0.025);
        oscGolpe.connect(gananciaGolpe);
        gananciaGolpe.connect(ctx.destination);
        oscGolpe.start(t);
        oscGolpe.stop(t + 0.025);
    } catch (_) {}

    // 2. Resonancia armónica de campana de bronce (con batido acústico y caída resonante)
    const parciales = [
        { freq: frecuenciaBase, gain: 0.22 },
        { freq: frecuenciaBase * 1.0045, gain: 0.20 }, // Batido metálico (~9 Hz)
        { freq: frecuenciaBase * 2.01, gain: 0.10 },   // Armónico superior
        { freq: frecuenciaBase * 0.5, gain: 0.06 }     // Resonancia del cuerpo
    ];

    parciales.forEach(({ freq, gain }) => {
        try {
            const osc = ctx.createOscillator();
            const g = ctx.createGain();
            osc.type = 'sine';
            osc.frequency.setValueAtTime(freq, t);

            g.gain.setValueAtTime(0.0001, t);
            g.gain.linearRampToValueAtTime(gain, t + 0.003); // Ataque inmediato
            g.gain.exponentialRampToValueAtTime(0.0001, t + duracion); // Caída suave de campana

            osc.connect(g);
            g.connect(ctx.destination);
            osc.start(t);
            osc.stop(t + duracion);
        } catch (_) {}
    });
}

/**
 * Reproduce la variante 3A de caja registradora al confirmar una venta:
 * 1) Apertura mecánica completa de la caja (0.025s a 0.38s)
 * 2) Ataque real de campana filtrado (> 3000 Hz, sin ruido grave de fondo)
 * 3) Campana de caja registradora con apagado exponencial progresivo (~1.6 s)
 */
function reproducirCajaRegistradora3A(ctx, buf) {
    const t = ctx.currentTime;

    // 1. Apertura completa de la caja (ambos golpes "ka-clack", 0.025s a 0.38s)
    const srcDrawer = ctx.createBufferSource();
    srcDrawer.buffer = buf;
    const gDrawer = ctx.createGain();
    gDrawer.gain.setValueAtTime(0.95, t);
    gDrawer.gain.setValueAtTime(0.95, t + 0.335);
    gDrawer.gain.linearRampToValueAtTime(0.0001, t + 0.365);
    srcDrawer.connect(gDrawer);
    gDrawer.connect(ctx.destination);
    srcDrawer.start(t, 0.025, 0.37);

    // 2. Ataque inicial real de la campana (filtrado > 3000 Hz para eliminar ruido grave/medio)
    const srcBell = ctx.createBufferSource();
    srcBell.buffer = buf;

    const hp1 = ctx.createBiquadFilter();
    hp1.type = 'highpass';
    hp1.frequency.setValueAtTime(3000, t);
    hp1.Q.setValueAtTime(0.707, t);

    const hp2 = ctx.createBiquadFilter();
    hp2.type = 'highpass';
    hp2.frequency.setValueAtTime(3000, t);
    hp2.Q.setValueAtTime(0.707, t);

    const hp3 = ctx.createBiquadFilter();
    hp3.type = 'highpass';
    hp3.frequency.setValueAtTime(3000, t);
    hp3.Q.setValueAtTime(0.707, t);

    const gBell = ctx.createGain();
    gBell.gain.setValueAtTime(0.0001, t + 0.33);
    gBell.gain.linearRampToValueAtTime(1.10, t + 0.355);
    gBell.gain.setTargetAtTime(0.00001, t + 0.38, 0.085);

    srcBell.connect(hp1);
    hp1.connect(hp2);
    hp2.connect(hp3);
    hp3.connect(gBell);
    gBell.connect(ctx.destination);
    srcBell.start(t + 0.33, 0.365, 0.48);

    // 3. Campana de caja registradora con apagado progresivo suave (~1.6 s)
    const tBell = t + 0.35;
    const tauBase = 0.22;

    try {
        const oscGolpe = ctx.createOscillator();
        const gananciaGolpe = ctx.createGain();
        oscGolpe.type = 'triangle';
        oscGolpe.frequency.setValueAtTime(4800, tBell);
        oscGolpe.frequency.exponentialRampToValueAtTime(1400, tBell + 0.022);
        gananciaGolpe.gain.setValueAtTime(0.20, tBell);
        gananciaGolpe.gain.exponentialRampToValueAtTime(0.0001, tBell + 0.022);
        oscGolpe.connect(gananciaGolpe);
        gananciaGolpe.connect(ctx.destination);
        oscGolpe.start(tBell);
        oscGolpe.stop(tBell + 0.025);
    } catch (_) {}

    const parcialesCaja = [
        { freq: 3135.96, gain: 0.16, tauFactor: 1.05 },
        { freq: 3142.50, gain: 0.13, tauFactor: 0.98 },
        { freq: 4185.00, gain: 0.12, tauFactor: 0.90 },
        { freq: 6271.90, gain: 0.06, tauFactor: 0.55 },
        { freq: 1567.98, gain: 0.04, tauFactor: 1.15 }
    ];

    const maxStop = tBell + tauBase * 8.5;

    parcialesCaja.forEach(({ freq, gain, tauFactor }) => {
        try {
            const osc = ctx.createOscillator();
            const g = ctx.createGain();
            const tau = tauBase * (tauFactor || 1.0);
            osc.type = 'sine';
            osc.frequency.setValueAtTime(freq, tBell);

            g.gain.setValueAtTime(0.0001, tBell);
            g.gain.linearRampToValueAtTime(gain, tBell + 0.003);
            g.gain.setTargetAtTime(0.000001, tBell + 0.004, tau);

            osc.connect(g);
            g.connect(ctx.destination);
            osc.start(tBell);
            osc.stop(tBell + Math.min(maxStop - tBell, tau * 8.5));
        } catch (_) {}
    });
}

/**
 * Sonido "Trin": cuando se agrega un producto al carrito.
 */
export function sonarTrin() {
    try {
        const ctx = getAudioContext();
        if (! ctx) return;
        sonarCampana(ctx, ctx.currentTime, 2093, 0.45);
    } catch (e) {
        console.warn('Audio no disponible:', e);
    }
}

/**
 * Sonido de Caja Registradora (3A): cuando se confirma y finaliza una venta.
 */
export function sonarTrinTrin() {
    try {
        const ahoraMs = performance.now();
        if (ahoraMs - ultimoSonidoVentaMs < 80) {
            return;
        }
        ultimoSonidoVentaMs = ahoraMs;

        const ctx = getAudioContext();
        if (! ctx) return;

        if (bufferCaja) {
            reproducirCajaRegistradora3A(ctx, bufferCaja);
        } else {
            cargarBufferCaja(ctx).then(buf => {
                if (buf) {
                    reproducirCajaRegistradora3A(ctx, buf);
                }
            });
        }
    } catch (e) {
        console.warn('Audio no disponible:', e);
    }
}

window.sonarTrin = sonarTrin;
window.sonarTrinTrin = sonarTrinTrin;

// Listeners globales para eventos Livewire / DOM
window.addEventListener('pos-producto-agregado', () => {
    sonarTrin();
});

// Al cambiar de venta abierta (pestaña), el buscador queda listo para escanear.
window.addEventListener('pos-venta-cambiada', () => {
    requestAnimationFrame(() => document.querySelector('[data-pos] [data-pos-buscar]')?.focus());
});

// Al terminar la venta: sonido "Trin Trin" y desplaza la vista arriba para visibilizar el alert (.pos-last).
window.addEventListener('venta-finalizada', () => {
    sonarTrinTrin();
    window.scrollTo({ top: 0, behavior: 'smooth' });
    document.getElementById('contenido')?.scrollTo({ top: 0, behavior: 'smooth' });
});

// ==========================================================================
// Impresión directa en rollo térmico sin vista previa intermedia
// ==========================================================================
window.imprimirTicketDirecto = function (url) {
    if (! url) return;

    let iframe = document.getElementById('pos-print-iframe');
    if (! iframe) {
        iframe = document.createElement('iframe');
        iframe.id = 'pos-print-iframe';
        iframe.name = 'pos-print-iframe';
        iframe.style.position = 'fixed';
        iframe.style.right = '0';
        iframe.style.bottom = '0';
        iframe.style.width = '0';
        iframe.style.height = '0';
        iframe.style.border = '0';
        iframe.style.opacity = '0';
        iframe.style.pointerEvents = 'none';
        document.body.appendChild(iframe);
    }

    iframe.onload = function () {
        try {
            iframe.contentWindow?.focus();
            iframe.contentWindow?.print();
        } catch (_) {
            // Si el navegador bloquea print() en iframe por sandbox, abre ventana directa
            window.open(url, '_blank');
        }
    };

    iframe.src = url;
};
