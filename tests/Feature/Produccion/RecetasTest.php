<?php

use App\Domain\Produccion\Actions\GuardarMateriaPrima;
use App\Domain\Produccion\Actions\GuardarReceta;
use App\Domain\Tenancy\Actions\CambiarFuncionesEmpresa;
use App\Enums\Capacidad;
use App\Enums\Rubro;
use App\Livewire\Catalogo\ProductoFormulario;
use App\Livewire\Produccion\RecetaDetalle;
use App\Livewire\Produccion\RecetaFormulario;
use App\Livewire\Produccion\Recetas;
use App\Models\Empresa;
use App\Models\Producto;
use App\Models\Receta;
use App\Models\Sucursal;
use App\Models\UnidadMedida;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/*
 * Producción, etapa P2 (ESPECIFICACION_PRODUCCION.md § 5, PR4, PR5 y PR15): recetas con versiones. La vida útil (PR13)
 * se quitó el 16/11/2026: el vencimiento del lote producido se escribe al finalizar la orden.
 */

function recetaUnidad(Empresa $empresa, string $nombre, string $abreviatura, bool $decimales): UnidadMedida
{
    $unidad = new UnidadMedida(['nombre' => $nombre, 'abreviatura' => $abreviatura, 'admite_decimales' => $decimales, 'unidad_medida_sin' => 57]);
    $unidad->empresa_id = $empresa->id;
    $unidad->save();

    return $unidad;
}

function recetaMateria(User $admin, UnidadMedida $unidad, string $nombre, bool $activa = true): Producto
{
    $materia = app(GuardarMateriaPrima::class)->crear($admin, [
        'nombre' => $nombre, 'unidad_medida_id' => $unidad->id, 'controla_vencimiento' => false,
    ]);

    if (! $activa) {
        $materia->forceFill(['activo' => false])->save();
    }

    return $materia;
}

/**
 * @param  array<int, string>  $lineas  materia prima id => cantidad, en orden
 * @param  array<string, mixed>  $extra
 */
function guardarReceta(User $actor, Producto $producto, string $base, array $lineas, array $extra = []): Receta
{
    return app(GuardarReceta::class)->ejecutar($actor, $extra + [
        'producto_id' => $producto->id, 'cantidad_base' => $base, 'nota' => null, 'version' => null,
        'lineas' => array_map(fn (int $id, string $cantidad): array => ['producto_id' => $id, 'cantidad' => $cantidad], array_keys($lineas), $lineas),
    ]);
}

/**
 * @return list<array{0: int, 1: string}>
 */
function lineasDeReceta(Receta $receta): array
{
    return $receta->items()->get()->map(fn ($i): array => [$i->producto_id, (string) $i->cantidad])->all();
}

beforeEach(function () {
    [$this->empresa, $this->admin] = empresaConAdministrador();
    $this->matriz = Sucursal::factory()->create(['empresa_id' => $this->empresa->id, 'nombre' => 'Casa Matriz']);
    $this->litro = recetaUnidad($this->empresa, 'Litro', 'L', true);
    $this->kg = recetaUnidad($this->empresa, 'Kilogramo', 'kg', true);
    $this->und = recetaUnidad($this->empresa, 'Unidad', 'und.', false);
    $this->suavizante = Producto::factory()->enUnidad($this->litro)->porMedida()->create(['nombre' => 'Suavizante floral']);
    $this->actingAs($this->admin);
    app(CambiarFuncionesEmpresa::class)->ejecutar($this->admin, $this->empresa, Rubro::General, [Capacidad::Produccion]);
    $this->fragancia = recetaMateria($this->admin, $this->kg, 'Fragancia floral');
    $this->agua = recetaMateria($this->admin, $this->litro, 'Agua desmineralizada');
    $this->botella = recetaMateria($this->admin, $this->und, 'Botella 1 L');
});

