<?php

namespace MoziStudios\Siat\Configuracion;

use Closure;
use LogicException;
use MoziStudios\Siat\Excepciones\DatosInvalidos;
use SensitiveParameter;

/**
 * Token delegado que el contribuyente genera en el portal SIAT para este sistema. Es un secreto:
 * se guarda dentro de un cierre para que var_dump, print_r, var_export, json_encode y los logs
 * no lo muestren, y no se puede serializar. Solo `valor()` lo entrega, para la cabecera HTTP.
 */
final class TokenDelegado
{
    private readonly Closure $valor;

    public function __construct(#[SensitiveParameter] string $valor)
    {
        $valor = trim($valor);

        if ($valor === '' || preg_match('/[\r\n\s]/', $valor) === 1) {
            throw new DatosInvalidos('El token delegado está vacío o contiene espacios o saltos de línea.');
        }

        $this->valor = static fn (): string => $valor;
    }

    public function valor(): string
    {
        return ($this->valor)();
    }

    /** Últimos 4 caracteres, para identificar qué token se usó sin revelarlo. */
    public function sufijo(): string
    {
        return '…'.substr($this->valor(), -4);
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['valor' => '[oculto]'];
    }

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new LogicException('El token delegado no se serializa.');
    }
}
