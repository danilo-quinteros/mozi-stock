<?php

namespace App\Domain\Creditos\Actions;

use App\Models\Cobro;
use App\Models\User;
use App\Models\Venta;
use Illuminate\Http\UploadedFile;

/**
 * Abono posterior a una venta a crédito (especificación § 5.3 y V3 § 6), desde su historial de
 * pagos. Es un cobro con alcance de esa sola venta: si la venta tiene plan de cuotas, se aplica a
 * las cuotas más antiguas (o a las elegidas en modo manual). Decisiones del propietario
 * (23/10/2026): la fecha es siempre la actual y un vendedor cobra cualquier crédito de su empresa
 * en su propia sucursal; el vendedor original de la venta no cambia. Ver RegistrarCobro.
 */
final class RegistrarAbono
{
    public function __construct(private readonly RegistrarCobro $cobros) {}

    /**
     * @param  array{sucursal_id: int|null, metodo: string|null, monto: string|null, efectivo: string|null, electronico: string|null, nota: string|null, clave: string, referencia?: string|null, externo?: bool, comprobante?: UploadedFile|null, modo?: string|null, asignaciones?: array<array-key, mixed>}  $datos
     */
    public function ejecutar(User $actor, Venta $venta, array $datos): Cobro
    {
        return $this->cobros->ejecutar($actor, ['venta_id' => $venta->id] + $datos);
    }
}