test('P2: saving a recipe creates version 1 with its lines in order and audits it', function () {
    $receta = guardarReceta($this->admin, $this->suavizante, '100', [$this->agua->id => '95.5', $this->fragancia->id => '2,25'], ['nota' => 'Mezclar 20 minutos']);

    expect($receta->fresh())
        ->version->toBe(1)
        ->cantidad_base->toBe('100.000')
        ->nota->toBe('Mezclar 20 minutos')
        ->vigente->toBeTrue()
        ->activa->toBeTrue()
        ->usuario_id->toBe($this->admin->id)
        ->and(lineasDeReceta($receta))->toBe([[$this->agua->id, '95.500'], [$this->fragancia->id, '2.250']])
        ->and($this->suavizante->recetaVigente()->value('id'))->toBe($receta->id);

    $auditoria = DB::table('auditoria')->where('accion', 'receta.creada')->sole();
    expect(json_decode((string) $auditoria->despues, true))->toMatchArray(['producto_id' => $this->suavizante->id, 'version' => 1, 'cantidad_base' => '100.000'])
        ->and($auditoria->antes)->toBeNull();
});

test('P2: editing creates the next version and keeps the previous one unchanged and read-only', function () {
    $v1 = guardarReceta($this->admin, $this->suavizante, '100', [$this->agua->id => '95', $this->fragancia->id => '2']);
    $v2 = guardarReceta($this->admin, $this->suavizante, '50', [$this->fragancia->id => '1.2', $this->agua->id => '48'], ['version' => 1, 'nota' => 'Lote chico']);

    expect($v2->version)->toBe(2)
        ->and($v2->vigente)->toBeTrue()
        ->and($v1->fresh()->vigente)->toBeFalse()
        ->and(lineasDeReceta($v1))->toBe([[$this->agua->id, '95.000'], [$this->fragancia->id, '2.000']])
        ->and(lineasDeReceta($v2))->toBe([[$this->fragancia->id, '1.200'], [$this->agua->id, '48.000']])
        ->and(Receta::query()->where('producto_id', $this->suavizante->id)->where('vigente', true)->count())->toBe(1);

    $auditoria = DB::table('auditoria')->where('accion', 'receta.version')->sole();
    expect(json_decode((string) $auditoria->antes, true)['version'])->toBe(1)
        ->and(json_decode((string) $auditoria->despues, true))->toMatchArray(['version' => 2, 'cantidad_base' => '50.000', 'nota' => 'Lote chico']);
});

test('P2: repeating the same save does not create another version, and a stale edit is rejected instead of overwriting', function () {
    guardarReceta($this->admin, $this->suavizante, '100', [$this->agua->id => '95']);

    // Doble clic al crear: la segunda solicitud encuentra lo mismo y no crea nada.
    $repetida = guardarReceta($this->admin, $this->suavizante, '100', [$this->agua->id => '95']);
    expect($repetida->version)->toBe(1)->and(Receta::query()->count())->toBe(1);

    // Crear otra receta para el mismo producto, con otro contenido, se rechaza.
    expect(fn () => guardarReceta($this->admin, $this->suavizante, '100', [$this->agua->id => '90']))
        ->toThrow(ValidationException::class, 'ya tiene receta (versión 1). Edítala para crear una versión nueva.');

    // Dos personas editan la versión 1: la primera guarda la 2; la segunda, con otro contenido, no pisa sus cambios.
    guardarReceta($this->admin, $this->suavizante, '100', [$this->agua->id => '96'], ['version' => 1]);
    expect(fn () => guardarReceta($this->admin, $this->suavizante, '100', [$this->agua->id => '97'], ['version' => 1]))
        ->toThrow(ValidationException::class, 'cambió mientras la editabas: ahora está en la versión 2');

    // Reintento del mismo guardado (misma versión de origen, mismo contenido): devuelve la 2 sin crear la 3.
    expect(guardarReceta($this->admin, $this->suavizante, '100', [$this->agua->id => '96'], ['version' => 1])->version)->toBe(2)
        ->and(Receta::query()->count())->toBe(2)
        ->and(DB::table('auditoria')->where('accion', 'like', 'receta.%')->count())->toBe(2);
});

