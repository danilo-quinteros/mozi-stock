<?php

namespace App\Livewire\Concerns;

use App\Domain\Ventas\Actions\GuardarCliente;
use App\Models\Cliente;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;

/**
 * Cliente de una venta (pedidos y POS): buscar por nombre o documento, elegir, quitar
 * (consumidor final) o registrar uno nuevo con los datos mínimos. El scope de empresa
 * impide elegir clientes de otra empresa; la acción de dominio vuelve a validarlo.
 */
trait EligeCliente
{
    #[Locked]
    public ?int $cliente_id = null;

    public string $buscarCliente = '';

    public bool $formCliente = false;

    public string $clienteNombre = '';

    public string $clienteDocumento = '';

    public string $clienteTelefono = '';

    public function buscarClientes(): void
    {
        // La búsqueda se resuelve en render(); este método solo fuerza la actualización.
    }

    public function seleccionarCliente(int $id): void
    {
        $cliente = Cliente::query()->where('activo', true)->findOrFail($id);
        $this->cliente_id = $cliente->id;
        $this->buscarCliente = '';
        $this->resetValidation('cliente_id');
        $this->clienteElegido();
    }

    public function quitarCliente(): void
    {
        $this->cliente_id = null;
        $this->clienteElegido();
    }

    public function nuevoCliente(): void
    {
        $this->authorize('create', Cliente::class);
        $this->reset('clienteNombre', 'clienteDocumento', 'clienteTelefono');
        $this->clienteNombre = trim($this->buscarCliente);
        $this->resetValidation();
        $this->formCliente = true;
    }

    public function crearCliente(GuardarCliente $accion): void
    {
        $cliente = $accion->crear($this->actor(), [
            'nombre' => $this->clienteNombre, 'documento' => $this->clienteDocumento, 'telefono' => $this->clienteTelefono,
        ]);
        $this->cliente_id = $cliente->id;
        $this->buscarCliente = '';
        $this->formCliente = false;
        $this->clienteElegido();
        $this->avisar(__('Cliente :nombre registrado.', ['nombre' => $cliente->nombre]));
    }

    /**
     * Punto de extensión: la pantalla puede reaccionar al cambio de cliente.
     */
    protected function clienteElegido(): void {}

    protected function clienteActual(): ?Cliente
    {
        return $this->cliente_id === null ? null : Cliente::query()->find($this->cliente_id);
    }

    /**
     * @return Collection<int, Cliente>
     */
    protected function clientesEncontrados(): Collection
    {
        $termino = trim($this->buscarCliente);

        if (mb_strlen($termino) < 2) {
            return collect();
        }

        $patron = '%'.addcslashes($termino, '\\%_').'%';

        return Cliente::query()->where('activo', true)
            ->where(fn (Builder $q) => $q->where('nombre', 'like', $patron)->orWhere('documento', 'like', $patron))
            ->orderBy('nombre')->limit(6)->get(['id', 'nombre', 'documento']);
    }
}
