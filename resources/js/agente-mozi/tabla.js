// Tabla nativa del Lienzo: búsqueda, orden y paginación en el navegador sobre un dataset ya validado y acotado
// por el servidor (máx. agente_mozi.limites.filas_dataset). La exportación CSV la sirve el servidor.
import { el, icono, t } from './dom.js';
import { compararDecimal, formatear, normalizar } from './formato.js';

const NUMERICOS = new Set(['integer', 'number', 'money', 'percent']);

// Solo presentación: el tono de la insignia sale de palabras clave del estado (es/en).
function tonoInsignia(texto) {
    const valor = normalizar(texto);

    if (/critic|agotad|out of stock|vencid/.test(valor)) {
        return 'danger';
    }
    if (/atencion|attention|bajo|low|riesgo|risk|pendiente/.test(valor)) {
        return 'warning';
    }
    if (/estable|stable|suficiente|al dia|ok\b/.test(valor)) {
        return 'success';
    }

    return 'info';
}

export function montarTabla(contenedor, { columnas, filas, spec, textos }) {
    const tipos = Object.fromEntries(columnas.map(columna => [columna.field, columna.type]));
    const visibles = spec?.columns ?? columnas.map(columna => ({ field: columna.field, label: columna.label, format: formatoPorTipo(columna.type) }));
    const tamano = spec?.page_size ?? 10;
    let orden = spec?.default_sort ? { campo: spec.default_sort.field, direccion: spec.default_sort.direction } : null;
    let pagina = 1;
    let filtro = '';

    const registros = filas.map(fila => {
        const celdas = visibles.map(columna => formatear(fila[columna.field], columna.format));

        return { fila, celdas, busqueda: normalizar(celdas.join(' ')) };
    });

    const buscador = el('input', { class: 'input', type: 'search', placeholder: textos.buscar, 'aria-label': textos.buscar });
    buscador.addEventListener('input', () => {
        filtro = normalizar(buscador.value.trim());
        pagina = 1;
        pintar();
    });
    const contador = el('span', { class: 'contador', 'aria-live': 'polite' });

    const encabezados = visibles.map(columna => {
        const boton = el('button', { type: 'button', text: columna.label, title: t(textos.ordenar, { columna: columna.label }) });
        const th = el('th', { scope: 'col', class: NUMERICOS.has(tipos[columna.field]) ? 'num' : null, 'aria-sort': 'none' }, boton);
        boton.addEventListener('click', () => {
            orden = orden?.campo === columna.field
                ? { campo: columna.field, direccion: orden.direccion === 'asc' ? 'desc' : 'asc' }
                : { campo: columna.field, direccion: NUMERICOS.has(tipos[columna.field]) ? 'desc' : 'asc' };
            pagina = 1;
            pintar();
        });

        return th;
    });

    const cuerpo = el('tbody');
    const paginas = el('div', { class: 'lienzo-paginas' });
    const vacio = el('p', { class: 'lienzo-sin-resultados', text: textos.sinResultados, hidden: true });

    contenedor.replaceChildren(
        el('div', { class: 'lienzo-tabla-barra' }, [el('label', { class: 'search' }, [icono('search'), buscador]), contador]),
        el('div', { class: 'table-wrap lienzo-tabla' }, el('table', {}, [el('thead', {}, el('tr', {}, encabezados)), cuerpo])),
        vacio,
        paginas,
    );

    function comparar(a, b) {
        const x = a.fila[orden.campo];
        const y = b.fila[orden.campo];

        // Los vacíos quedan al final en ambos sentidos.
        if (x === null || x === undefined || y === null || y === undefined) {
            return (x ?? null) === (y ?? null) ? 0 : ((x ?? null) === null ? 1 : -1);
        }

        const resultado = NUMERICOS.has(tipos[orden.campo])
            ? compararDecimal(x, y)
            : String(x).localeCompare(String(y), 'es', { numeric: true, sensitivity: 'base' });

        return orden.direccion === 'desc' ? -resultado : resultado;
    }

    function pintar() {
        const lista = registros.filter(registro => filtro === '' || registro.busqueda.includes(filtro));

        if (orden) {
            lista.sort(comparar);
        }

        const total = Math.max(1, Math.ceil(lista.length / tamano));
        pagina = Math.min(pagina, total);

        encabezados.forEach((th, i) => {
            th.setAttribute('aria-sort', orden?.campo === visibles[i].field ? (orden.direccion === 'asc' ? 'ascending' : 'descending') : 'none');
        });

        cuerpo.replaceChildren(...lista.slice((pagina - 1) * tamano, pagina * tamano).map(registro => el('tr', {}, visibles.map((columna, i) => {
            const texto = registro.celdas[i];
            const nulo = registro.fila[columna.field] === null || registro.fila[columna.field] === undefined;
            const clase = [NUMERICOS.has(tipos[columna.field]) ? 'num' : '', nulo ? 'vacio' : ''].join(' ').trim() || null;

            return el('td', { class: clase }, columna.format === 'badge' && ! nulo
                ? el('span', { class: `badge ${tonoInsignia(texto)}`, text: texto })
                : texto);
        }))));

        contador.textContent = t(textos.filas, { n: lista.length });
        vacio.hidden = lista.length > 0;
        paginas.hidden = total <= 1;
        paginas.replaceChildren(
            el('span', { text: t(textos.pagina, { actual: pagina, total }) }),
            el('div', {}, [
                el('button', { type: 'button', class: 'btn soft sm', text: textos.anterior, disabled: pagina <= 1, onclick: () => { pagina--; pintar(); } }),
                el('button', { type: 'button', class: 'btn soft sm', text: textos.siguiente, disabled: pagina >= total, onclick: () => { pagina++; pintar(); } }),
            ]),
        );
    }

    pintar();
}

export function formatoPorTipo(tipo) {
    return { integer: 'integer', number: 'number', money: 'bs', percent: 'percent', date: 'date' }[tipo] ?? 'text';
}
