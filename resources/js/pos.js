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
    return audioCtx;
}

// Desbloqueo proactivo en la primera interacción del usuario
const desbloquearAudio = () => {
    getAudioContext();
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
 * Sonido "Trin Trin": cuando se confirma y finaliza una venta.
 */
export function sonarTrinTrin() {
    try {
        const ctx = getAudioContext();
        if (! ctx) return;
        const ahora = ctx.currentTime;
        // Primer "Trin"
        sonarCampana(ctx, ahora, 2093, 0.38);
        // Segundo "Trin" (un poco más agudo y con mayor brillo/eco)
        sonarCampana(ctx, ahora + 0.13, 2349, 0.65);
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
