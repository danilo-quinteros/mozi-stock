<?php

namespace App\Models;

use App\Models\Concerns\Anulable;
use App\Models\Concerns\BelongsToEmpresa;
use App\Support\Decimal;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Obligación pendiente de pago, nacida de una compra o un egreso con saldo, o registrada a
 * mano. Es la única fuente de su saldo: solo los pagos de esta cuenta lo reducen. El administrador
 * puede anular una obligación manual sin pagos vigentes; anulada ya no cambia.
 *
 * @property int $id
 * @property int $empresa_id
 * @property int $numero
 * @property string $origen
 * @property int|null $compra_id
 * @property int|null $egreso_id
 * @property int $sucursal_id
 * @property int $proveedor_id
 * @property int $usuario_id
 * @property string $concepto
 * @property Carbon $fecha
 * @property Carbon $vencimiento
 * @property string $total_documento
 * @property string $pagado_origen
 * @property string $saldo_pendiente
 * @property string|null $nota
 * @property string|null $respaldo_path
 * @property string|null $respaldo_mime
 * @property string|null $clave_idempotencia
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $anulado_at
 * @property int|null $anulado_por
 * @property string|null $motivo_anulacion
 * @property-read Compra|null $compra
 * @property-read Egreso|null $egreso
 * @property-read Sucursal $sucursal
 * @property-read Proveedor $proveedor
 * @property-read User $usuario
 */
class CuentaPagar extends Model
{
    use Anulable, BelongsToEmpresa;

    public const ORIGENES = ['compra' => 'Compra', 'egreso' => 'Egreso', 'manual' => 'Obligación manual'];

    private const INMUTABLES = ['numero', 'origen', 'compra_id', 'egreso_id', 'sucursal_id', 'proveedor_id', 'usuario_id', 'concepto',
        'fecha', 'vencimiento', 'total_documento', 'pagado_origen'];

    protected $table = 'cuentas_pagar';

    protected function casts(): array
    {
        return [
            'fecha' => 'date',
            'vencimiento' => 'date',
            'total_documento' => 'decimal:2',
            'pagado_origen' => 'decimal:2',
            'saldo_pendiente' => 'decimal:2',
            'anulado_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (CuentaPagar $cuenta): void {
            if ($cuenta->isDirty(self::INMUTABLES) || $cuenta->getOriginal('anulado_at') !== null) {
                throw new LogicException('Una cuenta por pagar registrada o anulada no puede reescribirse.');
            }
        });
        static::deleting(fn () => throw new LogicException('Una cuenta por pagar no se elimina.'));
    }

    public function codigo(): string
    {
        return 'CXP-'.str_pad((string) $this->numero, 5, '0', STR_PAD_LEFT);
    }

    /**
     * Importe de la deuda al nacer: total del documento menos lo pagado antes.
     */
    public function importe(): string
    {
        return Decimal::restar($this->total_documento, $this->pagado_origen, Decimal::ESCALA_DINERO);
    }

    /**
     * Pagado en total, incluido lo pagado en el documento de origen.
     */
    public function totalPagado(): string
    {
        return Decimal::restar($this->total_documento, $this->saldo_pendiente, Decimal::ESCALA_DINERO);
    }

    /**
     * @return 'anulada'|'pagada'|'vencida'|'parcial'|'pendiente'
     */
    public function estado(?CarbonInterface $hoy = null): string
    {
        return $this->estaAnulado() ? 'anulada' : self::estadoDe($this->saldo_pendiente, $this->totalPagado(), $this->vencimiento, $hoy);
    }

    /**
     * Estado calculado (§ 19): pagada si no hay saldo; vencida si hay saldo y pasó el vencimiento;
     * parcial si se pagó algo; pendiente en otro caso. Una cuenta pagada nunca está vencida.
     *
     * @return 'pagada'|'vencida'|'parcial'|'pendiente'
     */
    public static function estadoDe(string $saldo, string $pagado, CarbonInterface|string|null $vencimiento, ?CarbonInterface $hoy = null): string
    {
        $hoy ??= Carbon::now(config('mozistock.business_timezone'))->startOfDay();

        return match (true) {
            Decimal::comparar($saldo, '0', 2) <= 0 => 'pagada',
            $vencimiento !== null && Carbon::parse($vencimiento)->format('Y-m-d') < $hoy->format('Y-m-d') => 'vencida',
            Decimal::comparar($pagado, '0', 2) > 0 => 'parcial',
            default => 'pendiente',
        };
    }

    /**
     * @return HasMany<PagoCuentaPagar, $this>
     */
    public function pagos(): HasMany
    {
        return $this->hasMany(PagoCuentaPagar::class)->orderBy('fecha_pago');
    }

    /**
     * @return BelongsTo<Compra, $this>
     */
    public function compra(): BelongsTo
    {
        return $this->belongsTo(Compra::class);
    }

    /**
     * @return BelongsTo<Egreso, $this>
     */
    public function egreso(): BelongsTo
    {
        return $this->belongsTo(Egreso::class);
    }

    /**
     * @return BelongsTo<Sucursal, $this>
     */
    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class);
    }

    /**
     * @return BelongsTo<Proveedor, $this>
     */
    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }
}
