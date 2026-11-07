// Lienzo de Agente Mozi: pinta el análisis (protocolo ProtocoloLienzo v1.0) con renderizadores nativos seguros.
// Los datasets se piden al servidor por referencia (autorizados por usuario y empresa); el código de sandbox_web
// nunca llega aquí, solo la URL de su vista aislada. Nada de lo que se pinta modifica datos del negocio.
import { botonIcono, el, icono } from './dom.js';
import { fecha, fechaHora, formatear } from './formato.js';
import { montarGrafico } from './graficos.js';
import { montarSandbox } from './sandbox.js';
import { montarTabla } from './tabla.js';

const ICONOS = { kpi: 'dashboard', chart: 'chart', table: 'table', insight: 'sparkles', empty_state: 'info', sandbox_web: 'sparkles' };
const INSIGNIAS = { actual: 'success', estimated: 'info', demo: 'warning', insufficient: 'warning' };
const MAX_DATASETS_EN_MEMORIA = 40;
const reducirMovimiento = window.matchMedia('(prefers-reduced-motion: reduce)');

function mismoOrigen(url) {
    try {
        const destino = new URL(url, window.location.href);

        return destino.origin === window.location.origin ? destino.href : null;
    } catch (_) {
        return null;
    }
}

export class Lienzo {
    #raiz;
    #textos;
    #opciones;
    #analisis = [];
    #actual = null;
    #nuevo = null;
    #datasets = new Map();
    #widgets = [];
    #generacion = 0;
    #alturaSandbox;
    #botonAmpliar = null;
    #observadorTema;

    constructor(raiz, opciones = {}) {
        const leer = selector => {
            try {
                return JSON.parse(raiz.querySelector(selector)?.textContent ?? 'null');
            } catch (_) {
                return null;
            }
        };
        const datos = leer('[data-lienzo-datos]') ?? {};

        this.#raiz = raiz;
        this.#opciones = opciones;
        this.#textos = leer('[data-lienzo-textos]') ?? {};
        this.#alturaSandbox = Number(raiz.dataset.sandboxAltura) || 900;
        this.#analisis = Array.isArray(datos.analisis) ? datos.analisis : [];
        this.#actual = this.#ultimo();

        // Cambio de tema: los gráficos releen los tokens y las vistas aisladas reciben el tema por el puente.
        this.#observadorTema = new MutationObserver(() => this.#widgets.forEach(widget => widget.tema?.()));
        this.#observadorTema.observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });

        this.render();
    }

    actualizar({ analisis, foco } = {}) {
        this.#analisis = Array.isArray(analisis) ? analisis : [];
        const existe = id => this.#analisis.some(item => item.message_id === id);

        if (foco && existe(foco)) {
            this.#actual = foco;
            this.#nuevo = foco;
        } else if (! existe(this.#actual)) {
            this.#actual = this.#ultimo();
            this.#nuevo = null;
        }

        this.render();
        this.#raiz.scrollTo({ top: 0 });
    }

    enfocar({ mensaje, artefacto } = {}) {
        if (this.#actual !== mensaje && this.#analisis.some(item => item.message_id === mensaje)) {
            this.#actual = mensaje;
            this.render();
        }
        if (this.#nuevo === mensaje) {
            this.#nuevo = null;
            this.#raiz.querySelectorAll('.lienzo-nuevo').forEach(nodo => nodo.remove());
        }

        requestAnimationFrame(() => {
            const tarjeta = this.#raiz.querySelector(`[data-artefacto="${CSS.escape(String(artefacto))}"]`);
            if (! tarjeta) {
                return;
            }
            tarjeta.scrollIntoView({ behavior: reducirMovimiento.matches ? 'auto' : 'smooth', block: 'start' });
            tarjeta.focus({ preventScroll: true });
            tarjeta.classList.add('destacado');
            setTimeout(() => tarjeta.classList.remove('destacado'), 1600);
        });
    }

    ampliado(estado) {
        if (! this.#botonAmpliar) {
            return;
        }
        const etiqueta = estado ? this.#textos.reducir : this.#textos.ampliar;
        this.#botonAmpliar.replaceChildren(icono(estado ? 'minimize' : 'maximize'));
        this.#botonAmpliar.title = etiqueta;
        this.#botonAmpliar.setAttribute('aria-label', etiqueta);
        this.#botonAmpliar.setAttribute('aria-pressed', String(estado));
    }

    destruir() {
        this.#destruirWidgets();
        this.#observadorTema.disconnect();
    }

    render() {
        this.#destruirWidgets();
        const generacion = ++this.#generacion;
        const analisis = this.#analisis.find(item => item.message_id === this.#actual);

        if (! analisis) {
            this.#botonAmpliar = null;
            this.#raiz.replaceChildren(this.#vacio());

            return;
        }

        const artefactos = Array.isArray(analisis.artifacts) ? analisis.artifacts : [];
        const tarjetas = artefactos.filter(artefacto => artefacto.type !== 'kpi');

        this.#raiz.replaceChildren(
            this.#cabecera(analisis, artefactos),
            this.#analisis.length > 1 ? this.#pestanas() : '',
            tarjetas.length > 3 ? this.#indice(tarjetas) : '',
            this.#cuerpo(artefactos, generacion),
        );
        this.ampliado(this.#opciones.estaAmpliado?.() ?? false);
    }

    #ultimo() {
        return this.#analisis.at(-1)?.message_id ?? null;
    }

