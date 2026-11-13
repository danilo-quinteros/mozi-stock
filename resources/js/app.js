// Solo preferencias visuales; los textos llegan traducidos en data-label-*. No hay operaciones de negocio en el navegador.
function applyTheme(theme) {
    document.documentElement.dataset.theme = theme;
    document.documentElement.classList.toggle('dark', theme === 'dark');
    try { localStorage.setItem('mozistock-theme', theme); } catch (_) {}
    document.querySelectorAll('[data-theme-toggle]').forEach(button => {
        (button.querySelector('span') ?? button).textContent = theme === 'dark'
            ? (button.dataset.labelLight ?? 'Modo claro')
            : (button.dataset.labelDark ?? 'Modo oscuro');
    });
}

// Menú lateral colapsado (rail): solo en escritorio; en móvil el menú es el panel deslizable con acordeón.
const desktop = window.matchMedia('(min-width: 761px)');
const isRail = () => document.documentElement.dataset.sidebar === 'collapsed' && desktop.matches;

// En el rail los grupos quedan abiertos (su contenido es el panel flotante) y al volver recuperan su estado.
// Solo el toggle guarda la preferencia: aplicar el estado inicial (o el que pide una pantalla) no la pisa.
function applySidebar(state, persist = false) {
    const collapsed = state === 'collapsed';
    document.documentElement.dataset.sidebar = collapsed ? 'collapsed' : 'expanded';
    if (persist) {
        try { localStorage.setItem('mozistock-sidebar', collapsed ? 'collapsed' : 'expanded'); } catch (_) {}
    }
    closeFlyouts();
    document.querySelectorAll('.sidebar .nav-group').forEach(group => {
        if (isRail()) {
            group.dataset.wasOpen ??= group.open ? '1' : '0';
            group.open = true;
        } else if (group.dataset.wasOpen !== undefined) {
            group.open = group.dataset.wasOpen === '1';
            delete group.dataset.wasOpen;
        }
    });
    document.querySelectorAll('[data-sidebar-toggle]').forEach(button => {
        const label = collapsed ? button.dataset.labelExpand : button.dataset.labelCollapse;
        button.setAttribute('aria-label', label);
        button.setAttribute('aria-expanded', String(! collapsed));
        button.title = label;
    });
}

// Ubica el panel flotante a la altura del grupo, sin salirse de la ventana.
function placeFlyout(group) {
    const panel = group.querySelector('.nav-subitems');
    const top = group.querySelector('summary').getBoundingClientRect().top;
    const max = window.innerHeight - (panel?.offsetHeight ?? 0) - 12;
    group.style.setProperty('--flyout-top', `${Math.max(12, Math.min(top, max))}px`);
}

function closeFlyouts(except = null) {
    document.querySelectorAll('.sidebar .nav-group.flyout-open').forEach(group => {
        if (group !== except) {
            group.classList.remove('flyout-open');
            group.querySelector('summary')?.setAttribute('aria-expanded', 'false');
        }
    });
}

document.addEventListener('mouseover', event => {
    const group = isRail() ? event.target.closest('.sidebar .nav-group') : null;
    if (group && ! group.contains(event.relatedTarget)) {
        placeFlyout(group);
    }
});

