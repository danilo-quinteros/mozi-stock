<?php

namespace App\Models;

use App\Enums\EstadoFactura;
use App\Models\Concerns\BelongsToEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Factura compra-venta SIAT de una venta confirmada. Facturar no es cobrar: este registro nunca
 * modifica la venta, sus pagos, la caja ni el stock. Una venta tiene como máximo una factura vigente.
 *
 * @property int $id
 * @property int $empresa_id
 * @property int $venta_id
 * @property int|null $venta_vigente
 * @property int|null $siat_punto_venta_id
 * @property int|null $siat_cufd_id
 * @property int|null $numero
 * @property EstadoFactura $estado
 * @property string|null $motivo
 * @property int $tipo_documento
 * @property string $numero_documento
 * @property string|null $complemento
 * @property string $razon_social
 * @property string $codigo_cliente
 * @property int $codigo_excepcion
 * @property int|null $metodo_pago
 * @property string|null $numero_tarjeta
 * @property string $total
 * @property string|null $sub_total_neto
 * @property string|null $descuento_adicional
 * @property string|null $total_precio_neto
 * @property string|null $iva
 * @property list<array{venta_item_id: int, codigo_producto: string, descripcion: string, actividad: string, producto_sin: int, unidad_medida: int, cantidad: string, precio_neto_unitario: string, monto_descuento: string|null, sub_total: string}>|null $lineas
 * @property Carbon|null $fecha_emision
 * @property string|null $cuf
 * @property string|null $leyenda
 * @property string|null $xml
 * @property string|null $codigo_recepcion
 * @property int|null $codigo_estado
 * @property list<array{codigo: int|null, descripcion: string|null}>|null $mensajes
 * @property int $intentos
 * @property Carbon|null $enviada_en
 * @property Carbon|null $validada_en
 * @property int|null $motivo_anulacion
 * @property Carbon|null $anulada_en
 * @property int|null $anulada_por
 * @property Carbon|null $revertida_en
 * @property int|null $revertida_por
 * @property int $emitida_por
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Venta $venta
 * @property-read SiatPuntoVenta|null $puntoVenta
 * @property-read SiatCufd|null $cufd
 * @property-read User $emisor
 */
class SiatFactura extends Model
{
    use BelongsToEmpresa;

    protected $table = 'siat_facturas';

    /** fecha_emision conserva los milisegundos con que se generó el CUF (las columnas timestamp los redondean). */
    protected $dateFormat = 'Y-m-d H:i:s.v';

    /** El XML lleva datos del comprador: no viaja en toArray/JSON (Livewire, logs). */
    protected $hidden = ['xml'];

    protected function casts(): array
    {
        return [
            'estado' => EstadoFactura::class,
            'tipo_documento' => 'integer',
            'codigo_excepcion' => 'integer',
            'metodo_pago' => 'integer',
            'total' => 'decimal:2',
            'sub_total_neto' => 'decimal:2',
            'descuento_adicional' => 'decimal:2',
            'total_precio_neto' => 'decimal:2',
            'iva' => 'decimal:2',
            'lineas' => 'array',
            'fecha_emision' => 'datetime',
            'mensajes' => 'array',
            'intentos' => 'integer',
            'enviada_en' => 'datetime',
            'validada_en' => 'datetime',
            'motivo_anulacion' => 'integer',
            'anulada_en' => 'datetime',
            'revertida_en' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Venta, $this>
     */
    public function venta(): BelongsTo
    {
        return $this->belongsTo(Venta::class);
    }

    /**
     * @return BelongsTo<SiatPuntoVenta, $this>
     */
    public function puntoVenta(): BelongsTo
    {
        return $this->belongsTo(SiatPuntoVenta::class, 'siat_punto_venta_id');
    }

    /**
     * @return BelongsTo<SiatCufd, $this>
     */
    public function cufd(): BelongsTo
    {
        return $this->belongsTo(SiatCufd::class, 'siat_cufd_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function emisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'emitida_por');
    }

    public function vigente(): bool
    {
        return $this->venta_vigente !== null;
    }

    /**
     * Devuelve los motivos como lista de causas individuales.
     * Limpia menciones como « en Facturación → Datos fiscales» y separa por
     * saltos de línea u oraciones individuales.
     *
     * @return list<string>
     */
    public function listaMotivos(): array
    {
        if (blank($this->motivo)) {
            return [];
        }

        $texto = str_replace(
            [' en Facturación → Datos fiscales', ' in Invoicing → Tax details'],
            '',
            (string) $this->motivo
        );

        $lineas = preg_split('/\r\n|\r|\n/', trim($texto)) ?: [];
        $motivos = [];

        foreach ($lineas as $linea) {
            $linea = trim($linea);
            if ($linea === '') {
                continue;
            }

            // Separa oraciones terminadas en punto seguidas de espacio y letra mayúscula
            $partes = preg_split('/(?<=\.)\s+(?=[A-ZÁÉÍÓÚÑ])/u', $linea) ?: [$linea];
            foreach ($partes as $parte) {
                $parte = trim($parte);
                if ($parte !== '') {
                    $motivos[] = $parte;
                }
            }
        }

        return $motivos;
    }

    /**
     * Anulación y reversión: hasta el día 9 del mes siguiente a la emisión, en hora de Bolivia (anexo SIAT).
     */
    public function dentroDelPlazoDeAnulacion(?Carbon $ahora = null): bool
    {
        if ($this->fecha_emision === null) {
            return false;
        }

        $zona = (string) config('mozistock.business_timezone');
        $limite = $this->fecha_emision->copy()->timezone($zona)->startOfMonth()->addMonth()->setDay(9)->endOfDay();

        return ($ahora ?? Carbon::now())->copy()->timezone($zona)->lessThanOrEqualTo($limite);
    }
}
