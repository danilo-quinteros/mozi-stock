<?php

use App\Domain\SitioWeb\CatalogoPaginas;
use App\Http\Controllers\SitioWebController;
use App\Livewire\Auth\Registro;
use Illuminate\Support\Facades\Route;

/*
| Sitio web público de MoziStock (mozistock.com). Solo existe en modo SaaS; la raíz «/» está en
| web.php porque en modo standalone conserva la redirección al panel. Las rutas salen del
| catálogo de páginas: agregar una página es agregarla a CatalogoPaginas y crear su vista.
*/

Route::middleware('saas')->group(function () {
    foreach (CatalogoPaginas::todas() as $pagina) {
        if ($pagina->clave === 'inicio') {
            continue;
        }

        Route::get($pagina->ruta, [SitioWebController::class, 'pagina'])
            ->defaults('clave', $pagina->clave)
            ->name($pagina->nombreRuta());
    }

    // Registro público con prueba gratis («Prueba gratis» en todo el sitio).
    Route::livewire('registro', Registro::class)->middleware('guest')->name('registro');

    Route::get('sitemap.xml', [SitioWebController::class, 'sitemap'])->name('sitio.sitemap');
    Route::get('sitio/imagenes/{archivo}', [SitioWebController::class, 'imagen'])
        ->where('archivo', '[A-Za-z0-9]{40}\.(jpg|png|webp)')
        ->name('sitio.imagen');
});

Route::get('robots.txt', [SitioWebController::class, 'robots'])->name('sitio.robots');