test('P2: only active sale products that control stock get a recipe, made of active raw materials without repeats', function () {
    $inactivo = productoDe($this->empresa, ['nombre' => 'Jabón viejo', 'activo' => false]);
    $sinStock = productoDe($this->empresa, ['nombre' => 'Servicio de mezcla', 'controla_stock' => false]);
    $vendible = productoDe($this->empresa, ['nombre' => 'Esponja']);
    $vieja = recetaMateria($this->admin, $this->kg, 'Colorante viejo', activa: false);

    expect(fn () => guardarReceta($this->admin, $this->fragancia, '10', [$this->agua->id => '1']))
        ->toThrow(ValidationException::class, 'Fragancia floral es materia prima: la receta es para el producto que se fabrica.')
        ->and(fn () => guardarReceta($this->admin, $inactivo, '10', [$this->agua->id => '1']))
        ->toThrow(ValidationException::class, 'Jabón viejo está inactivo.')
        ->and(fn () => guardarReceta($this->admin, $sinStock, '10', [$this->agua->id => '1']))
        ->toThrow(ValidationException::class, 'Servicio de mezcla no controla stock.')
        ->and(fn () => guardarReceta($this->admin, $this->suavizante, '0', [$this->agua->id => '1']))
        ->toThrow(ValidationException::class, 'Ingresa una cantidad base mayor que cero')
        ->and(fn () => guardarReceta($this->admin, $this->suavizante, '100', []))
        ->toThrow(ValidationException::class, 'Agrega al menos una materia prima.')
        ->and(fn () => guardarReceta($this->admin, $this->suavizante, '100', [$vendible->id => '1']))
        ->toThrow(ValidationException::class, 'Esponja no es materia prima.')
        ->and(fn () => guardarReceta($this->admin, $this->suavizante, '100', [$vieja->id => '1']))
        ->toThrow(ValidationException::class, 'Colorante viejo está inactiva.')
        ->and(fn () => guardarReceta($this->admin, $this->suavizante, '100', [$this->agua->id => '-2']))
        ->toThrow(ValidationException::class, 'Agua desmineralizada: la cantidad debe ser mayor que cero');

    expect(fn () => app(GuardarReceta::class)->ejecutar($this->admin, [
        'producto_id' => $this->suavizante->id, 'cantidad_base' => '100',
        'lineas' => [['producto_id' => $this->agua->id, 'cantidad' => '50'], ['producto_id' => $this->agua->id, 'cantidad' => '45']],
    ]))->toThrow(ValidationException::class, 'Agua desmineralizada está dos veces');

    expect(Receta::query()->count())->toBe(0);
});

test('P2: whole units reject decimals in the base and in the lines', function () {
    $barra = productoDe($this->empresa, ['nombre' => 'Jabón en barra']);

    expect(fn () => guardarReceta($this->admin, $barra, '10.5', [$this->agua->id => '1']))
        ->toThrow(ValidationException::class, 'Jabón en barra se mide en unidades enteras')
        ->and(fn () => guardarReceta($this->admin, $this->suavizante, '100', [$this->botella->id => '1.5']))
        ->toThrow(ValidationException::class, 'Botella 1 L se usa en unidades enteras (und.)');

    expect(guardarReceta($this->admin, $barra, '10', [$this->botella->id => '10', $this->agua->id => '0.125'])->version)->toBe(1);
});

