<?php

use App\Domain\Inventario\Actions\RegistrarAjuste;
use App\Domain\Ventas\Actions\ConfirmarVenta;
use App\Domain\Ventas\Actions\GuardarPedido;
use App\Models\Empresa;
use App\Models\Pedido;
use App\Models\Producto;
use App\Models\Sucursal;
use App\Models\User;
use App\Models\Venta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Empresa activa con su administrador. Crear los datos antes de autenticar: el modelo
 * impide registrar datos de otra empresa con un usuario autenticado.
 *
 * @param  array<string, mixed>  $empresa
 * @return array{0: Empresa, 1: User}
 */
function empresaConAdministrador(array $empresa = []): array
{
    $registro = Empresa::factory()->create($empresa);

    return [$registro, User::factory()->administrador($registro)->create()];
}

/**
 * Producto activo de la empresa (unidad sin decimales salvo que se indique otra).
 *
 * @param  array<string, mixed>  $atributos
 */
function productoDe(Empresa $empresa, array $atributos = []): Producto
{
    return Producto::factory()->create(['empresa_id' => $empresa->id] + $atributos);
}

/**
 * Carga stock con una entrada «Reposición manual» real (la única vía permitida).
 * Requiere que $admin esté autenticado.
 */
function cargarStock(User $admin, Sucursal $sucursal, Producto $producto, string $cantidad): void
{
    app(RegistrarAjuste::class)->ejecutar($admin, [
        'tipo' => 'entrada', 'motivo' => 'reposicion_manual', 'sucursal_id' => $sucursal->id,
        'nota' => 'Stock inicial de prueba', 'lineas' => [$producto->id => $cantidad], 'clave' => (string) Str::uuid(),
    ]);
}

/**
 * Guarda un pedido pendiente con la acción real. Requiere que $actor esté autenticado.
 *
 * @param  list<array<string, mixed>>  $lineas
 * @param  array<string, mixed>  $extra
 */
function crearPedido(User $actor, Sucursal $sucursal, array $lineas, array $extra = []): Pedido
{
    return app(GuardarPedido::class)->ejecutar($actor, null, $extra + [
        'sucursal_id' => $sucursal->id, 'cliente_id' => null, 'vendedor_id' => null,
        'lineas' => array_map(fn (array $l) => $l + ['tipo_precio' => 'consumidor', 'descuento_tipo' => 'bs', 'descuento_valor' => '0'], $lineas),
        'descuento_global_tipo' => 'bs', 'descuento_global_valor' => '0', 'nota' => null,
        'pago_metodo' => null, 'pago_en_cuotas' => false, 'pago_cuota_inicial' => null,
        'pago_efectivo' => null, 'pago_electronico' => null, 'clave' => (string) Str::uuid(),
    ]);
}

/**
 * @param  array<string, mixed>  $pago
 */
function confirmarVenta(User $actor, Pedido $pedido, array $pago): Venta
{
    return app(ConfirmarVenta::class)->ejecutar($actor, $pedido, $pago + [
        'metodo' => null, 'en_cuotas' => false, 'cuota_inicial' => null, 'efectivo' => null,
        'electronico' => null, 'clave' => (string) Str::uuid(),
    ]);
}

/**
 * PNG real de 1×1 (el entorno no tiene GD para UploadedFile::fake()->image()).
 */
function imagenPng(string $nombre = 'imagen.png'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($nombre, (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));
}

function stockDe(Sucursal $sucursal, Producto $producto): string
{
    return (string) (DB::table('stock_niveles')->where('sucursal_id', $sucursal->id)
        ->where('producto_id', $producto->id)->value('cantidad') ?? '0.000');
}
