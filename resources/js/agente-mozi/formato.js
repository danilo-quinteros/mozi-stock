// Formatos bolivianos (1.234,50 · Bs · %) calculados sobre el texto decimal que entrega el servidor, sin float:
// mismo criterio que App\Support\Decimal::formatear(). Solo presentación; ningún importe se recalcula aquí.
const DECIMAL = /^(-)?(\d+)(?:\.(\d+))?$/;

function partes(valor) {
    const m = DECIMAL.exec(String(valor).trim());

    return m ? { negativo: m[1] === '-', entero: m[2].replace(/^0+(?=\d)/, ''), decimales: m[3] ?? '' } : null;
}

// Redondeo «mitad hacia arriba» (lejos de cero), como bcmath + Decimal::redondear().
function fijar(p, digitos) {
    if (p.decimales.length <= digitos) {
        return { ...p, decimales: p.decimales.padEnd(digitos, '0') };
    }

    const sube = p.decimales.charCodeAt(digitos) >= 53;
    const n = (BigInt(p.entero + p.decimales.slice(0, digitos)) + (sube ? 1n : 0n)).toString().padStart(digitos + 1, '0');

    return { negativo: p.negativo, entero: n.slice(0, n.length - digitos), decimales: digitos > 0 ? n.slice(-digitos) : '' };
}

function componer(p) {
    const cero = /^0*$/.test(p.entero + p.decimales);
    const entero = p.entero.replace(/\B(?=(\d{3})+(?!\d))/g, '.');

    return (p.negativo && ! cero ? '−' : '') + entero + (p.decimales ? `,${p.decimales}` : '');
}

export function esNumero(valor) {
    return typeof valor === 'number' ? Number.isFinite(valor) : partes(valor ?? '') !== null;
}

export function fecha(valor) {
    const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(valor ?? ''));

    return m ? `${m[3]}/${m[2]}/${m[1]}` : String(valor ?? '');
}

export function fechaHora(iso) {
    const instante = new Date(iso);

    return Number.isNaN(instante.getTime()) ? '' : instante.toLocaleString('es-BO', { dateStyle: 'short', timeStyle: 'short' });
}

export function formatear(valor, formato = 'text', unidad = null) {
    if (valor === null || valor === undefined || valor === '') {
        return '—';
    }

    if (formato === 'date') {
        return fecha(valor);
    }

    const p = typeof valor === 'number' ? partes(String(valor)) : partes(valor);

    if (p === null || formato === 'text' || formato === 'badge') {
        return String(valor);
    }

    const texto = {
        integer: () => componer(fijar(p, 0)),
        decimal_1: () => componer(fijar(p, 1)),
        decimal_2: () => componer(fijar(p, 2)),
        bs: () => `Bs ${componer(fijar(p, 2))}`,
        percent: () => `${componer(fijar(p, 1))} %`,
        number: () => componer({ ...p, decimales: p.decimales.replace(/0+$/, '') }),
    }[formato]?.() ?? String(valor);

    return unidad && formato !== 'bs' && formato !== 'percent' ? `${texto} ${unidad}` : texto;
}

// Compara dos decimales en texto escalándolos a enteros (BigInt); los vacíos van siempre al final.
export function compararDecimal(a, b) {
    const x = partes(a ?? '');
    const y = partes(b ?? '');

    if (x === null || y === null) {
        return x === y ? 0 : (x === null ? 1 : -1);
    }

    const escala = Math.max(x.decimales.length, y.decimales.length);
    const entero = q => BigInt((q.negativo ? '-' : '') + q.entero + q.decimales.padEnd(escala, '0'));
    const diferencia = entero(x) - entero(y);

    return diferencia === 0n ? 0 : (diferencia < 0n ? -1 : 1);
}

// Suma exacta de decimales en texto (para agrupar «Otros» en un donut sin float).
export function sumarDecimal(valores) {
    const lista = valores.map(valor => partes(valor ?? '')).filter(Boolean);
    const escala = Math.max(0, ...lista.map(p => p.decimales.length));
    const total = lista.reduce((suma, p) => suma + BigInt((p.negativo ? '-' : '') + p.entero + p.decimales.padEnd(escala, '0')), 0n);
    const negativo = total < 0n;
    const digitos = (negativo ? -total : total).toString().padStart(escala + 1, '0');

    return (negativo ? '-' : '') + digitos.slice(0, digitos.length - escala) + (escala > 0 ? `.${digitos.slice(-escala)}` : '');
}

// Solo para posicionar marcas en un gráfico: el texto mostrado siempre sale de formatear(valor original).
export function aNumero(valor) {
    if (valor === null || valor === undefined || valor === '') {
        return null;
    }

    const n = Number(valor);

    return Number.isFinite(n) ? n : null;
}

export function normalizar(texto) {
    return String(texto ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
}
