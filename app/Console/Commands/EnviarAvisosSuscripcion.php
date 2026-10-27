<?php

namespace App\Console\Commands;

use App\Domain\Suscripciones\Actions\ProgramarAvisos;
use App\Jobs\EnviarAvisoSuscripcion;
use Illuminate\Console\Command;

/**
 * Programado a diario (routes/console.php). Se puede ejecutar a mano: repetirlo el mismo día
 * no duplica avisos. Nunca activa ni desactiva empresas.
 */
class EnviarAvisosSuscripcion extends Command
{
    protected $signature = 'mozistock:avisos-suscripcion';

    protected $description = 'Registra y pone en cola los avisos de vencimiento D-5, D0 y D+3 del día';

    public function handle(ProgramarAvisos $programar): int
    {
        if (config('mozistock.deployment_mode') !== 'saas') {
            $this->info('El modo standalone no tiene suscripciones.');

            return self::SUCCESS;
        }

        $nuevos = $programar->ejecutar();
        $this->info($nuevos === 0 ? 'No hay avisos nuevos para hoy.' : "Avisos nuevos en cola: {$nuevos}.");

        if (! EnviarAvisoSuscripcion::correoConfigurado()) {
            $this->warn('El correo no está configurado (MAIL_MAILER): los avisos quedan solo en el panel.');
        }

        return self::SUCCESS;
    }
}
