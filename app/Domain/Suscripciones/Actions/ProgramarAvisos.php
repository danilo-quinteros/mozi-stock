<?php

namespace App\Domain\Suscripciones\Actions;

use App\Domain\Suscripciones\CalendarioSuscripcion;
use App\Enums\EstadoAviso;
use App\Enums\EstadoSuscripcion;
use App\Enums\EventoAviso;
use App\Jobs\EnviarAvisoSuscripcion;
use App\Models\AvisoSuscripcion;
use App\Models\Scopes\EmpresaScope;
use App\Models\Suscripcion;
use App\Policies\SuscripcionPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Throwable;

/**
 * Tarea diaria (08:00 de La Paz) que registra los avisos D-5, D0 y D+3 que correspondan y
 * los pone en cola. El único (empresa, vencimiento, evento) hace que ejecutarla varias veces
 * no duplique nada (T24). Si el servidor no corrió un día, envía el aviso cuya ventana sigue
 * abierta, nunca dos el mismo día. Omite empresas desactivadas y jamás cambia su acceso (T26).
 */
final class ProgramarAvisos
{
    /**
     * @return int avisos nuevos registrados
     */
    public function ejecutar(?CarbonImmutable $hoy = null): int
    {
        if (! SuscripcionPolicy::saas()) {
            return 0;
        }

        $hoy ??= CalendarioSuscripcion::hoy();
        $nuevos = 0;

        // Solo los vencimientos cuya ventana de aviso incluye hoy: de D-5 a D+5.
        Suscripcion::withoutGlobalScope(EmpresaScope::class)
            ->whereBetween('vencimiento', [
                $hoy->subDays(EventoAviso::Posterior->hasta())->format('Y-m-d'),
                $hoy->addDays(EstadoSuscripcion::DIAS_POR_VENCER)->format('Y-m-d'),
            ])
            ->whereHas('empresa', fn ($q) => $q->where('activa', true))
            ->chunkById(200, function (Collection $lote) use ($hoy, &$nuevos): void {
                /** @var Collection<int, Suscripcion> $lote */
                foreach ($lote as $suscripcion) {
                    $evento = CalendarioSuscripcion::eventoDelDia($suscripcion->vencimiento, $hoy);
                    $aviso = $evento === null ? null : $this->registrar($suscripcion, $evento);

                    if ($aviso === null) {
                        continue;
                    }

                    $nuevos++;

                    try {
                        EnviarAvisoSuscripcion::dispatch($aviso->id);
                    } catch (Throwable $e) {
                        // Con la cola síncrona un fallo de correo no debe frenar a las demás empresas.
                        report($e);
                    }
                }
            });

        return $nuevos;
    }

    private function registrar(Suscripcion $suscripcion, EventoAviso $evento): ?AvisoSuscripcion
    {
        $aviso = new AvisoSuscripcion;
        $aviso->empresa_id = $suscripcion->empresa_id;
        $aviso->suscripcion_id = $suscripcion->id;
        $aviso->vencimiento = $suscripcion->vencimiento;
        $aviso->evento = $evento;
        $aviso->fecha_programada = CalendarioSuscripcion::fechaEvento($suscripcion->vencimiento, $evento);
        $aviso->canal = 'correo';
        $aviso->estado = EstadoAviso::Pendiente;

        try {
            $aviso->save();
        } catch (UniqueConstraintViolationException) {
            return null;
        }

        return $aviso;
    }
}
