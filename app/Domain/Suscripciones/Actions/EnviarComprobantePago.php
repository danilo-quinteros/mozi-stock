<?php

namespace App\Domain\Suscripciones\Actions;

use App\Domain\Auditoria\Auditor;
use App\Domain\Suscripciones\CalculadoraExtras;
use App\Domain\Suscripciones\CalendarioSuscripcion;
use App\Enums\EstadoPagoSaas;
use App\Models\PagoSaas;
use App\Models\Scopes\EmpresaScope;
use App\Models\Suscripcion;
use App\Models\User;
use App\Support\Decimal;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * La empresa envía el comprobante de un pago QR por 1 a 12 meses (decisión § 8.4) o por los
 * extras que suma a mitad del período. El importe lo calcula el servidor (meses × plan + extras,
 * o la diferencia de los extras) y queda en revisión: no renueva ni activa nada hasta que
 * MoziStudios lo apruebe. Un solo comprobante en revisión por empresa.
 * El archivo va al disco privado, en la carpeta de la empresa y con nombre aleatorio.
 */
final class EnviarComprobantePago
{
    public const DISCO = 'local';

    public const MAX_KB = 5120;

    /** Tipos detectados por el contenido del archivo, no por su nombre. */
    public const TIPOS = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'];

    public function __construct(private readonly Auditor $auditor) {}

    /**
     * Renovación: meses × mensualidad (plan + extras). El vencimiento avanza al aprobarse.
     *
     * @param  array{meses: int|string|null, nota: string|null, clave: string}  $datos
     */
    public function ejecutar(User $actor, array $datos, UploadedFile $archivo): PagoSaas
    {
        $meses = filter_var($datos['meses'], FILTER_VALIDATE_INT);

        return $this->registrar($actor, $datos, $archivo, function () use ($meses): void {
            if ($meses === false || ! in_array($meses, CalendarioSuscripcion::MESES_PAGABLES, true)) {
                throw ValidationException::withMessages(['meses' => __('Elige 1, 3, 6 o 12 meses.')]);
            }
        }, function (Suscripcion $suscripcion, PagoSaas $pago) use ($meses): void {
            $desglose = $suscripcion->desgloseRenovacion();
            $pago->tipo = PagoSaas::TIPO_RENOVACION;
            $pago->meses = (int) $meses;
            $this->desglose($pago, $desglose);
            $pago->importe = Decimal::multiplicar($desglose['total'], (string) $meses, Decimal::ESCALA_DINERO);
        });
    }

    /**
     * Extras agregados a mitad del período: se paga hoy la diferencia hasta el vencimiento
     * (CalculadoraExtras) y se activan cuando MoziStudios aprueba. El vencimiento no cambia.
     *
     * @param  array{sucursales_extra: int, paquetes_usuarios: int, nota: string|null, clave: string}  $datos
     */
    public function extras(User $actor, array $datos, UploadedFile $archivo): PagoSaas
    {
        $sucursales = (int) $datos['sucursales_extra'];
        $paquetes = (int) $datos['paquetes_usuarios'];

        return $this->registrar($actor, $datos, $archivo, null, function (Suscripcion $suscripcion, PagoSaas $pago) use ($sucursales, $paquetes): void {
            CambiarExtrasSuscripcion::validar($suscripcion, $sucursales, $paquetes);
            $diferencia = CalculadoraExtras::diferencia($suscripcion, $sucursales, $paquetes);

            if (Decimal::comparar($diferencia['importe'], '0', 2) <= 0) {
                throw ValidationException::withMessages(['archivo' => __('Este cambio no tiene costo hoy: guárdalo sin enviar comprobante.')]);
            }

            $pago->tipo = PagoSaas::TIPO_EXTRAS;
            $pago->meses = null;
            $this->desglose($pago, CalculadoraExtras::desglose((string) $suscripcion->precio_mensual, $suscripcion->plan, $sucursales, $paquetes));
            $pago->importe = $diferencia['importe'];
        });
    }

