<?php

namespace App\Domain\Facturacion;

use App\Enums\ModalidadFacturacion;
use App\Models\Empresa;
use App\Models\SiatConfiguracion;
use App\Models\SiatCufd;
use App\Models\SiatCuis;
use App\Models\SiatPuntoVenta;
use App\Models\SiatSucursal;
use DateTimeInterface;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use MoziStudios\Siat\ClienteSiat;
use MoziStudios\Siat\Configuracion\Contribuyente;
use MoziStudios\Siat\Configuracion\PuntoEmision;
use MoziStudios\Siat\Excepciones\SiatException;
use MoziStudios\Siat\Respuestas\Mensaje;

/**
 * CUIS y CUFD de cada punto de venta, guardados con su historial. Cada código vale solo para el
 * ambiente y el código de sistema actuales. Las llamadas al SIN van fuera de transacciones y bajo
 * un bloqueo por punto: un doble clic o un job simultáneo no piden dos códigos a la vez.
 */
final class CodigosSiat
{
    /** El job horario renueva el CUFD cuando le quedan menos de 2 horas. */
    public const MARGEN_RENOVACION_MINUTOS = 120;

    public function __construct(
        private readonly ClienteSiat $cliente,
        private readonly ContribuyenteEmpresa $contribuyentes,
    ) {}

    public function cuisVigente(SiatPuntoVenta $punto): ?SiatCuis
    {
        return $this->cuisDelSistema($punto)->where('vence_en', '>', now())->latest('vence_en')->first();
    }

    /**
     * CUIS vigente del punto; lo pide al SIN solo si no hay uno guardado o si se fuerza su renovación.
     *
     * @throws ErrorFacturacion|SiatException
     */
    public function cuis(SiatPuntoVenta $punto, bool $forzar = false): SiatCuis
    {
        if (! $forzar && ($vigente = $this->cuisVigente($punto)) !== null) {
            return $vigente;
        }

        return $this->conBloqueo("siat:cuis:{$punto->id}", function () use ($punto, $forzar): SiatCuis {
            if (! $forzar && ($vigente = $this->cuisVigente($punto)) !== null) {
                return $vigente;
            }

            [$contribuyente, $emision] = $this->preparar($punto);
            $respuesta = $this->clientePara($punto->empresa_id)->codigos($contribuyente)->cuis($emision);

            // Con un CUIS vigente en el SIN la respuesta trae el mismo código y el mensaje 980: se guarda igual.
            if ($respuesta->codigo === null || $respuesta->fechaVigencia === null) {
                throw ErrorFacturacion::delSin(__('El SIN no entregó el CUIS.'), $respuesta->mensajes);
            }

            $cuis = $this->cuisDelSistema($punto)->where('codigo', $respuesta->codigo)->first() ?? new SiatCuis;
            $cuis->empresa_id = $punto->empresa_id;
            $cuis->siat_punto_venta_id = $punto->id;
            $cuis->ambiente = $this->cliente->configuracion()->ambiente->value;
            $cuis->codigo_sistema = $this->cliente->configuracion()->codigoSistema;
            $cuis->codigo = $respuesta->codigo;
            $cuis->vence_en = self::enZonaApp($respuesta->fechaVigencia);
            $cuis->save();

            return $cuis;
        });
    }

    public function cufdVigente(SiatPuntoVenta $punto, int $margenMinutos = 0): ?SiatCufd
    {
        return DeEmpresa::consulta(SiatCufd::class, $punto->empresa_id)
            ->where('siat_punto_venta_id', $punto->id)
            ->where('vence_en', '>', now()->addMinutes($margenMinutos))
            ->whereIn('siat_cuis_id', $this->cuisDelSistema($punto)->select('id'))
            ->latest('id')
            ->first();
    }

    public function invalidarCufdVigente(SiatPuntoVenta $punto): void
    {
        DeEmpresa::consulta(SiatCufd::class, $punto->empresa_id)
            ->where('siat_punto_venta_id', $punto->id)
            ->where('vence_en', '>', now())
            ->update(['vence_en' => now()->subMinute()]);
    }

