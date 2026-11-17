<?php

namespace App\Livewire\Concerns;

use App\Domain\Compras\Actions\GuardarProveedor;
use App\Models\Proveedor;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * «+ Nuevo proveedor» junto al select de proveedor (Nuevo egreso, Nueva compra, Nueva obligación): crea el proveedor
 * sin salir del formulario y lo deja elegido en `proveedor_id`. Usa GuardarProveedor (Policy: solo administrador,
 * NIT único en la empresa). Si ya existe uno activo con el mismo nombre, lo elige en vez de duplicarlo; el doble clic
 * tampoco duplica porque el formulario se cierra al guardar.
 *
 * La vista incluye `livewire.partials.proveedor-rapido`.
 */
trait CreaProveedorRapido
{
    public bool $proveedorRapido = false;

    public string $proveedorNombre = '';

    public string $proveedorDocumento = '';

    public string $proveedorTelefono = '';

    public function puedeCrearProveedor(): bool
    {
        return Gate::allows('create', Proveedor::class);
    }

    public function abrirProveedorRapido(): void
    {
        $this->authorize('create', Proveedor::class);
        $this->resetProveedorRapido();
        $this->proveedorRapido = true;
    }

    public function cancelarProveedorRapido(): void
    {
        $this->resetProveedorRapido();
    }

    public function guardarProveedorRapido(GuardarProveedor $accion): void
    {
        if (! $this->proveedorRapido) {
            return;
        }

        /** @var User $actor */
        $actor = Auth::user();
        $nombre = trim($this->proveedorNombre);
        $existente = $nombre === '' ? null : Proveedor::query()->where('activo', true)->where('nombre', $nombre)->orderBy('id')->first();

        if ($existente !== null) {
            $this->authorize('create', Proveedor::class);
            $proveedor = $existente;
            $this->avisar(__('Ya tenías a :proveedor: quedó elegido.', ['proveedor' => $proveedor->nombre]));
        } else {
            try {
                $proveedor = $accion->crear($actor, ['nombre' => $this->proveedorNombre, 'documento' => $this->proveedorDocumento,
                    'telefono' => $this->proveedorTelefono]);
            } catch (ValidationException $e) {
                $campos = ['nombre' => 'proveedorNombre', 'documento' => 'proveedorDocumento', 'telefono' => 'proveedorTelefono'];
                $mensajes = [];

                foreach ($e->errors() as $clave => $lista) {
                    $mensajes[$campos[$clave] ?? 'proveedorNombre'] = $lista;
                }

                throw ValidationException::withMessages($mensajes);
            }

            $this->avisar(__('Proveedor :proveedor agregado y elegido.', ['proveedor' => $proveedor->nombre]));
        }

        $this->proveedor_id = (string) $proveedor->id;
        $this->resetErrorBag('proveedor_id');
        $this->resetProveedorRapido();
    }

    private function resetProveedorRapido(): void
    {
        $this->proveedorRapido = false;
        $this->proveedorNombre = '';
        $this->proveedorDocumento = '';
        $this->proveedorTelefono = '';
        $this->resetErrorBag(['proveedorNombre', 'proveedorDocumento', 'proveedorTelefono']);
    }
}
