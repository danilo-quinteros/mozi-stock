<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use MoziStudios\Siat\Codigos\Cufd;

/**
 * CUFD de un punto de venta (vigencia de 24 horas). Historial de solo inserción.
 *
 * @property int $id
 * @property int $empresa_id
 * @property int $siat_punto_venta_id
 * @property int $siat_cuis_id
 * @property string $codigo
 * @property string $codigo_control
 * @property string $codigo_interno
 * @property string|null $direccion
 * @property Carbon $vence_en
 * @property Carbon|null $created_at
 */
class SiatCufd extends Model
{
    use BelongsToEmpresa;

    public const UPDATED_AT = null;

    protected $table = 'siat_cufd';

    protected function casts(): array
    {
        return [
            'vence_en' => 'datetime',
        ];
    }

    /** El CUFD con la forma que necesita el paquete para armar la factura. */
    public function paraFactura(): Cufd
    {
        return new Cufd($this->codigo, $this->codigo_control, $this->codigo_interno, $this->direccion, $this->vence_en->toDateTimeImmutable());
    }
}