    #destruirWidgets() {
        this.#widgets.forEach(widget => widget.destruir?.());
        this.#widgets = [];
    }

    #vacio() {
        return el('div', { class: 'lienzo-vacio' }, [
            el('div', { class: 'lienzo-vacio-arte', 'aria-hidden': 'true' }, [
                el('div', { class: 'kpi' }, [el('i'), el('b')]),
                el('div', { class: 'barras' }, [el('i'), el('i'), el('i'), el('i')]),
                el('div', {}, [el('i'), el('i'), el('i')]),
            ]),
            el('h2', { text: this.#textos.vacioTitulo }),
            el('p', { text: this.#textos.vacioTexto }),
        ]);
    }

    #cabecera(analisis, artefactos) {
        const metas = artefactos.map(artefacto => artefacto.meta ?? {});
        const calidades = [...new Set(metas.map(meta => meta.quality).filter(Boolean))];
        const periodo = metas.find(meta => meta.period?.from && meta.period?.to)?.period;
        const ambito = metas.find(meta => meta.scope)?.scope;
        const chips = [
            ...calidades.map(calidad => el('span', { class: `lienzo-chip ${calidad}` }, [icono(calidad === 'actual' ? 'check' : (calidad === 'demo' ? 'sparkles' : 'info')), this.#textos.calidad?.[calidad] ?? calidad])),
            periodo ? el('span', { class: 'lienzo-chip', text: `${this.#textos.periodo}: ${fecha(periodo.from)} – ${fecha(periodo.to)}` }) : '',
            ambito ? el('span', { class: 'lienzo-chip', text: ambito }) : '',
        ];

        this.#botonAmpliar = botonIcono('maximize', this.#textos.ampliar, () => this.#opciones.alAmpliar?.(), 'icon-action lienzo-ampliar');

        return el('header', { class: 'lienzo-head' }, [
            el('div', {}, [
                el('div', { class: 'eyebrow', text: this.#textos.lienzo }),
                el('h2', { text: analisis.title }),
                el('div', { class: 'lienzo-chips' }, chips),
            ]),
            el('div', { class: 'lienzo-acciones' }, this.#botonAmpliar),
        ]);
    }

    // Análisis anteriores de la charla, el más reciente primero.
    #pestanas() {
        return el('nav', { class: 'lienzo-tabs', 'aria-label': this.#textos.analisis }, [...this.#analisis].reverse().map(item => {
            const actual = item.message_id === this.#actual;
            const boton = el('button', { type: 'button', class: 'lienzo-tab', 'aria-current': actual ? 'true' : null, title: item.title }, [
                el('span', { text: item.title }),
                item.message_id === this.#nuevo ? el('b', { class: 'lienzo-nuevo', text: this.#textos.nuevo }) : '',
            ]);
            boton.addEventListener('click', () => {
                if (this.#nuevo === item.message_id) {
                    this.#nuevo = null;
                }
                this.#actual = item.message_id;
                this.render();
                this.#raiz.scrollTo({ top: 0 });
                this.#raiz.querySelector('.lienzo-tab[aria-current="true"]')?.focus();
            });

            return boton;
        }));
    }

    #indice(tarjetas) {
        return el('nav', { class: 'lienzo-indice', 'aria-label': this.#textos.indice }, tarjetas.map(artefacto => el('button', {
            type: 'button',
            onclick: () => this.enfocar({ mensaje: this.#actual, artefacto: artefacto.id }),
        }, [icono(ICONOS[artefacto.type]), artefacto.title])));
    }

    #cuerpo(artefactos, generacion) {
        const cuerpo = el('div', { class: 'lienzo-cuerpo' });
        let indicadores = null;

        artefactos.forEach((artefacto, i) => {
            const nodo = artefacto.type === 'kpi' ? this.#indicador(artefacto, generacion) : this.#tarjeta(artefacto, generacion);
            nodo.style.setProperty('--retraso', `${Math.min(i, 8) * 0.05}s`);

            if (artefacto.type === 'kpi') {
                indicadores ??= cuerpo.appendChild(el('div', { class: 'lienzo-kpis' }));
                indicadores.append(nodo);
            } else {
                indicadores = null;
                cuerpo.append(nodo);
            }
        });

        return cuerpo;
    }

    #insignia(meta) {
        const calidad = meta?.quality;

        return calidad && calidad !== 'actual'
            ? el('span', { class: `badge ${INSIGNIAS[calidad] ?? 'info'}`, text: this.#textos.calidad?.[calidad] ?? calidad })
            : '';
    }

    #detalles(meta) {
        if (! meta) {
            return [];
        }

        return [
            [this.#textos.fuente, meta.source ? (this.#textos.fuentes?.[meta.source] ?? meta.source) : null],
            [this.#textos.periodo, meta.period?.from && meta.period?.to ? `${fecha(meta.period.from)} – ${fecha(meta.period.to)}` : null],
            [this.#textos.ambito, meta.scope],
            [this.#textos.calculado, meta.calculated_at ? fechaHora(meta.calculated_at) : null],
            [this.#textos.metodo, meta.method],
            [this.#textos.nota, meta.note],
        ].filter(([, valor]) => valor);
    }

    #pie(meta) {
        const detalles = this.#detalles(meta);

        return detalles.length > 0
            ? el('footer', { class: 'lienzo-card-pie' }, detalles.map(([clave, valor]) => el('span', {}, [el('b', { text: `${clave}:` }), ` ${valor}`])))
            : '';
    }

    #indicador(artefacto, generacion) {
        const spec = artefacto.spec ?? {};
        const valor = el('div', { class: 'valor', text: '…', 'aria-live': 'polite' });
        const tarjeta = el('article', {
            class: `lienzo-card lienzo-kpi ${spec.tone ?? 'neutral'}`,
            'data-artefacto': artefacto.id,
            tabindex: '-1',
            title: this.#detalles(artefacto.meta).map(([clave, texto]) => `${clave}: ${texto}`).join('\n'),
        }, [
            el('div', { class: 'etiqueta' }, [el('span', { text: artefacto.title }), this.#insignia(artefacto.meta)]),
            valor,
            spec.hint ? el('div', { class: 'pista', text: spec.hint }) : '',
        ]);

        this.#dataset(artefacto).then(dataset => {
            if (generacion !== this.#generacion) {
                return;
            }
            valor.replaceChildren(formatear(dataset.rows[0]?.[spec.field], spec.format), spec.unit ? el('span', { class: 'unidad', text: spec.unit }) : '');
        }).catch(() => {
            valor.textContent = '—';
            valor.title = this.#textos.noDisponible;
        });

        return tarjeta;
    }

    #tarjeta(artefacto, generacion) {
        const cuerpo = el('div', { class: 'lienzo-card-body' });
        const acciones = el('div', { class: 'lienzo-acciones' });
        const csv = artefacto.csv_url ? mismoOrigen(artefacto.csv_url) : null;

        if (csv) {
            acciones.append(el('a', { class: 'icon-action', href: csv, download: '', title: this.#textos.exportar, 'aria-label': this.#textos.exportar }, icono('download')));
        }

        const tarjeta = el('article', { class: 'lienzo-card', 'data-artefacto': artefacto.id, tabindex: '-1', 'aria-label': artefacto.title }, [
            el('header', { class: 'lienzo-card-head' }, [
                el('div', { class: 'lienzo-card-etiquetas' }, [el('h3', { text: artefacto.title }), this.#insignia(artefacto.meta)]),
                acciones,
            ]),
            cuerpo,
            this.#pie(artefacto.meta),
        ]);
        const spec = artefacto.spec ?? {};

        if (artefacto.type === 'insight') {
            const tono = ['info', 'warning', 'success'].includes(spec.tone) ? spec.tone : 'info';
            cuerpo.append(el('div', { class: `lienzo-hallazgo ${tono}` }, [
                el('span', { class: 'lienzo-hallazgo-icono', 'aria-hidden': 'true' }, icono(tono === 'warning' ? 'alert' : (tono === 'success' ? 'check' : 'info'))),
                el('div', {}, [
                    el('p', { text: spec.text }),
                    Array.isArray(spec.bullets) && spec.bullets.length > 0 ? el('ul', {}, spec.bullets.map(vineta => el('li', { text: vineta }))) : '',
                ]),
            ]));

            return tarjeta;
        }

        if (artefacto.type === 'empty_state') {
            cuerpo.append(el('div', { class: 'lienzo-hallazgo info' }, [
                el('span', { class: 'lienzo-hallazgo-icono', 'aria-hidden': 'true' }, icono('info')),
                el('p', { text: spec.text }),
            ]));

            return tarjeta;
        }

        cuerpo.append(el('div', { class: 'lienzo-cargando', role: 'status', 'aria-label': this.#textos.cargando }));

        this.#dataset(artefacto).then(dataset => {
            if (generacion !== this.#generacion) {
                return;
            }
            if (artefacto.type === 'chart') {
                this.#grafico(cuerpo, acciones, artefacto, dataset);
            } else if (artefacto.type === 'table') {
                montarTabla(cuerpo, { columnas: dataset.columns, filas: dataset.rows, spec, textos: this.#textos });
            } else if (artefacto.type === 'sandbox_web') {
                const vista = artefacto.view_url ? mismoOrigen(artefacto.view_url) : null;
                if (! vista) {
                    throw new Error('vista no disponible');
                }
                this.#widgets.push(montarSandbox(cuerpo, {
                    artefacto: { ...artefacto, view_url: vista },
                    columnas: dataset.columns,
                    filas: dataset.rows,
                    meta: artefacto.meta,
                    textos: this.#textos,
                    alturaMaxima: this.#alturaSandbox,
                }));
            }
        }).catch(() => {
            if (generacion === this.#generacion) {
                cuerpo.replaceChildren(el('p', { class: 'lienzo-error', text: this.#textos.noDisponible }));
            }
        });

        return tarjeta;
    }

    // Gráfico con alternativa verificable: el mismo dataset en tabla nativa (accesible y sin depender del color).
    #grafico(cuerpo, acciones, artefacto, dataset) {
        const vistaGrafico = el('div');
        const vistaTabla = el('div', { hidden: true });
        let tablaMontada = false;
        const alternar = botonIcono('table', this.#textos.verTabla, () => {
            const mostrarTabla = vistaTabla.hidden;
            if (mostrarTabla && ! tablaMontada) {
                montarTabla(vistaTabla, { columnas: dataset.columns, filas: dataset.rows, spec: null, textos: this.#textos });
                tablaMontada = true;
            }
            vistaTabla.hidden = ! mostrarTabla;
            vistaGrafico.hidden = mostrarTabla;
            alternar.replaceChildren(icono(mostrarTabla ? 'chart' : 'table'));
            alternar.title = mostrarTabla ? this.#textos.verVista : this.#textos.verTabla;
            alternar.setAttribute('aria-label', alternar.title);
            alternar.setAttribute('aria-pressed', String(mostrarTabla));
        });

        acciones.prepend(alternar);
        cuerpo.replaceChildren(vistaGrafico, vistaTabla);
        this.#widgets.push(montarGrafico(vistaGrafico, {
            titulo: artefacto.title,
            spec: artefacto.spec,
            columnas: dataset.columns,
            filas: dataset.rows,
            textos: this.#textos,
            alFallar: () => vistaGrafico.replaceChildren(el('p', { class: 'lienzo-error', text: this.#textos.graficoError })),
        }));
    }

    #dataset(artefacto) {
        const url = artefacto.dataset_url ? mismoOrigen(artefacto.dataset_url) : null;

        if (! url) {
            return Promise.reject(new Error('sin dataset'));
        }

        if (! this.#datasets.has(url)) {
            if (this.#datasets.size >= MAX_DATASETS_EN_MEMORIA) {
                this.#datasets.clear();
            }

            const promesa = fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
                .then(respuesta => {
                    if (! respuesta.ok) {
                        throw new Error(String(respuesta.status));
                    }

                    return respuesta.json();
                })
                .then(datos => ({
                    columns: Array.isArray(datos.columns) ? datos.columns : [],
                    rows: Array.isArray(datos.rows) ? datos.rows : [],
                    meta: datos.meta ?? {},
                }));
            promesa.catch(() => this.#datasets.delete(url));
            this.#datasets.set(url, promesa);
        }

        return this.#datasets.get(url);
    }
}
