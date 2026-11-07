<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Cuota de un plan de crédito. Reparte el saldo de la venta: la suma de los saldos de sus
 * cuotas es siempre ventas.saldo_pendiente. Solo la reducen cobros aplicados y devoluciones.
 *
 * @property int $id
 * @property int $empresa_id
 * @property int $venta_id
 * @property int $numero
 * @property Carbon $vencimiento
 * @property string $monto
 * @property string $saldo
 * @property-read Venta $venta
 */
class VentaCuota extends Model
{
    use BelongsToEmpresa;

    protected $table = 'venta_cuotas';

    protected function casts(): array
    {
        return [
            'vencimiento' => 'date',
            'monto' => 'decimal:2',
            'saldo' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Venta, $this>
     */
    public function venta(): BelongsTo
    {
        return $this->belongsTo(Venta::class);
    }
}
