<?php

namespace App\Models;

use App\Enums\EstadoPagoSaas;
use App\Models\Concerns\BelongsToEmpresa;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Comprobante de pago a MoziStudios. Es dinero del SaaS: jamás entra en la caja ni en los
 * reportes de la empresa (T27). Subir el archivo no confirma el pago; solo la aprobación
 * del superadministrador renueva (T25). No se elimina.
 *
 * @property int $id
 * @property int $empresa_id
 * @property int $suscripcion_id
 * @property int $meses
 * @property string $precio_mensual
 * @property string $importe
 * @property string|null $nota
 * @property string $archivo_path
 * @property string $archivo_mime
 * @property int $archivo_bytes
 * @property EstadoPagoSaas $estado
 * @property int $enviado_por
 * @property int|null $revisado_por
 * @property Carbon|null $revisado_en
 * @property string|null $motivo_rechazo
 * @property CarbonImmutable|null $vencimiento_anterior
 * @property CarbonImmutable|null $vencimiento_nuevo
 * @property string $clave_idempotencia
 * @property Carbon $created_at
 * @property Carbon|null $updated_at
 * @property-read Empresa $empresa
 * @property-read User $remitente
 * @property-read User|null $revisor
 */
class PagoSaas extends Model
{
    use BelongsToEmpresa;

    protected $table = 'pagos_saas';

    protected function casts(): array
    {
        return [
            'meses' => 'integer',
            'precio_mensual' => 'decimal:2',
            'importe' => 'decimal:2',
            'archivo_bytes' => 'integer',
            'estado' => EstadoPagoSaas::class,
            'revisado_en' => 'datetime',
            'vencimiento_anterior' => 'date',
            'vencimiento_nuevo' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('Un comprobante de pago no se elimina.'));
    }

    public function codigo(): string
    {
        return 'CP-'.str_pad((string) $this->id, 5, '0', STR_PAD_LEFT);
    }

    public function esPdf(): bool
    {
        return $this->archivo_mime === 'application/pdf';
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function remitente(): BelongsTo
    {
        return $this->belongsTo(User::class, 'enviado_por');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function revisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revisado_por');
    }
}
