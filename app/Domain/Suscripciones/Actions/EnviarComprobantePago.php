<?php

namespace App\Domain\Suscripciones\Actions;

use App\Domain\Auditoria\Auditor;
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
 * La empresa envía el comprobante de un pago QR por 1 a 12 meses (decisión § 8.4). El
 * importe lo calcula el servidor (meses × precio vigente) y queda en revisión: no renueva
 * nada hasta que MoziStudios lo apruebe. Un solo comprobante en revisión por empresa.
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
     * @param  array{meses: int|string|null, nota: string|null, clave: string}  $datos
     */
    public function ejecutar(User $actor, array $datos, UploadedFile $archivo): PagoSaas
    {
        Gate::forUser($actor)->authorize('create', PagoSaas::class);
        $empresaId = (int) $actor->empresa_id;

        if (! Str::isUuid($datos['clave'])) {
            throw ValidationException::withMessages(['archivo' => __('El envío no tiene una clave válida. Recarga la página.')]);
        }

        if (($previo = $this->existente($empresaId, $datos['clave'])) !== null) {
            return $previo;
        }

        $meses = filter_var($datos['meses'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => CalendarioSuscripcion::MAX_MESES]]);

        if ($meses === false) {
            throw ValidationException::withMessages(['meses' => __('Elige de 1 a 12 meses.')]);
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
            return DB::transaction(function () use ($actor, $empresaId, $meses, $nota, $mime, $archivo, $ruta, $datos): PagoSaas {
                $suscripcion = Suscripcion::query()->lockForUpdate()->firstOrFail();

                if ($this->hayPendiente()) {
                    throw $this->yaEnRevision();
                }

                $precioRenovacion = $suscripcion->precioRenovacion();
                $pago = new PagoSaas;
                $pago->empresa_id = $empresaId;
                $pago->suscripcion_id = $suscripcion->id;
                $pago->meses = $meses;
                $pago->precio_mensual = $precioRenovacion;
                $pago->importe = Decimal::multiplicar($precioRenovacion, (string) $meses, Decimal::ESCALA_DINERO);
                $pago->nota = $nota === '' ? null : $nota;
                $pago->archivo_path = $ruta;
                $pago->archivo_mime = $mime;
                $pago->archivo_bytes = (int) $archivo->getSize();
                $pago->estado = EstadoPagoSaas::Pendiente;
                $pago->enviado_por = $actor->id;
                $pago->clave_idempotencia = $datos['clave'];
                $pago->save();

                $this->auditor->registrar('suscripcion.comprobante_enviado', $pago, $actor, $empresaId, despues: [
                    'comprobante' => $pago->codigo(), 'meses' => $meses, 'importe' => $pago->importe,
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
