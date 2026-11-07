// Atajos del punto de venta (pos.html): F2 enfoca el buscador/escáner y F4 pulsa «Confirmar venta» si está
// habilitado. Solo enfoca y pulsa botones de la pantalla; importes, stock y cobros los valida el servidor.
document.addEventListener('keydown', event => {
    const pos = document.querySelector('[data-pos]');

    if (! pos || (event.key !== 'F2' && event.key !== 'F4')) {
        return;
    }

    event.preventDefault();

    if (event.key === 'F2') {
        pos.querySelector('[data-pos-buscar]')?.focus();

        return;
    }

    const confirmar = pos.querySelector('[data-pos-confirmar]');

    if (confirmar && ! confirmar.disabled) {
        confirmar.click();
    }
});

// Al terminar la venta, desplaza la vista arriba para visibilizar el alert de confirmación (.pos-last).
window.addEventListener('venta-finalizada', () => {
    window.scrollTo({ top: 0, behavior: 'smooth' });
    document.getElementById('contenido')?.scrollTo({ top: 0, behavior: 'smooth' });
});
