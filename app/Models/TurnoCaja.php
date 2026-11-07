<?php

namespace App\Models;

use App\Domain\Reportes\Periodo;
use App\Models\Concerns\BelongsToEmpresa;
use App\Support\Decimal;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $empresa_id
 * @property int $sucursal_id
 * @property int $caja_id
 * @property int $usuario_apertura_id
 * @property string $monto_apertura
 * @property Carbon $fecha_apertura
 * @property string $estado
 * @property int|null $usuario_cierre_id
 * @property Carbon|null $fecha_cierre
 * @property string|null $monto_cierre
 * @property string|null $efectivo_esperado Guardado al cerrar: fondo + cobros − salidas en efectivo.
 * @property string|null $diferencia Guardada al cerrar: contado − esperado.
 * @property string|null $observaciones
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Caja $caja
 * @property-read Sucursal $sucursal
 * @property-read User $usuarioApertura
 * @property-read User|null $usuarioCierre
 */
#[Fillable(['sucursal_id', 'caja_id', 'usuario_apertura_id', 'monto_apertura', 'fecha_apertura', 'estado', 'usuario_cierre_id', 'fecha_cierre', 'monto_cierre', 'efectivo_esperado', 'diferencia', 'observaciones'])]
class TurnoCaja extends Model
{
    use BelongsToEmpresa;

    protected $table = 'turnos_caja';

    protected function casts(): array
    {
        return [
            'monto_apertura' => 'decimal:2',
            'monto_cierre' => 'decimal:2',
            'efectivo_esperado' => 'decimal:2',
            'diferencia' => 'decimal:2',
            'fecha_apertura' => 'datetime',
            'fecha_cierre' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Caja, $this>
     */
    public function caja(): BelongsTo
    {
        return $this->belongsTo(Caja::class)->withoutGlobalScopes();
    }

    /**
     * @return BelongsTo<Sucursal, $this>
     */
    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class)->withoutGlobalScopes();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function usuarioApertura(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_apertura_id')->withoutGlobalScopes();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function usuarioCierre(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_cierre_id')->withoutGlobalScopes();
    }

    /**
     * @return HasMany<TurnoUsuario, $this>
     */
    public function participantes(): HasMany
    {
        return $this->hasMany(TurnoUsuario::class)->withoutGlobalScopes();
    }

    /**
     * @return HasMany<Venta, $this>
     */
    public function ventas(): HasMany
    {
        return $this->hasMany(Venta::class)->withoutGlobalScopes();
    }

    /**
     * Resultado guardado al cerrar: abierto, cuadrada, sobrante, faltante o sin_conteo.
     */
    public function resultado(): string
    {
        return match (true) {
            $this->estaAbierto() => 'abierto',
            $this->diferencia === null => 'sin_conteo',
            default => match (Decimal::comparar((string) $this->diferencia, '0', Decimal::ESCALA_DINERO)) {
                0 => 'cuadrada',
                1 => 'sobrante',
                default => 'faltante',
            },
        };
    }

    public function estaAbierto(): bool
    {
        return $this->estado === 'abierto';
    }

    /**
     * Compara días de negocio (La Paz), no días UTC: un turno abierto a las 21:00 sigue siendo
     * del mismo día hasta la medianoche local.
     */
    public function esDelDiaAnterior(): bool
    {
        $zona = Periodo::zona();

        return $this->fecha_apertura->copy()->timezone($zona)->startOfDay()->lessThan(Carbon::now($zona)->startOfDay());
    }

    /**
     * Compartida: quien abrió, los vendedores asignados a la caja y el administrador trabajan en
     * el mismo turno. Individual: solo quien abrió.
     */
    public function usuarioPuedeOperar(User $usuario): bool
    {
        if (! $this->estaAbierto() || ! $this->esDelEquipo($usuario)) {
            return false;
        }

        if ($this->caja->modalidad === 'individual') {
            return $this->usuario_apertura_id === $usuario->id;
        }

        return $this->usuario_apertura_id === $usuario->id || $usuario->caja_id === $this->caja_id || $usuario->esAdministrador();
    }

    public function usuarioPuedeCerrar(User $usuario): bool
    {
        if (! $this->estaAbierto() || ! $this->esDelEquipo($usuario)) {
            return false;
        }

        if ($usuario->esAdministrador()) {
            return true;
        }

        if ($this->caja->modalidad === 'individual') {
            return $this->usuario_apertura_id === $usuario->id;
        }

        return $usuario->caja_id === $this->caja_id || $this->usuario_apertura_id === $usuario->id;
    }

