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
 * @property string $tipo renovacion | extras
 * @property int|null $meses null en un comprobante de extras
 * @property string $precio_mensual plan + extras
 * @property string|null $precio_plan
 * @property int|null $sucursales_extra
 * @property string|null $precio_sucursal_extra
 * @property int|null $paquetes_usuarios
 * @property string|null $precio_paquete_usuarios
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
            'precio_plan' => 'decimal:2',
            'sucursales_extra' => 'integer',
            'precio_sucursal_extra' => 'decimal:2',
            'paquetes_usuarios' => 'integer',
            'precio_paquete_usuarios' => 'decimal:2',
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

    public const TIPO_RENOVACION = 'renovacion';

    public const TIPO_EXTRAS = 'extras';

    public function esExtras(): bool
    {
        return $this->tipo === self::TIPO_EXTRAS;
    }

    /** «Extras: 2 sucursales · 1 paquete de usuarios» o «3 meses». */
    public function concepto(): string
    {
        if (! $this->esExtras()) {
            return trans_choice(':count mes|:count meses', (int) $this->meses);
        }

        return __('Extras: :detalle', ['detalle' => implode(' · ', array_filter([
            $this->sucursales_extra ? trans_choice(':count sucursal extra|:count sucursales extra', $this->sucursales_extra) : null,
            $this->paquetes_usuarios ? trans_choice(':count paquete de usuarios|:count paquetes de usuarios', $this->paquetes_usuarios) : null,
        ])) ?: __('sin extras')]);
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
