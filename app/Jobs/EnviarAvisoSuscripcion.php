<?php

namespace App\Jobs;

use App\Enums\EstadoAviso;
use App\Enums\EstadoPagoSaas;
use App\Enums\Rol;
use App\Mail\AvisoVencimientoSuscripcion;
use App\Models\AjusteGlobal;
use App\Models\AvisoSuscripcion;
use App\Models\Empresa;
use App\Models\PagoSaas;
use App\Models\Scopes\EmpresaScope;
use App\Models\Suscripcion;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Envía por correo un aviso de vencimiento (decisión § 8.5: SMTP estándar de Laravel, sin
 * dependencias). Toma el aviso de forma atómica: un reintento o un trabajo repetido no lo
 * reenvía. Sin correo configurado queda como «no enviado» y solo se ve en el panel.
 */
class EnviarAvisoSuscripcion implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 600];

    public function __construct(public readonly int $avisoId) {}

    public static function correoConfigurado(): bool
    {
        return ! in_array(config('mail.default'), ['array', 'log'], true);
    }

    public function handle(): void
    {
        $tomado = AvisoSuscripcion::withoutGlobalScope(EmpresaScope::class)->whereKey($this->avisoId)
            ->where('estado', EstadoAviso::Pendiente)->update(['estado' => EstadoAviso::Enviando]);

        if ($tomado === 0) {
            return;
        }

        $aviso = AvisoSuscripcion::withoutGlobalScope(EmpresaScope::class)->findOrFail($this->avisoId);
        $empresa = Empresa::query()->findOrFail($aviso->empresa_id);
        $suscripcion = Suscripcion::withoutGlobalScope(EmpresaScope::class)->find($aviso->suscripcion_id);

        // El período se pagó o se corrigió, o la empresa fue desactivada, después de programarlo.
        if ($suscripcion === null || ! $suscripcion->vencimiento->equalTo($aviso->vencimiento) || ! $empresa->activa) {
            $this->finalizar($aviso, EstadoAviso::Omitido);

            return;
        }

        $destinatarios = $this->destinatarios($empresa);

        if ($destinatarios === []) {
            $this->finalizar($aviso, EstadoAviso::SinDestinatarios);

            return;
        }

        if (! self::correoConfigurado()) {
            $this->finalizar($aviso, EstadoAviso::NoEnviado, $destinatarios);

            return;
        }

        $enRevision = PagoSaas::withoutGlobalScope(EmpresaScope::class)->where('empresa_id', $empresa->id)
            ->where('estado', EstadoPagoSaas::Pendiente)->exists();

        // Un solo correo para todos: sale en el idioma elegido por algún administrador, o el predeterminado.
        $idioma = User::query()->where('empresa_id', $empresa->id)->where('rol', Rol::Administrador)
            ->where('activo', true)->whereNotNull('idioma')->value('idioma');

        try {
            Mail::to($destinatarios)->locale($idioma ?? config('app.locale'))->send(new AvisoVencimientoSuscripcion($empresa, $suscripcion, $aviso->evento, $enRevision, AjusteGlobal::actual()));
        } catch (Throwable $e) {
            // Vuelve a la cola; el detalle del error queda en el log, no en la tabla.
            $aviso->estado = EstadoAviso::Pendiente;
            $aviso->error = Str::limit(class_basename($e), 250);
            $aviso->save();

            throw $e;
        }

        $this->finalizar($aviso, EstadoAviso::Enviado, $destinatarios, Carbon::now());
    }

    public function failed(?Throwable $e): void
    {
        AvisoSuscripcion::withoutGlobalScope(EmpresaScope::class)->whereKey($this->avisoId)
            ->whereIn('estado', [EstadoAviso::Pendiente, EstadoAviso::Enviando])
            ->update(['estado' => EstadoAviso::Fallido, 'error' => $e === null ? null : Str::limit(class_basename($e), 250)]);
    }

    /**
     * Correo de contacto de la empresa y sus administradores activos, sin repetir.
     *
     * @return list<string>
     */
    private function destinatarios(Empresa $empresa): array
    {
        $correos = User::query()->where('empresa_id', $empresa->id)->where('rol', Rol::Administrador)
            ->where('activo', true)->pluck('email')->prepend((string) $empresa->email);

        return array_values($correos->map(fn (string $correo) => Str::lower(trim($correo)))
            ->filter(fn (string $correo) => filter_var($correo, FILTER_VALIDATE_EMAIL) !== false)
            ->unique()->all());
    }

    /**
     * @param  list<string>|null  $destinatarios
     */
    private function finalizar(AvisoSuscripcion $aviso, EstadoAviso $estado, ?array $destinatarios = null, ?Carbon $enviado = null): void
    {
        $aviso->estado = $estado;
        $aviso->destinatarios = $destinatarios;
        $aviso->enviado_en = $enviado;
        $aviso->error = null;
        $aviso->save();
    }
}
