<?php

use App\Domain\SitioWeb\Actions\GuardarSeoPagina;
use App\Domain\SitioWeb\CatalogoPaginas;
use App\Livewire\Admin\SitioWeb;
use App\Models\AjusteGlobal;
use App\Models\Auditoria;
use App\Models\PaginaWeb;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

function imagenOg(string $nombre = 'og.png'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($nombre, (string) file_get_contents(public_path('img/sitio/og-mozistock.png')));
}

test('only the superadmin can open the website SEO screen', function () {
    $this->actingAs(User::factory()->create())->get(route('admin.sitio-web'))->assertForbidden();
    $this->actingAs(User::factory()->superadmin()->create())->get(route('admin.sitio-web'))
        ->assertOk()->assertSee('Sitio web')->assertSee('Facturación SIAT');
});

test('the superadmin edits the SEO of a page and it is audited', function () {
    $superadmin = User::factory()->superadmin()->create();
    $pagina = CatalogoPaginas::buscar('precios');

    Livewire::actingAs($superadmin)->test(SitioWeb::class)
        ->call('seleccionar', 'precios')
        ->set('titulo_seo', '  Precios de MoziStock en Bolivia  ')
        ->set('descripcion_seo', $pagina->descripcion)
        ->set('og_titulo', 'Planes desde Bs 150')
        ->set('indexar', false)
        ->call('guardar')
        ->assertHasNoErrors()
        ->assertSee('Editado');

    $fila = PaginaWeb::query()->where('clave', 'precios')->sole();

    expect($fila->titulo_seo)->toBe('Precios de MoziStock en Bolivia')
        // Igual al valor por defecto: se guarda vacío para seguir al código.
        ->and($fila->descripcion_seo)->toBeNull()
        ->and($fila->og_titulo)->toBe('Planes desde Bs 150')
        ->and($fila->indexar)->toBeFalse()
        ->and(Auditoria::query()->where('accion', 'sitio_web.seo_actualizado')->where('usuario_id', $superadmin->id)->exists())->toBeTrue();

    $this->get(route('sitio.precios'))
        ->assertSee('<title>Precios de MoziStock en Bolivia</title>', false)
        ->assertSee('<meta name="robots" content="noindex, follow">', false);
});

test('saving only default values keeps no customization row', function () {
    $superadmin = User::factory()->superadmin()->create();
    $pagina = CatalogoPaginas::buscar('facturacion');

    $componente = Livewire::actingAs($superadmin)->test(SitioWeb::class)
        ->call('seleccionar', 'facturacion')
        ->call('guardar')
        ->assertHasNoErrors();

    expect(PaginaWeb::query()->count())->toBe(0);

    $componente->set('og_titulo', 'Factura con el SIAT')->call('guardar');
    expect(PaginaWeb::query()->where('clave', 'facturacion')->exists())->toBeTrue();

    // Volver a dejarlo como el código borra la fila en lugar de guardarla vacía.
    $componente->set('og_titulo', '')->set('titulo_seo', $pagina->titulo)->call('guardar')->assertHasNoErrors();
    expect(PaginaWeb::query()->count())->toBe(0);
});

test('SEO fields are validated on the server', function () {
    Livewire::actingAs(User::factory()->superadmin()->create())->test(SitioWeb::class)
        ->set('titulo_seo', str_repeat('a', 71))
        ->set('canonical_url', 'no es una url')
        ->call('guardar')
        ->assertHasErrors(['titulo_seo', 'canonical_url']);

    expect(PaginaWeb::query()->count())->toBe(0);
});

test('a page social image is stored privately, served publicly and replaced cleanly', function () {
    Storage::fake('local');
    $superadmin = User::factory()->superadmin()->create();

    $componente = Livewire::actingAs($superadmin)->test(SitioWeb::class)
        ->call('seleccionar', 'facturacion')
        ->set('imagen', imagenOg())
        ->call('guardar')
        ->assertHasNoErrors();

    $primera = PaginaWeb::query()->where('clave', 'facturacion')->sole()->og_imagen_path;
    Storage::disk('local')->assertExists($primera);
    expect($primera)->toMatch('#^sitio-web/[A-Za-z0-9]{40}\.png$#');

    $this->get(route('sitio.imagen', ['archivo' => basename($primera)]))->assertOk()->assertHeader('Content-Type', 'image/png');
    $this->get(route('sitio.facturacion'))->assertSee(route('sitio.imagen', ['archivo' => basename($primera)], false), false);

    $componente->set('imagen', imagenOg('otra.png'))->call('guardar')->assertHasNoErrors();
    $segunda = PaginaWeb::query()->where('clave', 'facturacion')->sole()->og_imagen_path;

    Storage::disk('local')->assertMissing($primera);
    Storage::disk('local')->assertExists($segunda);

    $componente->call('restablecer');

    expect(PaginaWeb::query()->where('clave', 'facturacion')->exists())->toBeFalse();
    Storage::disk('local')->assertMissing($segunda);
});

test('small or non-image files are rejected as social images', function () {
    Storage::fake('local');

    Livewire::actingAs(User::factory()->superadmin()->create())->test(SitioWeb::class)
        ->set('imagen', UploadedFile::fake()->create('og.pdf', 10, 'application/pdf'))
        ->assertHasErrors('imagen');
});

test('site settings normalize the WhatsApp number and the Google verification code', function () {
    $superadmin = User::factory()->superadmin()->create();

    Livewire::actingAs($superadmin)->test(SitioWeb::class)
        ->set('sitio_whatsapp', '+591 712-34567')
        ->set('sitio_verificacion_google', '<meta name="google-site-verification" content="AbC123_def-456xyz" />')
        ->call('guardarAjustes')
        ->assertHasNoErrors()
        ->assertSet('sitio_whatsapp', '59171234567')
        ->assertSet('sitio_verificacion_google', 'AbC123_def-456xyz');

    $ajustes = AjusteGlobal::actual();

    expect($ajustes->sitio_whatsapp)->toBe('59171234567')
        ->and($ajustes->sitio_verificacion_google)->toBe('AbC123_def-456xyz')
        ->and(Auditoria::query()->where('accion', 'sitio_web.ajustes_actualizados')->exists())->toBeTrue();

    $this->get(route('sitio.contacto'))->assertSee('https://wa.me/59171234567?text=', false);
});

test('an invalid WhatsApp number is rejected', function () {
    Livewire::actingAs(User::factory()->superadmin()->create())->test(SitioWeb::class)
        ->set('sitio_whatsapp', '123')
        ->call('guardarAjustes')
        ->assertHasErrors('sitio_whatsapp');

    expect(AjusteGlobal::actual()->sitio_whatsapp)->toBeNull();
});

test('a company user cannot run the SEO actions even calling them directly', function () {
    $usuario = User::factory()->create();

    expect(fn () => app(GuardarSeoPagina::class)->ejecutar($usuario, 'inicio', ['titulo_seo' => 'X']))
        ->toThrow(AuthorizationException::class)
        ->and(PaginaWeb::query()->count())->toBe(0);
});
