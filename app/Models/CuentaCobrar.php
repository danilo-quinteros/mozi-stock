<?php

namespace App\Models;

use App\Models\Concerns\Anulable;
use App\Models\Concerns\BelongsToEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Cuenta por cobrar manual: saldo de un cliente que no proviene de una venta de MoziStock.
 * No crea ventas ni mueve stock o dinero al registrarse; solo saldo_pendiente cambia, por cobros.
 * El administrador puede anularla si no tiene cobros vigentes; anulada ya no cambia.
 *
 * @property int $id
 * @property int $empresa_id
 * @property int $numero
 * @property int $sucursal_id
 * @property int $cliente_id
 * @property int $usuario_id
 * @property string $concepto
 * @property Carbon $fecha
 * @property Carbon|null $vencimiento
 * @property string $importe
 * @property string $saldo_pendiente
 * @property string|null $nota
 * @property string $clave_idempotencia
 * @property Carbon|null $anulado_at
 * @property int|null $anulado_por
 * @property string|null $motivo_anulacion
 * @property-read Cliente $cliente
 * @property-read Sucursal $sucursal
 * @property-read User $usuario
 */
class CuentaCobrar extends Model
{
    use Anulable, BelongsToEmpresa;

    private const INMUTABLES = ['numero', 'sucursal_id', 'cliente_id', 'usuario_id', 'concepto', 'fecha', 'importe'];

    protected $table = 'cuentas_cobrar';

    protected function casts(): array
    {
        return [
            'fecha' => 'date',
            'vencimiento' => 'date',
            'importe' => 'decimal:2',
            'saldo_pendiente' => 'decimal:2',
            'anulado_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (CuentaCobrar $cuenta): void {
            if ($cuenta->isDirty(self::INMUTABLES) || $cuenta->getOriginal('anulado_at') !== null) {
                throw new LogicException('Una cuenta por cobrar registrada o anulada no puede reescribirse.');
            }
        });
        static::deleting(fn () => throw new LogicException('Una cuenta por cobrar no se elimina.'));
    }

    public function codigo(): string
    {
        return 'CXC-'.str_pad((string) $this->numero, 5, '0', STR_PAD_LEFT);
    }

    /**
     * @return HasMany<CobroAplicacion, $this>
     */
    public function aplicaciones(): HasMany
    {
        return $this->hasMany(CobroAplicacion::class)->orderBy('id');
    }

    /**
     * @return BelongsTo<Cliente, $this>
     */
    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    /**
     * @return BelongsTo<Sucursal, $this>
     */
    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }
}
