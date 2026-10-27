<?php

namespace App\Domain\Ventas\Actions;

use App\Models\Cliente;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Alta y edición de clientes (desde el pedido o desde Clientes). Los errores usan las claves
 * clienteNombre, clienteDocumento, clienteTelefono y clienteCorreo de ambos formularios.
 * Un cliente no se elimina: se desactiva y conserva su historial.
 */
final class GuardarCliente
{
    /**
     * @param  array{nombre: string, documento?: string|null, telefono?: string|null, correo?: string|null}  $datos
     */
    public function crear(User $actor, array $datos): Cliente
    {
        Gate::forUser($actor)->authorize('create', Cliente::class);

        $cliente = new Cliente;
        $cliente->empresa_id = (int) $actor->empresa_id;
        $cliente->fill($this->normalizar($actor, $datos, null));
        $cliente->save();

        return $cliente;
    }

    /**
     * @param  array{nombre: string, documento?: string|null, telefono?: string|null, correo?: string|null}  $datos
     */
    public function actualizar(User $actor, Cliente $cliente, array $datos): Cliente
    {
        Gate::forUser($actor)->authorize('update', $cliente);

        $cliente->fill($this->normalizar($actor, $datos, $cliente->id));
        $cliente->save();

        return $cliente;
    }

    public function cambiarEstado(User $actor, Cliente $cliente, bool $activo): Cliente
    {
        Gate::forUser($actor)->authorize('cambiarEstado', $cliente);

        $cliente->activo = $activo;
        $cliente->save();

        return $cliente;
    }

    /**
     * @param  array{nombre: string, documento?: string|null, telefono?: string|null, correo?: string|null}  $datos
     * @return array{nombre: string, documento: string|null, telefono: string|null, correo: string|null}
     */
    private function normalizar(User $actor, array $datos, ?int $ignorar): array
    {
        $nombre = trim($datos['nombre']);
        $documento = trim((string) ($datos['documento'] ?? ''));
        $telefono = trim((string) ($datos['telefono'] ?? ''));
        $correo = trim((string) ($datos['correo'] ?? ''));

        if ($nombre === '' || mb_strlen($nombre) > 150) {
            throw ValidationException::withMessages(['clienteNombre' => __('Escribe el nombre del cliente (hasta 150 caracteres).')]);
        }

        if (mb_strlen($documento) > 30) {
            throw ValidationException::withMessages(['clienteDocumento' => __('El CI/NIT admite hasta 30 caracteres.')]);
        }

        if ($documento !== '' && DB::table('clientes')->where('empresa_id', $actor->empresa_id)->where('documento', $documento)
            ->when($ignorar !== null, fn ($q) => $q->where('id', '<>', $ignorar))->exists()) {
            throw ValidationException::withMessages(['clienteDocumento' => __('Ya existe un cliente con ese CI/NIT en tu empresa.')]);
        }

        if (mb_strlen($telefono) > 40) {
            throw ValidationException::withMessages(['clienteTelefono' => __('El teléfono admite hasta 40 caracteres.')]);
        }

        if ($correo !== '' && (mb_strlen($correo) > 150 || filter_var($correo, FILTER_VALIDATE_EMAIL) === false)) {
            throw ValidationException::withMessages(['clienteCorreo' => __('Escribe un correo válido.')]);
        }

        return [
            'nombre' => $nombre,
            'documento' => $documento === '' ? null : $documento,
            'telefono' => $telefono === '' ? null : $telefono,
            'correo' => $correo === '' ? null : mb_strtolower($correo),
        ];
    }
}
