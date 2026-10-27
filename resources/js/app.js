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
function applySidebar(state) {
    const collapsed = state === 'collapsed';
    document.documentElement.dataset.sidebar = collapsed ? 'collapsed' : 'expanded';
    try { localStorage.setItem('mozistock-sidebar', collapsed ? 'collapsed' : 'expanded'); } catch (_) {}
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
        applySidebar(document.documentElement.dataset.sidebar === 'collapsed' ? 'expanded' : 'collapsed');
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