    /**
     * Usuario activo de la empresa del turno; el vendedor, además, de la sucursal de la caja.
     * Una caja nunca se usa desde otra sucursal, aunque el vendedor la haya abierto antes de
     * un cambio de sucursal.
     */
    private function esDelEquipo(User $usuario): bool
    {
        if (! $usuario->activo || (int) $usuario->empresa_id !== (int) $this->empresa_id) {
            return false;
        }

        return $usuario->esAdministrador()
            || ($usuario->esVendedor() && (int) $usuario->sucursal_id === (int) $this->sucursal_id);
    }

    /**
     * @return array{
     *   monto_apertura: string,
     *   ingresos_efectivo: string,
     *   egresos_efectivo: string,
     *   efectivo_esperado: string,
     *   ingresos_electronico: string,
     *   total_ventas_cobradas: string,
     *   monto_cierre: ?string,
     *   diferencia: ?string,
     *   estado_diferencia: 'cuadrada'|'sobrante'|'faltante'|'sin_cierre',
     *   cantidad_ventas: int,
     * }
     */
    public function calcularArqueo(string|float|int|null $montoCierre = null): array
    {
        $movimientos = MovimientoCaja::withoutGlobalScopes()
            ->where('empresa_id', $this->empresa_id)
            ->where('turno_caja_id', $this->id)
            ->get(['sentido', 'metodo', 'monto']);

        $ingresosEfectivo = '0.00';
        $egresosEfectivo = '0.00';
        $ingresosElectronico = '0.00';

        foreach ($movimientos as $mov) {
            $monto = (string) $mov->monto;
            if ($mov->metodo === 'efectivo') {
                if ($mov->sentido === 'ingreso') {
                    $ingresosEfectivo = Decimal::sumar($ingresosEfectivo, $monto, Decimal::ESCALA_DINERO);
                } else {
                    $egresosEfectivo = Decimal::sumar($egresosEfectivo, $monto, Decimal::ESCALA_DINERO);
                }
            } else {
                if ($mov->sentido === 'ingreso') {
                    $ingresosElectronico = Decimal::sumar($ingresosElectronico, $monto, Decimal::ESCALA_DINERO);
                }
            }
        }

        $apertura = (string) $this->monto_apertura;
        $efectivoEsperado = Decimal::restar(
            Decimal::sumar($apertura, $ingresosEfectivo, Decimal::ESCALA_DINERO),
            $egresosEfectivo,
            Decimal::ESCALA_DINERO
        );

        $totalVentasCobradas = Decimal::sumar($ingresosEfectivo, $ingresosElectronico, Decimal::ESCALA_DINERO);

        $cierre = $montoCierre !== null && trim((string) $montoCierre) !== ''
            ? Decimal::normalizar((string) $montoCierre, Decimal::ESCALA_DINERO)
            : ($this->monto_cierre !== null ? (string) $this->monto_cierre : null);

        $diferencia = null;
        $estado = 'sin_cierre';

        if ($cierre !== null) {
            $diferencia = Decimal::restar($cierre, $efectivoEsperado, Decimal::ESCALA_DINERO);
            $comp = Decimal::comparar($diferencia, '0.00', Decimal::ESCALA_DINERO);
            if ($comp === 0) {
                $estado = 'cuadrada';
            } elseif ($comp > 0) {
                $estado = 'sobrante';
            } else {
                $estado = 'faltante';
            }
        }

        $cantidadVentas = Venta::withoutGlobalScopes()
            ->where('empresa_id', $this->empresa_id)
            ->where('turno_caja_id', $this->id)
            ->count();

        return [
            'monto_apertura' => $apertura,
            'ingresos_efectivo' => $ingresosEfectivo,
            'egresos_efectivo' => $egresosEfectivo,
            'efectivo_esperado' => $efectivoEsperado,
            'ingresos_electronico' => $ingresosElectronico,
            'total_ventas_cobradas' => $totalVentasCobradas,
            'monto_cierre' => $cierre,
            'diferencia' => $diferencia,
            'estado_diferencia' => $estado,
            'cantidad_ventas' => $cantidadVentas,
        ];
    }
}
