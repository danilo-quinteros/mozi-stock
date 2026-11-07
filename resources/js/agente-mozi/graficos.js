// Gráficos del Lienzo con ECharts a partir de una spec declarativa ya validada por el servidor (ValidadorLienzo).
// Los números se convierten a Number solo para ubicar marcas; todo texto mostrado sale de formatear(valor original).
// El tooltip es HTML: cada texto derivado de datos se escapa antes de insertarlo.
import { el, t, temaActual, token } from './dom.js';
import { aNumero, compararDecimal, fecha, formatear, sumarDecimal } from './formato.js';

let cargador = null;
export const cargarEcharts = () => (cargador ??= import('./echarts.js').then(modulo => modulo.default));

// Paletas categóricas en orden fijo, validadas (CVD, contraste y banda de luminosidad) contra --panel de cada tema.
const SERIES = {
    dark: ['#d2722e', '#4893d4', '#36a268', '#b072d0', '#b38e1c', '#de5f79'],
    light: ['#c56a28', '#2f7fc6', '#1f8f57', '#9256b8', '#a8820f', '#c8455f'],
};
const MAX_PORCIONES = 6;

function escapar(texto) {
    return String(texto ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
}

function colores() {
    const tema = temaActual();

    return {
        series: SERIES[tema],
        texto: token('--text', '#e9e1d6'),
        tenue: token('--muted', '#aaa198'),
        debil: token('--faint', '#7d756b'),
        borde: token('--border', '#3b352d'),
        suave: token('--border-soft', '#302b25'),
        superficie: token('--panel', '#29251f'),
        elevada: token('--panel-elevated', '#2f2a23'),
    };
}

function presente(valor) {
    return valor !== null && valor !== undefined && valor !== '';
}

// Etiqueta del eje de categorías: fechas cortas (dd/mm), el resto tal cual (ECharts la dibuja como texto SVG).
function etiquetaX(valor, tipo) {
    if (tipo === 'date') {
        return fecha(valor).slice(0, 5);
    }

    return tipo === 'string' ? String(valor) : formatear(valor, 'number');
}

function marcaEje(valor, formato) {
    const numero = formatear(String(valor), 'number');

    return formato === 'bs' ? `Bs ${numero}` : (formato === 'percent' ? `${numero} %` : numero);
}

function preparar(spec, filas) {
    const campos = [...spec.series.map(serie => serie.field), ...(spec.band ? [spec.band.lower_field, spec.band.upper_field] : [])];
    let validas = filas.filter(fila => presente(fila[spec.x_field]) && campos.some(campo => presente(fila[campo])));

    if (spec.sort === 'asc' || spec.sort === 'desc') {
        const campo = spec.series[0].field;
        validas = [...validas].sort((a, b) => (spec.sort === 'desc' ? -1 : 1) * compararDecimal(a[campo], b[campo]));
    }

    return { validas, omitidas: filas.length - validas.length };
}

// Donut: como máximo seis porciones en orden fijo de color; el resto se agrupa en «Otros» con suma exacta.
function porciones(spec, validas, textos) {
    const campo = spec.series[0].field;
    const ordenadas = [...validas].filter(fila => presente(fila[campo])).sort((a, b) => -compararDecimal(a[campo], b[campo]));

    if (ordenadas.length <= MAX_PORCIONES) {
        return ordenadas.map(fila => ({ nombre: String(fila[spec.x_field]), valor: fila[campo] }));
    }

    const resto = ordenadas.slice(MAX_PORCIONES - 1);

    return [
        ...ordenadas.slice(0, MAX_PORCIONES - 1).map(fila => ({ nombre: String(fila[spec.x_field]), valor: fila[campo] })),
        { nombre: textos.otros, valor: sumarDecimal(resto.map(fila => fila[campo])) },
    ];
}

function opciones(spec, tipos, validas, textos) {
    const c = colores();
    const formato = spec.value_format;
    const unidad = spec.value_unit;
    const texto = (valor, campo) => formatear(valor, formato === 'number' && tipos[campo] === 'integer' ? 'integer' : formato, unidad);
    const base = {
        animationDuration: 500,
        animationEasing: 'cubicOut',
        textStyle: { fontFamily: 'Inter, ui-sans-serif, system-ui, sans-serif', color: c.texto },
        tooltip: {
            backgroundColor: c.elevada,
            borderColor: c.borde,
            borderWidth: 1,
            padding: [8, 11],
            textStyle: { color: c.texto, fontSize: 12 },
            extraCssText: 'border-radius:10px;box-shadow:0 10px 28px rgba(0,0,0,.22);',
            confine: true,
        },
    };
    const linea = (marcador, etiqueta, valor) => `<div style="display:flex;gap:10px;justify-content:space-between;align-items:center"><span>${marcador}${escapar(etiqueta)}</span><b style="font-variant-numeric:tabular-nums">${escapar(valor)}</b></div>`;
    const punto = color => `<span style="display:inline-block;width:8px;height:8px;border-radius:50%;margin-right:7px;background:${color}"></span>`;
    const leyenda = nombres => (nombres.length > 1 ? {
        top: 0, left: 0, icon: 'roundRect', itemWidth: 12, itemHeight: 4, itemGap: 16,
        textStyle: { color: c.tenue, fontSize: 11 }, data: nombres,
    } : { show: false });

    if (spec.chart_type === 'donut') {
        const datos = porciones(spec, validas, textos);

        return {
            ...base,
            color: c.series,
            legend: { ...leyenda(datos.map(d => d.nombre)), top: 'auto', bottom: 0, left: 'center' },
            tooltip: { ...base.tooltip, trigger: 'item', formatter: p => linea(punto(p.color), p.name, `${texto(datos[p.dataIndex].valor, spec.series[0].field)} · ${formatear(p.percent.toFixed(1), 'percent')}`) },
            series: [{
                type: 'pie', radius: ['52%', '76%'], center: ['50%', '45%'], avoidLabelOverlap: true, label: { show: false },
                itemStyle: { borderColor: c.superficie, borderWidth: 2, borderRadius: 4 },
                emphasis: { scale: true, scaleSize: 4 },
                data: datos.map(d => ({ name: d.nombre, value: aNumero(d.valor) })),
            }],
        };
    }

    const ejeValor = (nombre) => ({
        type: 'value', name: nombre ?? '', nameTextStyle: { color: c.debil, fontSize: 11 },
        axisLabel: { color: c.debil, fontSize: 11, formatter: valor => marcaEje(valor, formato) },
        splitLine: { lineStyle: { color: c.suave } }, axisLine: { show: false }, axisTick: { show: false },
    });
    const grid = { left: 4, right: 18, top: spec.series.length > 1 || spec.band ? 38 : 14, bottom: 4, outerBoundsMode: 'same', outerBoundsContain: 'axisLabel' };

    if (spec.chart_type === 'scatter') {
        const tooltip = p => {
            const fila = validas[p.dataIndex];
            const serie = spec.series[p.seriesIndex];
            const titulo = spec.label_field ? `<div style="margin-bottom:5px;font-weight:700">${escapar(fila[spec.label_field])}</div>` : '';

            return titulo
                + linea('', spec.x_label ?? spec.x_field, formatear(fila[spec.x_field], tipos[spec.x_field] === 'integer' ? 'integer' : 'number'))
                + linea(punto(p.color), serie.label, texto(fila[serie.field], serie.field));
        };

        return {
            ...base,
            grid: { ...grid, bottom: 22 },
            legend: leyenda(spec.series.map(serie => serie.label)),
            tooltip: { ...base.tooltip, trigger: 'item', formatter: tooltip },
            xAxis: { ...ejeValor(spec.x_label), nameLocation: 'middle', nameGap: 26, axisLabel: { color: c.debil, fontSize: 11, formatter: valor => formatear(String(valor), 'number') } },
            yAxis: ejeValor(spec.y_label),
            series: spec.series.map((serie, i) => ({
                type: 'scatter', name: serie.label, symbolSize: 11,
                itemStyle: { color: c.series[i], borderColor: c.superficie, borderWidth: 2, opacity: 0.92 },
                emphasis: { scale: 1.3 },
                data: validas.map(fila => [aNumero(fila[spec.x_field]), aNumero(fila[serie.field])]),
            })),
        };
    }

    const categorias = validas.map(fila => etiquetaX(fila[spec.x_field], tipos[spec.x_field]));
    const horizontal = spec.chart_type === 'bar' && spec.orientation === 'horizontal';
    const ejeCategorias = {
        type: 'category', data: categorias, inverse: horizontal, name: spec.x_label ?? '',
        nameTextStyle: { color: c.debil, fontSize: 11 },
        axisLabel: { color: c.tenue, fontSize: 11, hideOverlap: true, width: horizontal ? 130 : undefined, overflow: horizontal ? 'truncate' : undefined },
        axisLine: { lineStyle: { color: c.borde } }, axisTick: { show: false },
    };
    const series = spec.series.map((serie, i) => {
        const datos = validas.map(fila => aNumero(fila[serie.field]));

        if (spec.chart_type === 'bar') {
            return {
                type: 'bar', name: serie.label, data: datos, barMaxWidth: 26, barGap: '18%', barCategoryGap: '38%',
                itemStyle: { color: c.series[i], borderRadius: horizontal ? [0, 4, 4, 0] : [4, 4, 0, 0] },
                emphasis: { focus: 'series' },
            };
        }

        return {
            type: 'line', name: serie.label, data: datos, showSymbol: datos.length <= 1, symbolSize: 8, connectNulls: false, z: 3,
            lineStyle: { width: 2, color: c.series[i], type: serie.style === 'dashed' ? 'dashed' : 'solid' },
            itemStyle: { color: c.series[i], borderColor: c.superficie, borderWidth: 2 },
            areaStyle: spec.chart_type === 'area' ? { color: c.series[i], opacity: 0.12 } : undefined,
            emphasis: { focus: 'series' },
        };
    });
    const nombres = spec.series.map(serie => serie.label);

    // Banda de incertidumbre: base invisible + ancho apilado, con el color de la serie estimada.
    if (spec.band) {
        const color = c.series[Math.max(0, spec.series.findIndex(serie => serie.style === 'dashed'))];
        const inferior = validas.map(fila => aNumero(fila[spec.band.lower_field]));
        const superior = validas.map(fila => aNumero(fila[spec.band.upper_field]));
        const comun = { type: 'line', stack: 'banda', symbol: 'none', silent: true, lineStyle: { opacity: 0 }, z: 1, tooltip: { show: false } };
        series.push(
            { ...comun, name: '__banda', data: inferior },
            { ...comun, name: spec.band.label, data: inferior.map((valor, i) => (valor === null || superior[i] === null ? null : superior[i] - valor)), areaStyle: { color, opacity: 0.16 }, itemStyle: { color } },
        );
        nombres.push(spec.band.label);
    }

    const tooltip = parametros => {
        const lista = [parametros].flat();
        const fila = validas[lista[0]?.dataIndex ?? 0];
        const filasHtml = spec.series
            .filter(serie => presente(fila[serie.field]))
            .map((serie, i) => linea(punto(c.series[spec.series.indexOf(serie)] ?? c.series[i]), serie.label, texto(fila[serie.field], serie.field)));

        if (spec.band && presente(fila[spec.band.lower_field]) && presente(fila[spec.band.upper_field])) {
            filasHtml.push(linea('', spec.band.label, `${texto(fila[spec.band.lower_field], spec.band.lower_field)} – ${texto(fila[spec.band.upper_field], spec.band.upper_field)}`));
        }

        const titulo = tipos[spec.x_field] === 'date' ? fecha(fila[spec.x_field]) : String(fila[spec.x_field]);

        return `<div style="margin-bottom:5px;color:${c.tenue}">${escapar(titulo)}</div>${filasHtml.join('')}`;
    };

    return {
        ...base,
        grid,
        legend: leyenda(nombres),
        tooltip: {
            ...base.tooltip,
            trigger: 'axis',
            axisPointer: spec.chart_type === 'bar' ? { type: 'shadow', shadowStyle: { color: 'rgba(160,140,120,.08)' } } : { type: 'line', lineStyle: { color: c.borde } },
            formatter: tooltip,
        },
        xAxis: horizontal ? ejeValor(spec.y_label) : ejeCategorias,
        yAxis: horizontal ? ejeCategorias : ejeValor(spec.y_label),
        series,
    };
}

function resumen(titulo, spec, validas, tipos, textos) {
    const campo = spec.series[0].field;
    const mayor = validas.reduce((max, fila) => (max === null || compararDecimal(fila[campo], max[campo]) > 0 ? fila : max), null);

    return t(textos.graficoResumen, {
        titulo,
        n: validas.length,
        max: mayor ? formatear(mayor[campo], spec.value_format, spec.value_unit) : '—',
        etiqueta: mayor ? (tipos[spec.x_field] === 'date' ? fecha(mayor[spec.x_field]) : String(mayor[spec.x_field])) : '—',
    });
}

/**
 * Dibuja el gráfico en `contenedor`. Si aún no tiene tamaño (Lienzo oculto en móvil), espera a tenerlo.
 * Devuelve { tema(), destruir() } para que el Lienzo repinte al cambiar de tema y libere recursos.
 */
export function montarGrafico(contenedor, { titulo, spec, columnas, filas, textos, alFallar }) {
    const tipos = Object.fromEntries(columnas.map(columna => [columna.field, columna.type]));
    const { validas, omitidas } = preparar(spec, filas);
    const lienzo = el('div', { class: 'lienzo-grafico', role: 'img', 'aria-label': resumen(titulo, spec, validas, tipos, textos) });
    let instancia = null;
    let destruido = false;

    contenedor.replaceChildren(lienzo, omitidas > 0 ? el('p', { class: 'lienzo-nota', text: t(textos.graficoSinDatos, { n: omitidas }) }) : '');

    const observador = new ResizeObserver(() => {
        if (instancia) {
            instancia.resize();
        } else if (lienzo.clientWidth > 0) {
            iniciar();
        }
    });
    observador.observe(lienzo);

    function iniciar() {
        if (instancia || destruido) {
            return;
        }

        cargarEcharts().then(echarts => {
            if (destruido || instancia) {
                return;
            }
            instancia = echarts.init(lienzo, null, { renderer: 'svg' });
            instancia.setOption(opciones(spec, tipos, validas, textos));
        }).catch(() => alFallar?.());
    }

    if (lienzo.clientWidth > 0) {
        iniciar();
    }

    return {
        tema() {
            instancia?.setOption(opciones(spec, tipos, validas, textos), true);
        },
        destruir() {
            destruido = true;
            observador.disconnect();
            instancia?.dispose();
            instancia = null;
        },
    };
}
