// Construcción de DOM del Lienzo sin innerHTML: todo texto que viene de datos se asigna con textContent.
const SVG = 'http://www.w3.org/2000/svg';

// Mismos trazos que App\Support\Iconos (24×24, trazo); solo los que dibuja el Lienzo.
const ICONOS = {
    alert: [['path', { d: 'M12 3 2 20h20L12 3Z' }], ['path', { d: 'M12 10v4M12 17h.01' }]],
    chart: [['path', { d: 'M4 20V4M4 20h17M8 15l4-5 3 3 5-7' }]],
    check: [['path', { d: 'm5 12 4 4L19 6' }]],
    dashboard: [
        ['rect', { x: 3, y: 3, width: 7, height: 9, rx: 1.5 }], ['rect', { x: 14, y: 3, width: 7, height: 5, rx: 1.5 }],
        ['rect', { x: 14, y: 12, width: 7, height: 9, rx: 1.5 }], ['rect', { x: 3, y: 16, width: 7, height: 5, rx: 1.5 }],
    ],
    download: [['path', { d: 'M12 3v12m-5-5 5 5 5-5M4 17v4h16v-4' }]],
    info: [['circle', { cx: 12, cy: 12, r: 10 }], ['path', { d: 'M12 11v6M12 7h.01' }]],
    maximize: [['path', { d: 'M8 3H3v5M16 3h5v5M21 16v5h-5M3 16v5h5' }]],
    minimize: [['path', { d: 'M3 8h5V3M21 8h-5V3M16 21v-5h5M8 21v-5H3' }]],
    refresh: [['path', { d: 'M20 11a8 8 0 0 0-14.9-3.9M4 4v4h4M4 13a8 8 0 0 0 14.9 3.9M20 20v-4h-4' }]],
    search: [['circle', { cx: 10.8, cy: 10.8, r: 7 }], ['path', { d: 'm16 16 5 5' }]],
    sparkles: [
        ['path', { d: 'M11 3.5 12.6 8a3 3 0 0 0 1.9 1.9L19 11.5l-4.5 1.6a3 3 0 0 0-1.9 1.9L11 19.5l-1.6-4.5a3 3 0 0 0-1.9-1.9L3 11.5l4.5-1.6A3 3 0 0 0 9.4 8Z' }],
        ['path', { d: 'M18.5 3v4M16.5 5h4' }],
    ],
    table: [['rect', { x: 3, y: 4, width: 18, height: 16, rx: 2 }], ['path', { d: 'M3 10h18M3 15h18M9 10v10' }]],
};

export function el(etiqueta, atributos = {}, hijos = []) {
    const nodo = document.createElement(etiqueta);

    for (const [clave, valor] of Object.entries(atributos)) {
        if (valor === null || valor === undefined || valor === false) {
            continue;
        }
        if (clave === 'class') {
            nodo.className = valor;
        } else if (clave === 'text') {
            nodo.textContent = String(valor);
        } else if (clave.startsWith('on') && typeof valor === 'function') {
            nodo.addEventListener(clave.slice(2), valor);
        } else {
            nodo.setAttribute(clave, valor === true ? '' : String(valor));
        }
    }

    for (const hijo of [hijos].flat()) {
        if (hijo !== null && hijo !== undefined && hijo !== false) {
            nodo.append(hijo instanceof Node ? hijo : document.createTextNode(String(hijo)));
        }
    }

    return nodo;
}

export function icono(nombre, tamano = 'sm') {
    const svg = document.createElementNS(SVG, 'svg');
    svg.setAttribute('viewBox', '0 0 24 24');
    svg.setAttribute('aria-hidden', 'true');
    svg.setAttribute('class', tamano ? `ico ${tamano}` : 'ico');

    for (const [etiqueta, atributos] of ICONOS[nombre] ?? ICONOS.info) {
        const trazo = document.createElementNS(SVG, etiqueta);
        Object.entries(atributos).forEach(([clave, valor]) => trazo.setAttribute(clave, String(valor)));
        svg.append(trazo);
    }

    return svg;
}

// Botón de icono con nombre accesible (title + aria-label).
export function botonIcono(nombre, etiqueta, alPulsar, clase = 'icon-action') {
    return el('button', { type: 'button', class: clase, title: etiqueta, 'aria-label': etiqueta, onclick: alPulsar }, icono(nombre));
}

// Reemplaza :clave en los textos traducidos que llegan del servidor.
export function t(texto, valores = {}) {
    return Object.entries(valores).reduce((acumulado, [clave, valor]) => acumulado.replaceAll(`:${clave}`, String(valor)), String(texto ?? ''));
}

// Lee un token CSS del tema activo (para ECharts, que no entiende var()).
export function token(nombre, respaldo = '') {
    return getComputedStyle(document.documentElement).getPropertyValue(nombre).trim() || respaldo;
}

export function temaActual() {
    return document.documentElement.dataset.theme === 'light' ? 'light' : 'dark';
}