document.addEventListener('click', event => {
    if (event.target.closest('[data-theme-toggle]')) {
        applyTheme(document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark');
    }
    if (event.target.closest('[data-sidebar-toggle]')) {
        applySidebar(document.documentElement.dataset.sidebar === 'collapsed' ? 'expanded' : 'collapsed', true);
    }

    if (! isRail()) {
        return;
    }

    // En el rail, el grupo no se pliega: el clic (táctil o teclado) abre o cierra su panel flotante.
    const summary = event.target.closest('.sidebar .nav-group > summary');
    if (summary) {
        event.preventDefault();
        const group = summary.parentElement;
        closeFlyouts(group);
        placeFlyout(group);
        const open = group.classList.toggle('flyout-open');
        summary.setAttribute('aria-expanded', String(open));
    } else if (! event.target.closest('.sidebar .nav-subitems')) {
        closeFlyouts();
    }
});

document.addEventListener('keydown', event => {
    if (event.key === 'Escape') {
        closeFlyouts();
    }
});

desktop.addEventListener('change', () => applySidebar(document.documentElement.dataset.sidebar || 'expanded'));
document.addEventListener('livewire:navigated', () => {
    applyTheme(document.documentElement.dataset.theme || 'dark');
    applySidebar(document.documentElement.dataset.sidebar || 'expanded');
});
applyTheme(document.documentElement.dataset.theme || 'dark');
applySidebar(document.documentElement.dataset.sidebar || 'expanded');

// Soporte para lectores de códigos de barras USB y Bluetooth tipo teclado (HID)
function initBarcodeScanner() {
    let buffer = [];
    let lastKeyTime = 0;
    const MAX_INTERVAL_MS = 55;
    const MIN_LENGTH = 3;

    window.addEventListener('keydown', event => {
        // Ignorar teclas modificadoras aisladas
        if (['Shift', 'Control', 'Alt', 'Meta', 'CapsLock', 'Tab'].includes(event.key)) {
            return;
        }

        const now = performance.now();
        const interval = now - lastKeyTime;
        lastKeyTime = now;

        // Si pasó más tiempo que el umbral de ráfaga rápida, reiniciar el búfer
        if (interval > MAX_INTERVAL_MS) {
            buffer = [];
        }

        if (event.key === 'Enter') {
            const barcode = buffer.join('').trim();
            buffer = [];

            // Si se detectó una ráfaga válida de código de barras
            if (barcode.length >= MIN_LENGTH) {
                event.preventDefault();
                event.stopPropagation();
                dispatchScannedBarcode(barcode);
                return;
            }

            // Si el foco está en el campo de código de barras, prevenir siempre el envío accidental al pulsar Enter
            const activeEl = document.activeElement;
            if (activeEl && (activeEl.matches('[data-scan-target="codigo-barras"]') || activeEl.id === 'codigo_barras')) {
                event.preventDefault();
                event.stopPropagation();
                return;
            }

            return;
        }

        // Acumular caracteres imprimibles simples (longitud 1)
        if (event.key.length === 1) {
            buffer.push(event.key);
        }
    }, true);
}

function dispatchScannedBarcode(barcode) {
    // 1. Contexto: Formulario de Productos (crear / editar)
    const campoBarras = document.querySelector('[data-scan-target="codigo-barras"], #codigo_barras');
    if (campoBarras && campoBarras.offsetParent !== null) {
        // Si el foco estaba en otro campo (ej. nombre), revertir los caracteres de la ráfaga
        const activeEl = document.activeElement;
        if (activeEl && activeEl !== campoBarras && (activeEl.tagName === 'INPUT' || activeEl.tagName === 'TEXTAREA')) {
            if (typeof activeEl.value === 'string' && activeEl.value.endsWith(barcode)) {
                activeEl.value = activeEl.value.slice(0, -barcode.length);
                activeEl.dispatchEvent(new Event('input', { bubbles: true }));
                activeEl.dispatchEvent(new Event('change', { bubbles: true }));
            }
        }

        campoBarras.value = barcode;
        campoBarras.dispatchEvent(new Event('input', { bubbles: true }));
        campoBarras.dispatchEvent(new Event('change', { bubbles: true }));
        campoBarras.focus();
        campoBarras.select();

        window.dispatchEvent(new CustomEvent('mozistock-scan-producto', {
            detail: { codigo: barcode },
            bubbles: true,
        }));
        return;
    }

    // 2. Verificar si hay un modal abierto sin campo de escaneo (evitar interferir con modales de clientes o cobro)
    const modalActivo = document.querySelector('.modal-backdrop.open, dialog[open], .modal:not([hidden])');
    const posOInventario = document.querySelector('.pos-search, [data-pos-buscar], .scanner-wrap, [data-scanner-wrap]');

    if (modalActivo && posOInventario && ! modalActivo.contains(posOInventario)) {
        return;
    }

    // 3. Contexto: POS, Pedidos, Compras y Ajustes
    if (posOInventario) {
        const activeEl = document.activeElement;
        if (activeEl && ! activeEl.matches('[data-pos-buscar], .scanner-wrap input, [x-ref="buscador"]') && (activeEl.tagName === 'INPUT' || activeEl.tagName === 'TEXTAREA')) {
            if (typeof activeEl.value === 'string' && activeEl.value.endsWith(barcode)) {
                activeEl.value = activeEl.value.slice(0, -barcode.length);
                activeEl.dispatchEvent(new Event('input', { bubbles: true }));
                activeEl.dispatchEvent(new Event('change', { bubbles: true }));
            }
        }

        window.dispatchEvent(new CustomEvent('mozistock-scan', {
            detail: { codigo: barcode },
            bubbles: true,
            cancelable: true,
        }));
    }
}

initBarcodeScanner();


// ==========================================================================
// Errores de validación: aviso rojo abajo a la derecha
// ==========================================================================
// Al pulsar un botón (acción Livewire) o enviar un formulario clásico, si el servidor devuelve errores de validación,
// además del mensaje bajo cada campo se muestra un aviso con el primero: el campo puede quedar fuera de la pantalla.
// Pulsar el aviso lleva al primer campo marcado. Solo muestra lo que respondió el servidor; no valida nada.
function textosValidacion() {
    const meta = document.querySelector('meta[name="mozistock-validacion"]');

    return { mas: meta?.dataset.mas ?? '(+:n)', ver: meta?.dataset.ver ?? '' };
}

function irAlPrimerError() {
    const marca = document.querySelector('[aria-invalid="true"], .auth-error, .field-error');

    if (! marca) {
        return;
    }

    marca.scrollIntoView({ behavior: 'smooth', block: 'center' });
    const campo = marca.matches('input, select, textarea')
        ? marca
        : marca.closest('.field, .pos-field, .rapid-field, td, label')?.querySelector('input:not([type=hidden]), select, textarea');
    campo?.focus({ preventScroll: true });
}

export function mostrarErrorValidacion(mensaje, total = 1) {
    let host = document.getElementById('toast-validacion');

    if (! host) {
        host = document.createElement('div');
        host.id = 'toast-validacion';
        host.className = 'toast-host';
        host.setAttribute('role', 'alert');
        host.setAttribute('aria-live', 'assertive');
        document.body.appendChild(host);
    }

    const textos = textosValidacion();
    const aviso = document.createElement('button');
    aviso.type = 'button';
    aviso.className = 'toast danger toast-validacion';
    aviso.textContent = total > 1 ? `${mensaje} ${textos.mas.replace(':n', String(total - 1))}` : mensaje;
    aviso.title = textos.ver;
    aviso.addEventListener('click', () => {
        irAlPrimerError();
        aviso.remove();
    });

    host.replaceChildren(aviso);
    clearTimeout(host.ocultar);
    host.ocultar = setTimeout(() => aviso.remove(), 6000);
}

window.mostrarErrorValidacion = mostrarErrorValidacion;

function erroresDe(snapshot) {
    let datos = snapshot;

    if (typeof datos === 'string') {
        try { datos = JSON.parse(datos); } catch (_) { return []; }
    }

    return Object.values(datos?.memo?.errors ?? {}).flat().filter(mensaje => typeof mensaje === 'string' && mensaje !== '');
}

document.addEventListener('livewire:init', () => {
    window.Livewire.hook('commit', ({ commit, succeed }) => {
        // Solo acciones del usuario (guardar, confirmar, revisar...); escribir en un campo no muestra el aviso.
        const acciones = (commit?.calls ?? []).filter(llamada => ! String(llamada.method ?? '').startsWith('$') && ! String(llamada.method ?? '').startsWith('_'));

        if (acciones.length === 0) {
            return;
        }

        succeed(({ snapshot }) => {
            const errores = erroresDe(snapshot);

            if (errores.length > 0) {
                mostrarErrorValidacion(errores[0], errores.length);
            }
        });
    });
});

// Formularios clásicos (sin Livewire): los errores vuelven en la recarga y el layout los deja en la meta.
document.addEventListener('DOMContentLoaded', () => {
    const meta = document.querySelector('meta[name="mozistock-validacion"]');

    if (meta?.dataset.primero) {
        mostrarErrorValidacion(meta.dataset.primero, Number(meta.dataset.total ?? 1));
    }
});