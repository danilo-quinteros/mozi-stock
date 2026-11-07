<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEmpresa;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Facade;
use MoziStudios\Siat\Sincronizacion\Catalogo;

/**
 * @property int $id
 * @property int $empresa_id
 * @property string $nombre
 * @property string $abreviatura
 * @property int|null $unidad_medida_sin
 * @property bool $admite_decimales
 * @property bool $activa
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['nombre', 'abreviatura', 'unidad_medida_sin', 'admite_decimales', 'activa'])]
class UnidadMedida extends Model
{
    use BelongsToEmpresa;

    /**
     * Unidades con las que nace cada empresa.
     * Mapeadas con los códigos oficiales del catálogo SIAT de Bolivia (SIN):
     * - Unidad (bienes): 57
     * - Kilogramo: 22
     * - Gramo: 17
     * - Litro: 28
     * - Mililitro: 34
     * - Caja: 6
     */
    public const BASE = [
        ['nombre' => 'Unidad', 'abreviatura' => 'und.', 'admite_decimales' => false, 'unidad_medida_sin' => 57],
        ['nombre' => 'Kilogramo', 'abreviatura' => 'kg', 'admite_decimales' => true, 'unidad_medida_sin' => 22],
        ['nombre' => 'Gramo', 'abreviatura' => 'g', 'admite_decimales' => false, 'unidad_medida_sin' => 17],
        ['nombre' => 'Litro', 'abreviatura' => 'L', 'admite_decimales' => true, 'unidad_medida_sin' => 28],
        ['nombre' => 'Mililitro', 'abreviatura' => 'ml', 'admite_decimales' => false, 'unidad_medida_sin' => 34],
        ['nombre' => 'Caja', 'abreviatura' => 'cj.', 'admite_decimales' => false, 'unidad_medida_sin' => 6],
    ];

    /**
     * Resuelve el código numérico del catálogo SIN/SIAT a partir de una descripción,
     * código clasificador o abreviatura comercial.
     */
    public static function resolverCodigoSin(?string $valor, ?string $abreviatura = null): ?int
    {
        if (filled($valor) && is_numeric($valor)) {
            return (int) $valor;
        }

        $candidatos = array_filter([$valor, $abreviatura]);
        foreach ($candidatos as $cand) {
            $clean = mb_strtolower(trim((string) $cand, ". \t\n\r\0\x0B"));
            $mapeado = match ($clean) {
                'litro', 'litros', 'l', 'lt', 'lts' => 28,
                'kilogramo', 'kilogramos', 'kilo', 'kilos', 'kg', 'kgs', 'k' => 22,
                'gramo', 'gramos', 'g', 'gr', 'grs' => 17,
                'mililitro', 'mililitros', 'ml', 'mls', 'cc' => 34,
                'caja', 'cajas', 'cj', 'cj.' => 6,
                'unidad', 'unidades', 'und', 'und.', 'pza', 'pza.', 'pieza', 'piezas', 'unidad (bienes)', 'unidad bienes' => 57,
                'unidad (servicios)', 'unidad servicios' => 58,
                'paquete', 'paquetes', 'paq', 'paq.', 'pack' => 42,
                'metro', 'metros', 'm', 'mt', 'mts' => 30,
                'botella', 'botellas', 'bot' => 5,
                'lata', 'latas' => 26,
                'bolsa', 'bolsas' => 4,
                'balde', 'baldes' => 2,
                'bidon', 'bidón', 'bidones' => 74,
                default => null,
            };

            if ($mapeado !== null) {
                return $mapeado;
            }

            // Unidades de medida es un catálogo paramétrico nacional del SIN (Catalogo::globales()):
            // sus filas son iguales en todas las empresas, por eso se consulta sin filtrar por empresa.
            $norm = (string) preg_replace('/\s+/u', ' ', mb_strtoupper(trim((string) $cand)));
            if ($norm !== '' && Facade::getFacadeApplication()?->bound('db') === true) {
                $comodin = addcslashes($norm, '\\%_');

                try {
                    $enSiat = SiatCatalogo::withoutGlobalScopes()
                        ->where('catalogo', Catalogo::UnidadesMedida->name)
                        ->where(fn ($q) => $q->where('descripcion', $norm)
                            ->orWhere('descripcion', 'like', $comodin.' %')
                            ->orWhere('descripcion', 'like', '% '.$comodin))
                        ->orderByRaw('CAST(codigo AS UNSIGNED)')
                        ->value('codigo');

                    if (is_string($enSiat) && ctype_digit($enSiat)) {
                        return (int) $enSiat;
                    }
                } catch (QueryException) {
                    // Sin base de datos o sin la tabla todavía: queda solo el mapeo fijo de arriba.
                }
            }
        }

        return null;
    }

    protected $table = 'unidades_medida';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'admite_decimales' => false,
        'activa' => true,
    ];

    protected function casts(): array
    {
        return [
            'unidad_medida_sin' => 'integer',
            'admite_decimales' => 'boolean',
            'activa' => 'boolean',
        ];
    }
}