test('P2: the database keeps a single current version per product', function () {
    $receta = guardarReceta($this->admin, $this->suavizante, '100', [$this->agua->id => '95']);

    expect(fn () => DB::table('recetas')->insert([
        'empresa_id' => $this->empresa->id, 'producto_id' => $this->suavizante->id, 'version' => 2, 'cantidad_base' => '1',
        'vigente' => true, 'activa' => true, 'usuario_id' => $this->admin->id, 'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(QueryException::class)
        ->and(fn () => DB::table('receta_items')->insert([
            'empresa_id' => $this->empresa->id, 'receta_id' => $receta->id, 'producto_id' => $this->fragancia->id, 'orden' => 2, 'cantidad' => '0',
        ]))->toThrow(QueryException::class);
});

test('P2: deactivating does not create a version, survives a new version and is audited once', function () {
    guardarReceta($this->admin, $this->suavizante, '100', [$this->agua->id => '95']);

    app(GuardarReceta::class)->cambiarEstado($this->admin, $this->suavizante, false);
    app(GuardarReceta::class)->cambiarEstado($this->admin, $this->suavizante, false);
    $v2 = guardarReceta($this->admin, $this->suavizante, '100', [$this->agua->id => '94'], ['version' => 1]);

    expect($v2->activa)->toBeFalse()
        ->and(Receta::query()->count())->toBe(2)
        ->and(DB::table('auditoria')->where('accion', 'receta.desactivada')->count())->toBe(1);

    app(GuardarReceta::class)->cambiarEstado($this->admin, $this->suavizante, true);
    expect($v2->fresh()->activa)->toBeTrue()->and(DB::table('auditoria')->where('accion', 'receta.activada')->count())->toBe(1);

    Livewire::test(RecetaDetalle::class, ['producto' => $this->suavizante])
        ->call('desactivar')
        ->assertSee('Desactivada')
        ->assertSeeHtml('data-test="activar-receta"');
    expect($v2->fresh()->activa)->toBeFalse();
});

test('P2: the form chooses the product, scans raw materials and saves; editing saves version 2', function () {
    Livewire::test(RecetaFormulario::class)
        ->set('buscarProducto', 'Suavizante')
        ->assertSee('Suavizante floral')
        ->call('elegirProducto', $this->suavizante->id)
        ->assertSet('productoId', $this->suavizante->id)
        ->set('cantidad_base', '100')
        ->call('agregarDesdeBuscador', $this->agua->sku)
        ->call('agregarProducto', $this->fragancia->id)
        ->call('agregarProducto', $this->suavizante->id)
        ->assertSet('lineas', [['id' => $this->agua->id, 'cantidad' => '1'], ['id' => $this->fragancia->id, 'cantidad' => '1']])
        ->set('lineas.0.cantidad', '95')
        ->set('lineas.1.cantidad', '2,5')
        ->call('guardar')
        ->assertRedirect(route('produccion.recetas.ver', $this->suavizante));

    expect(lineasDeReceta(Receta::query()->sole()))->toBe([[$this->agua->id, '95.000'], [$this->fragancia->id, '2.500']]);

    // Un producto que ya tiene receta no aparece al crear y, si se elige, abre su edición.
    Livewire::test(RecetaFormulario::class)->set('buscarProducto', 'Suavizante')->assertDontSee('Suavizante floral')
        ->call('elegirProducto', $this->suavizante->id)->assertRedirect(route('produccion.recetas.editar', $this->suavizante));

    Livewire::test(RecetaFormulario::class, ['producto' => $this->suavizante])
        ->assertSet('versionEditada', 1)
        ->assertSet('cantidad_base', '100')
        ->assertSee('Guardar versión 2')
        ->call('quitarLinea', $this->fragancia->id)
        ->call('agregarProducto', $this->botella->id)
        ->set('lineas.1.cantidad', '1.5')
        ->call('guardar')
        ->assertHasErrors('lineas.1.cantidad')
        ->set('lineas.1.cantidad', '100')
        ->call('guardar')
        ->assertRedirect(route('produccion.recetas.ver', $this->suavizante));

    expect(Receta::query()->where('vigente', true)->sole()->version)->toBe(2);
});

test('P2: the detail shows the history with old versions read-only, and the list warns what is missing', function () {
    guardarReceta($this->admin, $this->suavizante, '100', [$this->agua->id => '95', $this->fragancia->id => '2']);
    guardarReceta($this->admin, $this->suavizante, '100', [$this->agua->id => '96'], ['version' => 1]);
    $this->suavizante->forceFill(['controla_vencimiento' => true])->save();

    $this->get(route('produccion.recetas.ver', $this->suavizante))->assertOk()
        ->assertSee('Versión 2')->assertSee('Historial de versiones')->assertDontSee('Versión anterior · solo lectura');

    Livewire::test(RecetaDetalle::class, ['producto' => $this->suavizante])
        ->set('version', '1')
        ->assertSee('Versión anterior · solo lectura')
        ->assertSee('Fragancia floral');

    Livewire::test(Recetas::class)->assertSee('Suavizante floral')->assertSee('v2')->assertDontSee('Sin vida útil');
    $this->get(route('dashboard'))->assertSee(route('produccion.recetas'), false);

    // Un producto sin receta no tiene detalle.
    $this->get(route('produccion.recetas.ver', productoDe($this->empresa)))->assertNotFound();
});

test('P2: a seller gets 403, no capability gets 404, and company B cannot see or use what belongs to A', function () {
    guardarReceta($this->admin, $this->suavizante, '100', [$this->agua->id => '95']);

    $vendedor = User::factory()->vendedor($this->matriz)->create();
    $this->actingAs($vendedor)->get(route('produccion.recetas'))->assertForbidden();
    expect(fn () => guardarReceta($vendedor, $this->suavizante, '100', [$this->agua->id => '90'], ['version' => 1]))
        ->toThrow(AuthorizationException::class);

    [$empresaB, $adminB] = empresaConAdministrador();
    $this->actingAs($adminB);
    $this->get(route('produccion.recetas'))->assertNotFound();
    app(CambiarFuncionesEmpresa::class)->ejecutar($adminB, $empresaB, Rubro::General, [Capacidad::Produccion]);
    $litroB = recetaUnidad($empresaB, 'Litro', 'L', true);
    $propio = Producto::factory()->enUnidad($litroB)->porMedida()->create(['nombre' => 'Detergente B']);
    $aguaB = recetaMateria($adminB, $litroB, 'Agua B');

    $this->get(route('produccion.recetas'))->assertOk()->assertDontSee('Suavizante floral');
    $this->get(route('produccion.recetas.ver', $this->suavizante))->assertNotFound();
    $this->get(route('produccion.recetas.editar', $this->suavizante))->assertNotFound();

    expect(fn () => guardarReceta($adminB, $this->suavizante, '100', [$aguaB->id => '1']))
        ->toThrow(ValidationException::class, 'Elige un producto de tu empresa.')
        ->and(fn () => guardarReceta($adminB, $propio, '100', [$this->agua->id => '1']))
        ->toThrow(ValidationException::class, 'Una de las materias primas no existe.')
        ->and(fn () => app(GuardarReceta::class)->cambiarEstado($adminB, $this->suavizante, false))
        ->toThrow(NotFoundHttpException::class);

    expect(Receta::query()->withoutGlobalScopes()->where('empresa_id', $this->empresa->id)->sole()->activa)->toBeTrue();
});

test('the product form no longer has shelf life (removed 16/11/2026) and the column is gone', function () {
    Livewire::test(ProductoFormulario::class, ['producto' => $this->suavizante])
        ->assertDontSee('Vida útil')
        ->set('precio_consumidor', '20.00')
        ->call('guardar')
        ->assertHasNoErrors();

    expect($this->suavizante->fresh()->precio_consumidor)->toBe('20.00')
        ->and(Schema::hasColumn('productos', 'vida_util_dias'))->toBeFalse();
});
