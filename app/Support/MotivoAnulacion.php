<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

/**
 * Motivo obligatorio de una anulación: queda en el historial junto a quién y cuándo anuló.
 */
final class MotivoAnulacion
{
    public const MINIMO = 5;

    public const MAXIMO = 200;

    /**
     * @throws ValidationException
     */
    public static function validar(?string $valor): string
    {
        $motivo = trim((string) $valor);

        if (mb_strlen($motivo) < self::MINIMO || mb_strlen($motivo) > self::MAXIMO) {
            throw ValidationException::withMessages(['motivo' => __('Explica el motivo de la anulación (entre :min y :max caracteres).', ['min' => self::MINIMO, 'max' => self::MAXIMO])]);
        }

        return $motivo;
    }
}
