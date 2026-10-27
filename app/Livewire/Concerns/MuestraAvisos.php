<?php

namespace App\Livewire\Concerns;

/**
 * Aviso breve tras una operación confirmada en servidor (estilo .toast del prototipo).
 */
trait MuestraAvisos
{
    public ?string $aviso = null;

    public string $avisoTipo = 'success';

    public int $avisoNumero = 0;

    protected function avisar(string $mensaje, string $tipo = 'success'): void
    {
        $this->aviso = $mensaje;
        $this->avisoTipo = $tipo;
        $this->avisoNumero++;
    }
}