    /**
     * Pide un CUFD nuevo. Con $soloSiHaceFalta conserva el vigente que aún tenga margen (job horario).
     * Un pedido simultáneo espera al primero y recibe el CUFD recién obtenido en vez de pedir otro.
     * Con $forzar = true ignora cualquier CUFD previo y solicita uno fresco al SIN.
     *
     * @throws ErrorFacturacion|SiatException
     */
    public function renovarCufd(SiatPuntoVenta $punto, bool $soloSiHaceFalta = false, bool $forzar = false): SiatCufd
    {
        return $this->conBloqueo("siat:cufd:{$punto->id}", function () use ($punto, $soloSiHaceFalta, $forzar): SiatCufd {
            if (! $forzar) {
                $actual = $this->cufdVigente($punto, $soloSiHaceFalta ? self::MARGEN_RENOVACION_MINUTOS : 0);

                if ($actual !== null && ($soloSiHaceFalta || $actual->created_at?->greaterThan(now()->subMinute()))) {
                    return $actual;
                }
            }

            $cuis = $this->cuis($punto);
            [$contribuyente, $emision] = $this->preparar($punto);
            $cliente = $this->clientePara($punto->empresa_id);
            $cufd = $cliente->codigos($contribuyente)->cufd($emision, $cuis->codigo);

            if ($cufd->cufd === null || $cufd->cufd->codigoInterno === null || $cufd->cufd->fechaVigencia === null) {
                // Si el SIN indica que el CUIS guardado es inválido (código 913), se fuerza su renovación y se reintenta
                $esCuisInvalido = collect($cufd->mensajes)->contains(fn (Mensaje $m): bool => $m->codigo === 913);
                if ($esCuisInvalido) {
                    $cuis = $this->cuis($punto, forzar: true);
                    $cufd = $cliente->codigos($contribuyente)->cufd($emision, $cuis->codigo);
                }

                if ($cufd->cufd === null || $cufd->cufd->codigoInterno === null || $cufd->cufd->fechaVigencia === null) {
                    throw ErrorFacturacion::delSin(__('El SIN no entregó el CUFD.'), $cufd->mensajes);
                }
            }

            $registro = new SiatCufd;
            $registro->empresa_id = $punto->empresa_id;
            $registro->siat_punto_venta_id = $punto->id;
            $registro->siat_cuis_id = $cuis->id;
            $registro->codigo = $cufd->cufd->codigo;
            $registro->codigo_control = $cufd->cufd->codigoControl;
            $registro->codigo_interno = $cufd->cufd->codigoInterno;
            $registro->direccion = $cufd->cufd->direccion;
            $registro->vence_en = self::enZonaApp($cufd->cufd->fechaVigencia);
            $registro->save();

            return $registro;
        });
    }

    /**
     * Sucursal SIN y contribuyente del punto, con las comprobaciones de habilitación y token.
     *
     * @return array{0: Contribuyente, 1: PuntoEmision}
     *
     * @throws ErrorFacturacion
     */
    public function preparar(SiatPuntoVenta $punto): array
    {
        if (! $punto->activo) {
            throw new ErrorFacturacion(__('El punto de venta está cerrado.'));
        }

        $sucursal = DeEmpresa::consulta(SiatSucursal::class, $punto->empresa_id)->findOrFail($punto->siat_sucursal_id);
        $empresa = Empresa::query()->findOrFail($punto->empresa_id);

        return [$this->contribuyentes->para($empresa), new PuntoEmision($sucursal->codigo, $punto->codigo)];
    }

    /**
     * Eloquent guarda la hora tal como viene, sin convertirla: el SIN responde en hora de Bolivia
     * (-04:00) y la base de datos está en la zona de la aplicación.
     */
    public static function enZonaApp(DateTimeInterface $fecha): Carbon
    {
        return Carbon::instance($fecha)->setTimezone((string) config('app.timezone'));
    }

    private function clientePara(int $empresaId): ClienteSiat
    {
        $configuracion = DeEmpresa::consulta(SiatConfiguracion::class, $empresaId)->first();
        $modalidadSiat = ($configuracion?->modalidad ?? ModalidadFacturacion::Computarizada)->siat();

        return $this->cliente->conModalidad($modalidadSiat);
    }

    /**
     * @return Builder<SiatCuis>
     */
    private function cuisDelSistema(SiatPuntoVenta $punto): Builder
    {
        return DeEmpresa::consulta(SiatCuis::class, $punto->empresa_id)
            ->where('siat_punto_venta_id', $punto->id)
            ->where('ambiente', $this->cliente->configuracion()->ambiente->value)
            ->where('codigo_sistema', $this->cliente->configuracion()->codigoSistema);
    }

    /**
     * @template T
     *
     * @param  callable(): T  $proceso
     * @return T
     */
    private function conBloqueo(string $clave, callable $proceso): mixed
    {
        try {
            return Cache::lock($clave, 60)->block(20, $proceso);
        } catch (LockTimeoutException) {
            throw new ErrorFacturacion(__('Ya hay una solicitud en curso para este punto de venta. Intenta de nuevo en unos segundos.'));
        }
    }
}
