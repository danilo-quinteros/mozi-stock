<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Auditoria\Auditor;
use App\Domain\Suscripciones\Actions\ConfigurarSuscripcion;
use App\Enums\Rol;
use App\Models\CategoriaEgreso;
use App\Models\Empresa;
use App\Models\Sucursal;
use App\Models\UnidadMedida;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Alta de una empresa junto con su sucursal «Casa Matriz», su primer administrador y, si se
 * indica, su suscripción SaaS (precio y primer vencimiento), en una sola transacción.
 */
final class CrearEmpresa
{
    public function __construct(
        private readonly Auditor $auditor,
        private readonly ConfigurarSuscripcion $configurarSuscripcion,
    ) {}

    /**
     * @param  array{nombre: string, razon_social?: string|null, nit?: string|null, email?: string|null, telefono?: string|null}  $datos
     * @param  array{name: string, email: string, password: string}  $administrador
     * @param  array{precio_mensual: string, vencimiento: string, plan_id?: int|null}|null  $suscripcion
     */
    public function ejecutar(User $actor, array $datos, array $administrador, ?array $suscripcion = null): Empresa
    {
        Gate::forUser($actor)->authorize('create', Empresa::class);

        return DB::transaction(function () use ($actor, $datos, $administrador, $suscripcion): Empresa {
            $empresa = new Empresa;
            $empresa->fill($datos);
            $empresa->activa = true;
            $empresa->siat_habilitado = false;
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
            // Cuenta aprovisionada por MoziStudios; no depende de la entrega de correo.
            $usuario->email_verified_at = Carbon::now();
            $usuario->save();

            $this->auditor->registrar('empresa.creada', $empresa, $actor, $empresa->id,
                despues: $empresa->only(['nombre', 'razon_social', 'nit', 'email', 'telefono', 'activa']));
            $this->auditor->registrar('sucursal.creada', $matriz, $actor, $empresa->id,
                despues: $matriz->only(['nombre', 'codigo', 'direccion', 'activa']));
            $this->auditor->registrar('usuario.creado', $usuario, $actor, $empresa->id,
                despues: ['name' => $usuario->name, 'email' => $usuario->email, 'rol' => Rol::Administrador->value]);

            if ($suscripcion !== null) {
                $this->configurarSuscripcion->ejecutar($actor, $empresa, $suscripcion['precio_mensual'], $suscripcion['vencimiento'], null, $suscripcion['plan_id'] ?? null);
            }

            return $empresa;
        });
    }
}
