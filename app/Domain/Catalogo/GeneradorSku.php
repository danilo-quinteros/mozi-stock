<?php

namespace App\Domain\Catalogo;

use App\Domain\Tenancy\Numerador;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * SKU automático: las 3 primeras letras o números del nombre (sin tildes) y un correlativo de 3 dígitos
 * por prefijo y empresa: POLERA → POL-001, POL-002… Se llama dentro de la transacción que guarda el
 * producto: el numerador bloquea la serie y un rollback no consume números. Si alguien ya cargó a mano
 * ese código, se salta al siguiente libre.
 */
final class GeneradorSku
{
    private const SIN_LETRAS = 'PRD';

    public function __construct(private readonly Numerador $numerador) {}

    public static function prefijo(string $nombre): string
    {
        $limpio = preg_replace('/[^A-Z0-9]/', '', Str::upper(Str::ascii($nombre))) ?? '';

        return $limpio === '' ? self::SIN_LETRAS : substr($limpio, 0, 3);
    }

    public function generar(int $empresaId, string $nombre): string
    {
        $prefijo = self::prefijo($nombre);

        do {
            $sku = sprintf('%s-%03d', $prefijo, $this->numerador->siguiente($empresaId, 'SKU-'.$prefijo));
        } while (DB::table('productos')->where('empresa_id', $empresaId)->where('sku', $sku)->exists());

        return $sku;
    }
}
