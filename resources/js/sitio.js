/*
  MOZISTOCK · SITIO WEB PÚBLICO
  Comportamiento mínimo y sin dependencias: tema, menú y desplegables, cabecera, aparición al
  hacer scroll y la maqueta animada de la portada. Los enlaces de WhatsApp vienen armados del
  servidor (número configurado en Superadmin › Sitio web). Las cifras de la maqueta son ilustrativas.
*/
(function () {
    'use strict';

    var root = document.documentElement;
    var reducido = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    // ── Tema claro / oscuro (misma clave que la app) ─────────────────────────────
    var botonTema = document.querySelector('[data-theme-toggle]');
    var metaTema = document.querySelector('meta[name="theme-color"]');

    function pintarTema(tema) {
        root.setAttribute('data-theme', tema);
        if (metaTema) metaTema.setAttribute('content', tema === 'light' ? '#f7f5f1' : '#151411');
        if (botonTema) botonTema.setAttribute('aria-label', tema === 'light' ? 'Cambiar a modo oscuro' : 'Cambiar a modo claro');
    }

    pintarTema(root.getAttribute('data-theme') === 'light' ? 'light' : 'dark');

    if (botonTema) {
        botonTema.addEventListener('click', function () {
            var tema = root.getAttribute('data-theme') === 'light' ? 'dark' : 'light';
            pintarTema(tema);
            try { localStorage.setItem('mozistock-theme', tema); } catch (e) { /* almacenamiento bloqueado */ }
        });
    }

    // ── Menú principal y desplegables ────────────────────────────────────────────
    var cabecera = document.querySelector('[data-header]');
    var botonMenu = document.querySelector('[data-menu-toggle]');
    var grupos = Array.prototype.slice.call(document.querySelectorAll('[data-dropdown]'));
    var escritorio = window.matchMedia('(min-width: 1101px)');

    function cerrarGrupos(excepto) {
        grupos.forEach(function (grupo) {
            if (grupo === excepto) return;
            grupo.classList.remove('is-open');
            grupo.querySelector('button').setAttribute('aria-expanded', 'false');
        });
    }

    function alternarMenu(abrir) {
        if (!cabecera || !botonMenu) return;
        cabecera.classList.toggle('is-menu', abrir);
        botonMenu.setAttribute('aria-expanded', abrir ? 'true' : 'false');
        botonMenu.setAttribute('aria-label', abrir ? 'Cerrar menú' : 'Abrir menú');
        document.body.style.overflow = abrir ? 'hidden' : '';
        if (!abrir) cerrarGrupos(null);
    }

    if (botonMenu) {
        botonMenu.addEventListener('click', function () {
            alternarMenu(!cabecera.classList.contains('is-menu'));
        });
    }

    grupos.forEach(function (grupo) {
        var boton = grupo.querySelector('button');
        var temporizador = null;

        boton.addEventListener('click', function () {
            var abrir = !grupo.classList.contains('is-open');
            cerrarGrupos(grupo);
            grupo.classList.toggle('is-open', abrir);
            boton.setAttribute('aria-expanded', abrir ? 'true' : 'false');
        });

        // En escritorio también se abre al pasar el mouse (con un pequeño margen al salir).
        grupo.addEventListener('mouseenter', function () {
            if (!escritorio.matches) return;
            window.clearTimeout(temporizador);
            cerrarGrupos(grupo);
            grupo.classList.add('is-open');
            boton.setAttribute('aria-expanded', 'true');
        });
        grupo.addEventListener('mouseleave', function () {
            if (!escritorio.matches) return;
            temporizador = window.setTimeout(function () {
                grupo.classList.remove('is-open');
                boton.setAttribute('aria-expanded', 'false');
            }, 160);
        });
    });

    document.addEventListener('click', function (evento) {
        if (escritorio.matches && !evento.target.closest('[data-dropdown]')) cerrarGrupos(null);
    });

    document.addEventListener('keydown', function (evento) {
        if (evento.key !== 'Escape') return;
        var abierto = grupos.filter(function (grupo) { return grupo.classList.contains('is-open'); })[0];
        if (abierto) {
            cerrarGrupos(null);
            abierto.querySelector('button').focus();
        } else if (cabecera && cabecera.classList.contains('is-menu')) {
            alternarMenu(false);
            botonMenu.focus();
        }
    });

    escritorio.addEventListener('change', function () { alternarMenu(false); });

    // ── Cabecera con borde al hacer scroll ───────────────────────────────────────
    var pendiente = false;

    function marcarCabecera() {
        pendiente = false;
        if (cabecera) cabecera.classList.toggle('is-scrolled', window.scrollY > 8);
    }

    window.addEventListener('scroll', function () {
        if (!pendiente) {
            pendiente = true;
            window.requestAnimationFrame(marcarCabecera);
        }
    }, { passive: true });
    marcarCabecera();

    // ── Medición de contactos (si se instala el píxel de Meta) ──────────────────
    document.querySelectorAll('[data-wa]').forEach(function (enlace) {
        enlace.addEventListener('click', function () {
            if (typeof window.fbq === 'function') window.fbq('track', 'Contact');
        });
    });

    var conObservador = 'IntersectionObserver' in window;

    // ── Aparición al hacer scroll ────────────────────────────────────────────────
    var revelables = Array.prototype.slice.call(document.querySelectorAll('.reveal'));

    if (conObservador && !reducido) {
        // Lo que ya está en pantalla se muestra sin animar, para que nada parpadee.
        revelables.forEach(function (nodo) {
            if (nodo.getBoundingClientRect().top < window.innerHeight) nodo.classList.add('is-in');
        });
        root.classList.add('reveal-ready');

        var observadorRevelar = new IntersectionObserver(function (entradas) {
            entradas.forEach(function (entrada) {
                if (entrada.isIntersecting) {
                    entrada.target.classList.add('is-in');
                    observadorRevelar.unobserve(entrada.target);
                }
            });
        }, { rootMargin: '0px 0px -8% 0px', threshold: 0.08 });

        revelables.forEach(function (nodo) {
            if (!nodo.classList.contains('is-in')) observadorRevelar.observe(nodo);
        });
    }

    // ── Maqueta viva de la portada: ventas que llegan y suben lo cobrado ─────────
    // Importes en centavos (enteros) para no usar decimales de punto flotante.
    var cobrado = document.querySelector('[data-cobrado]');
    var vendido = document.querySelector('[data-vendido]');
    var aviso = document.querySelector('[data-sale]');
    var textoAviso = document.querySelector('[data-sale-text]');
    var barraHoy = document.querySelector('[data-today]');
    var visual = document.querySelector('.hero-visual');

    var ventas = [
        { ref: 'V-01249', centavos: 4200, metodo: 'QR' },
        { ref: 'V-01250', centavos: 12750, metodo: 'Mixto' },
        { ref: 'V-01251', centavos: 3600, metodo: 'Efectivo' },
        { ref: 'V-01252', centavos: 9800, metodo: 'QR' }
    ];
    var totalCobrado = 193000;
    var totalVendido = 248000;
    var alturaHoy = 70;
    var indice = 0;
    var temporizadorVentas = null;

    function bolivianos(centavos) {
        var entero = String(Math.floor(centavos / 100)).replace(/\B(?=(\d{3})+(?!\d))/g, '.');
        var decimales = String(centavos % 100);
        return 'Bs ' + entero + ',' + (decimales.length < 2 ? '0' + decimales : decimales);
    }

    function destacar(nodo) {
        nodo.classList.add('is-bump');
        window.setTimeout(function () { nodo.classList.remove('is-bump'); }, 900);
    }

    function nuevaVenta() {
        var venta = ventas[indice % ventas.length];
        indice += 1;

        totalCobrado += venta.centavos;
        totalVendido += venta.centavos;
        cobrado.textContent = bolivianos(totalCobrado);
        vendido.textContent = bolivianos(totalVendido);
        destacar(cobrado);
        destacar(vendido);

        textoAviso.textContent = venta.ref + ' · ' + bolivianos(venta.centavos) + ' · ' + venta.metodo;
        aviso.classList.remove('is-new');
        void aviso.offsetWidth; // reinicia la animación de entrada
        aviso.classList.add('is-new');

        if (barraHoy && alturaHoy < 96) {
            alturaHoy = Math.min(96, alturaHoy + 6);
            barraHoy.style.setProperty('--h', alturaHoy + '%');
        }
    }

    function iniciar() { if (!temporizadorVentas) temporizadorVentas = window.setInterval(nuevaVenta, 4200); }
    function detener() { window.clearInterval(temporizadorVentas); temporizadorVentas = null; }

    if (cobrado && vendido && aviso && textoAviso && visual && !reducido) {
        var portadaVisible = true;
        var actualizar = function () {
            if (portadaVisible && !document.hidden) iniciar();
            else detener();
        };

        if (conObservador) {
            new IntersectionObserver(function (entradas) {
                portadaVisible = entradas[0].isIntersecting;
                actualizar();
            }).observe(visual);
        }
        document.addEventListener('visibilitychange', actualizar);
        actualizar();
    }
})();
