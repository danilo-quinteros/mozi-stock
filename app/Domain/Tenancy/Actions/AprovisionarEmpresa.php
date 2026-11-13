<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Auditoria\Auditor;
use App\Domain\Caja\Actions\CrearCajaPrincipal;
use App\Domain\Catalogo\ListasSugeridas;
use App\Enums\Capacidad;
use App\Enums\Rol;
use App\Enums\Rubro;
use App\Models\Categoria;
use App\Models\CategoriaEgreso;
use App\Models\Empresa;
use App\Models\Sucursal;
use App\Models\UnidadMedida;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Crea una empresa lista para trabajar: unidades y categorías base, sucursal «Casa Matriz» con
 * su caja principal y el primer administrador. No autoriza ni abre transacción: lo hacen quien
 * la llama (CrearEmpresa para el superadmin, RegistrarEmpresaPrueba para el registro público).
 */
final class AprovisionarEmpresa
{
    public function __construct(
        private readonly Auditor $auditor,
        private readonly CrearCajaPrincipal $crearCajaPrincipal,
        private readonly ListasSugeridas $listas,
    ) {}

    /**
     * @param  User|null  $actor  null cuando la persona se registra sola: actúa su propio usuario.
     * @param  array{nombre: string, rubro: Rubro, razon_social?: string|null, nit?: string|null, email?: string|null, telefono?: string|null}  $datos
     * @param  array{name: string, email: string, password: string}  $administrador
     * @return array{0: Empresa, 1: User}
     */
    public function ejecutar(?User $actor, array $datos, array $administrador, bool $correoVerificado, bool $siatHabilitado = false): array
    {
        $rubro = $datos['rubro'];

        $empresa = new Empresa;
        $empresa->fill(Arr::except($datos, ['rubro']));
        $empresa->activa = true;
        $empresa->siat_habilitado = $siatHabilitado;
        $empresa->rubro = $rubro;
        $empresa->save();

        foreach (UnidadMedida::BASE as $base) {
            $unidad = new UnidadMedida($base);
            $unidad->empresa_id = $empresa->id;
            $unidad->save();
        }

        foreach (CategoriaEgreso::BASE as $base) {
            $categoria = new CategoriaEgreso($base);
            $categoria->empresa_id = $empresa->id;
            $categoria->save();
        }

        foreach ($rubro->categoriasSugeridas() as $nombre) {
            $categoria = new Categoria(['nombre' => $nombre]);
            $categoria->empresa_id = $empresa->id;
            $categoria->save();
        }

        // Sucursal principal: la empresa puede vender y asignar vendedores desde el primer día.
        $matriz = new Sucursal(['nombre' => 'Casa Matriz', 'codigo' => 'CM']);
        $matriz->empresa_id = $empresa->id;
        $matriz->activa = true;
        $matriz->save();

        $usuario = new User;
        $usuario->name = $administrador['name'];
        $usuario->email = Str::lower($administrador['email']);
        $usuario->password = $administrador['password'];
        $usuario->empresa_id = $empresa->id;
        $usuario->rol = Rol::Administrador;
        $usuario->activo = true;
        // Una cuenta aprovisionada por MoziStudios no depende de la entrega de correo; un registro público sí.
        $usuario->email_verified_at = $correoVerificado ? Carbon::now() : null;
        $usuario->save();

        $actor ??= $usuario;

        // El rubro deja encendidas sus capacidades desde el primer ingreso, en la misma transacción.
        $capacidades = array_map(fn (Capacidad $c): string => $c->value, $rubro->capacidades());

        if ($capacidades !== []) {
            $ahora = now();
            DB::table('empresa_capacidades')->insert(array_map(fn (string $capacidad): array => [
                'empresa_id' => $empresa->id, 'capacidad' => $capacidad, 'activa' => true,
                'cambiada_por' => $actor->id, 'created_at' => $ahora, 'updated_at' => $ahora,
            ], $capacidades));
        }

        // Datos iniciales por rubro: la lista sugerida de tallas y colores (ropa), editable después (tallas § 3.1).
        if (in_array(Capacidad::Variantes, $rubro->capacidades(), true)) {
            $this->listas->crearSiVacias($empresa->id);
        }

        $this->crearCajaPrincipal->ejecutar($actor, $matriz);

        $this->auditor->registrar('empresa.creada', $empresa, $actor, $empresa->id,
            despues: $empresa->only(['nombre', 'razon_social', 'nit', 'email', 'telefono', 'activa'])
                + ['rubro' => $rubro->value, 'capacidades' => $capacidades]);
        $this->auditor->registrar('sucursal.creada', $matriz, $actor, $empresa->id,
            despues: $matriz->only(['nombre', 'codigo', 'direccion', 'activa']));
        $this->auditor->registrar('usuario.creado', $usuario, $actor, $empresa->id,
            despues: ['name' => $usuario->name, 'email' => $usuario->email, 'rol' => Rol::Administrador->value]);

        return [$empresa, $usuario];
    }
}
