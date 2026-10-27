<?php

namespace App\Domain\Compras\Actions;

use App\Models\Proveedor;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Alta y edición de proveedores de la empresa. No se eliminan: se desactivan y conservan
 * sus compras.
 */
final class GuardarProveedor
{
    /**
     * @param  array{nombre: string, documento?: string|null, telefono?: string|null, correo?: string|null, ciudad?: string|null}  $datos
     */
    public function crear(User $actor, array $datos): Proveedor
    {
        Gate::forUser($actor)->authorize('create', Proveedor::class);

        $proveedor = new Proveedor;
        $proveedor->empresa_id = (int) $actor->empresa_id;
        $proveedor->fill($this->normalizar($actor, $datos, null));
        $proveedor->save();

        return $proveedor;
    }

    /**
     * @param  array{nombre: string, documento?: string|null, telefono?: string|null, correo?: string|null, ciudad?: string|null}  $datos
     */
    public function actualizar(User $actor, Proveedor $proveedor, array $datos): Proveedor
    {
        Gate::forUser($actor)->authorize('update', $proveedor);

        $proveedor->fill($this->normalizar($actor, $datos, $proveedor->id));
        $proveedor->save();

        return $proveedor;
    }

    public function cambiarEstado(User $actor, Proveedor $proveedor, bool $activo): Proveedor
    {
        Gate::forUser($actor)->authorize('update', $proveedor);

        $proveedor->activo = $activo;
        $proveedor->save();

        return $proveedor;
    }

    /**
     * @param  array{nombre: string, documento?: string|null, telefono?: string|null, correo?: string|null, ciudad?: string|null}  $datos
     * @return array{nombre: string, documento: string|null, telefono: string|null, correo: string|null, ciudad: string|null}
     */
    private function normalizar(User $actor, array $datos, ?int $ignorar): array
    {
        $nombre = $this->texto($datos, 'nombre', 150);
        $documento = $this->texto($datos, 'documento', 30);
        $correo = $this->texto($datos, 'correo', 150);

        if ($nombre === null) {
            throw ValidationException::withMessages(['nombre' => __('Escribe el nombre del proveedor.')]);
        }

        if ($documento !== null && DB::table('proveedores')->where('empresa_id', $actor->empresa_id)
            ->where('documento', $documento)->when($ignorar !== null, fn ($q) => $q->where('id', '<>', $ignorar))->exists()) {
            throw ValidationException::withMessages(['documento' => __('Ya existe un proveedor con ese NIT/CI en tu empresa.')]);
        }

        if ($correo !== null && filter_var($correo, FILTER_VALIDATE_EMAIL) === false) {
            throw ValidationException::withMessages(['correo' => __('Escribe un correo válido.')]);
        }

        return [
            'nombre' => $nombre,
            'documento' => $documento,
            'telefono' => $this->texto($datos, 'telefono', 40),
            'correo' => $correo === null ? null : mb_strtolower($correo),
            'ciudad' => $this->texto($datos, 'ciudad', 80),
        ];
    }

    /**
     * @param  array<string, string|null>  $datos
     */
    private function texto(array $datos, string $campo, int $maximo): ?string
    {
        $texto = trim((string) ($datos[$campo] ?? ''));

        if (mb_strlen($texto) > $maximo) {
            throw ValidationException::withMessages([$campo => __('Admite hasta :maximo caracteres.', ['maximo' => $maximo])]);
        }

        return $texto === '' ? null : $texto;
    }
}