    /**
     * @param  array{nota: string|null, clave: string}  $datos
     * @param  (callable(): void)|null  $validarAntes  validación sin bloqueo, antes de guardar el archivo
     * @param  callable(Suscripcion, PagoSaas): void  $completar  tipo, desglose e importe, con la suscripción bloqueada
     */
    private function registrar(User $actor, array $datos, UploadedFile $archivo, ?callable $validarAntes, callable $completar): PagoSaas
    {
        Gate::forUser($actor)->authorize('create', PagoSaas::class);
        $empresaId = (int) $actor->empresa_id;

        if (! Str::isUuid($datos['clave'])) {
            throw ValidationException::withMessages(['archivo' => __('El envío no tiene una clave válida. Recarga la página.')]);
        }

        if (($previo = $this->existente($empresaId, $datos['clave'])) !== null) {
            return $previo;
        }

        if ($validarAntes !== null) {
            $validarAntes();
        }

        $nota = trim((string) $datos['nota']);

        if (mb_strlen($nota) > 300) {
            throw ValidationException::withMessages(['nota' => __('La nota admite hasta 300 caracteres.')]);
        }

        $mime = (string) $archivo->getMimeType();

        if (! $archivo->isValid() || ! array_key_exists($mime, self::TIPOS) || $archivo->getSize() <= 0 || $archivo->getSize() > self::MAX_KB * 1024) {
            throw ValidationException::withMessages(['archivo' => __('Adjunta una imagen JPG, PNG o WebP, o un PDF, de hasta 5 MB.')]);
        }

        if (Suscripcion::query()->doesntExist()) {
            throw ValidationException::withMessages(['meses' => __('MoziStudios todavía no configuró el plan de tu empresa.')]);
        }

        if ($this->hayPendiente()) {
            throw $this->yaEnRevision();
        }

        $ruta = $archivo->storeAs("empresas/{$empresaId}/suscripcion", Str::random(40).'.'.self::TIPOS[$mime], self::DISCO);

        if ($ruta === false) {
            throw ValidationException::withMessages(['archivo' => __('No se pudo guardar el comprobante.')]);
        }

        try {
            return DB::transaction(function () use ($actor, $empresaId, $nota, $mime, $archivo, $ruta, $datos, $completar): PagoSaas {
                $suscripcion = Suscripcion::query()->with(['plan', 'planSiguiente'])->lockForUpdate()->firstOrFail();

                if ($this->hayPendiente()) {
                    throw $this->yaEnRevision();
                }

                $pago = new PagoSaas;
                $pago->empresa_id = $empresaId;
                $pago->suscripcion_id = $suscripcion->id;
                $completar($suscripcion, $pago);
                $pago->nota = $nota === '' ? null : $nota;
                $pago->archivo_path = $ruta;
                $pago->archivo_mime = $mime;
                $pago->archivo_bytes = (int) $archivo->getSize();
                $pago->estado = EstadoPagoSaas::Pendiente;
                $pago->enviado_por = $actor->id;
                $pago->clave_idempotencia = $datos['clave'];
                $pago->save();

                $this->auditor->registrar('suscripcion.comprobante_enviado', $pago, $actor, $empresaId, despues: [
                    'comprobante' => $pago->codigo(), 'tipo' => $pago->tipo, 'meses' => $pago->meses, 'importe' => $pago->importe,
                    'sucursales_extra' => $pago->sucursales_extra, 'paquetes_usuarios' => $pago->paquetes_usuarios,
                ]);

                return $pago;
            }, attempts: 3);
        } catch (Throwable $e) {
            Storage::disk(self::DISCO)->delete($ruta);

            // Una repetición de la misma clave devuelve el comprobante ya registrado.
            if ($e instanceof UniqueConstraintViolationException) {
                return $this->existente($empresaId, $datos['clave']) ?? throw $this->yaEnRevision();
            }

            throw $e;
        }
    }

    /**
     * @param  array{plan: string, sucursales: int, precio_sucursal: string, paquetes: int, precio_paquete: string, total: string}  $desglose
     */
    private function desglose(PagoSaas $pago, array $desglose): void
    {
        $pago->precio_mensual = $desglose['total'];
        $pago->precio_plan = $desglose['plan'];
        $pago->sucursales_extra = $desglose['sucursales'];
        $pago->precio_sucursal_extra = $desglose['precio_sucursal'];
        $pago->paquetes_usuarios = $desglose['paquetes'];
        $pago->precio_paquete_usuarios = $desglose['precio_paquete'];
    }

    /**
     * Lee la base en cada llamada: tras un choque de clave el comprobante ya existe.
     *
     * @phpstan-impure
     */
    private function existente(int $empresaId, string $clave): ?PagoSaas
    {
        return PagoSaas::withoutGlobalScope(EmpresaScope::class)
            ->where('empresa_id', $empresaId)->where('clave_idempotencia', $clave)->first();
    }

    /**
     * @phpstan-impure
     */
    private function hayPendiente(): bool
    {
        return PagoSaas::query()->where('estado', EstadoPagoSaas::Pendiente)->exists();
    }

    private function yaEnRevision(): ValidationException
    {
        return ValidationException::withMessages(['archivo' => __('Ya tienes un comprobante en revisión. Espera la respuesta de MoziStudios antes de enviar otro.')]);
    }
}
