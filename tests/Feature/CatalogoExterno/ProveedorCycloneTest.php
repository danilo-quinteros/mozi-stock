<?php

use App\Domain\CatalogoExterno\Actions\VincularEmpresaProveedor;
use App\Models\Auditoria;
use App\Models\Proveedor;
use App\Models\ProveedorExterno;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/*
 * Una empresa distribuidora de Cyclone le compra a Cyclone: al vincularla queda registrado como proveedor de compras
 * (Cyclone, NIT 7687402012, Santa Cruz de la Sierra, 77092733, ventas@cyclonelimpieza.com), sin duplicarlo.
 */

beforeEach(function () {
    [$this->empresa, $this->admin] = empresaConAdministrador();
    $this->cyclone = ProveedorExterno::query()->firstOrCreate(['codigo' => 'cyclone'],
        ['nombre' => 'Cyclone', 'driver' => 'cyclone', 'base_url' => 'https://cyclone.test/api/v1', 'activo' => true]);
    $this->superadmin = User::factory()->superadmin()->create();
    $this->actingAs($this->superadmin);
});

function vincularCyclone(object $prueba, bool $activo = true): void
{
    app(VincularEmpresaProveedor::class)->ejecutar($prueba->superadmin, $prueba->empresa, $prueba->cyclone, $activo);
}

function proveedoresDe(int $empresaId)
{
    return Proveedor::withoutGlobalScopes()->where('empresa_id', $empresaId)->orderBy('id')->get();
}

function proveedorManual(int $empresaId, array $datos): Proveedor
{
    $proveedor = new Proveedor;
    $proveedor->empresa_id = $empresaId;
    $proveedor->forceFill($datos)->save();

    return $proveedor;
}

test('al vincular la empresa como distribuidora de Cyclone queda Cyclone como proveedor de compras, una sola vez', function () {
    vincularCyclone($this);

    $proveedor = proveedoresDe($this->empresa->id)->sole();
    expect($proveedor->only(['nombre', 'documento', 'ciudad', 'telefono', 'correo', 'activo']))->toBe([
        'nombre' => 'Cyclone', 'documento' => '7687402012', 'ciudad' => 'Santa Cruz de la Sierra',
        'telefono' => '77092733', 'correo' => 'ventas@cyclonelimpieza.com', 'activo' => true,
    ])->and(Auditoria::query()->where('accion', 'empresa.proveedor_externo_vinculado')->latest('id')->first()->despues['proveedor_compras_id'])
        ->toBe($proveedor->id);

    // Desvincular no toca el proveedor (puede tener compras); volver a vincular no lo duplica.
    vincularCyclone($this, false);
    expect(proveedoresDe($this->empresa->id)->sole()->activo)->toBeTrue();
    vincularCyclone($this);
    expect(proveedoresDe($this->empresa->id))->toHaveCount(1);
});

test('si la empresa ya lo había creado a mano sin NIT, se completan los datos que faltan sin cambiar su nombre', function () {
    $manual = proveedorManual($this->empresa->id, ['nombre' => 'Cyclone Bolivia', 'telefono' => '70000000']);

    vincularCyclone($this);

    $proveedor = proveedoresDe($this->empresa->id)->sole();
    expect($proveedor->id)->toBe($manual->id)
        ->and($proveedor->nombre)->toBe('Cyclone Bolivia')
        ->and($proveedor->telefono)->toBe('70000000')
        ->and($proveedor->documento)->toBe('7687402012')
        ->and($proveedor->ciudad)->toBe('Santa Cruz de la Sierra')
        ->and($proveedor->correo)->toBe('ventas@cyclonelimpieza.com');
});

test('con el mismo NIT ya registrado no se duplica ni se pisan sus datos; si estaba inactivo se reactiva', function () {
    $existente = proveedorManual($this->empresa->id, ['nombre' => 'Cyclone SRL', 'documento' => '7687402012', 'correo' => 'compras@cyclone.test', 'activo' => false]);
    proveedorManual($this->empresa->id, ['nombre' => 'Otro proveedor']);

    vincularCyclone($this);

    $proveedor = $existente->fresh();
    expect(proveedoresDe($this->empresa->id))->toHaveCount(2)
        ->and($proveedor->nombre)->toBe('Cyclone SRL')
        ->and($proveedor->correo)->toBe('compras@cyclone.test')
        ->and($proveedor->ciudad)->toBe('Santa Cruz de la Sierra')
        ->and($proveedor->activo)->toBeTrue();
});

test('aislamiento: solo se registra en la empresa vinculada y no toca el proveedor «Cyclone» de otra empresa', function () {
    [$empresaB] = empresaConAdministrador();
    $deB = proveedorManual($empresaB->id, ['nombre' => 'Cyclone']);

    vincularCyclone($this);

    expect(proveedoresDe($this->empresa->id))->toHaveCount(1)
        ->and($deB->fresh()->documento)->toBeNull()
        ->and(proveedoresDe($empresaB->id))->toHaveCount(1);
});

test('la migración registra Cyclone en las distribuidoras ya vinculadas, completa el manual y se puede repetir', function () {
    [$empresaB] = empresaConAdministrador();
    [$empresaC] = empresaConAdministrador();
    [$empresaInactiva] = empresaConAdministrador();
    // Vínculos anteriores a esta versión: sin proveedor de compras.
    foreach ([[$this->empresa->id, true], [$empresaB->id, true], [$empresaInactiva->id, false]] as [$empresaId, $activo]) {
        DB::table('empresa_proveedor_externo')->insert(['empresa_id' => $empresaId, 'proveedor_externo_id' => $this->cyclone->id,
            'activo' => $activo, 'vinculado_por' => $this->superadmin->id, 'created_at' => now(), 'updated_at' => now()]);
    }
    $manualB = proveedorManual($empresaB->id, ['nombre' => 'Cyclone', 'correo' => 'pedidos@cyclone.test']);

    $migracion = require database_path('migrations/2026_11_17_100000_registrar_proveedor_cyclone_en_distribuidoras.php');
    $migracion->up();
    $migracion->up();

    expect(proveedoresDe($this->empresa->id)->sole()->documento)->toBe('7687402012')
        ->and(proveedoresDe($empresaB->id)->sole()->id)->toBe($manualB->id)
        ->and($manualB->fresh()->documento)->toBe('7687402012')
        ->and($manualB->fresh()->correo)->toBe('pedidos@cyclone.test')
        ->and(proveedoresDe($empresaC->id))->toHaveCount(0)
        ->and(proveedoresDe($empresaInactiva->id))->toHaveCount(0);
});
