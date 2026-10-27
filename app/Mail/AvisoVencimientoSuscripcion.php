<?php

namespace App\Mail;

use App\Enums\EventoAviso;
use App\Models\AjusteGlobal;
use App\Models\Empresa;
use App\Models\Suscripcion;
use App\Support\Decimal;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Recordatorio de pago de la suscripción a MoziStock. No incluye datos comerciales de la
 * empresa: solo su plan y cómo pagar.
 */
class AvisoVencimientoSuscripcion extends Mailable
{
    public function __construct(
        public readonly Empresa $empresa,
        public readonly Suscripcion $suscripcion,
        public readonly EventoAviso $evento,
        public readonly bool $enRevision,
        public readonly AjusteGlobal $ajustes,
    ) {}

    public function envelope(): Envelope
    {
        $fecha = $this->suscripcion->vencimiento->format('d/m/Y');
        $dias = $this->suscripcion->diasHastaVencimiento();

        return new Envelope(
            subject: match (true) {
                $dias > 0 => __('Tu suscripción a MoziStock vence el :fecha', ['fecha' => $fecha]),
                $dias === 0 => __('Tu suscripción a MoziStock vence hoy'),
                default => __('Tu suscripción a MoziStock venció el :fecha', ['fecha' => $fecha]),
            },
            replyTo: filled($this->ajustes->correo_contacto)
                ? [new Address((string) $this->ajustes->correo_contacto, $this->ajustes->receptor_nombre)] : [],
        );
    }

    public function content(): Content
    {
        $dias = $this->suscripcion->diasHastaVencimiento();

        return new Content(view: 'mail.aviso-suscripcion', with: [
            'fecha' => $this->suscripcion->vencimiento->format('d/m/Y'),
            // El texto depende de los días reales: un aviso puede salir tarde si el servidor no corrió.
            'titulo' => match (true) {
                $dias > 1 => __('Tu suscripción vence en :dias días', ['dias' => $dias]),
                $dias === 1 => __('Tu suscripción vence mañana'),
                $dias === 0 => __('Tu suscripción vence hoy'),
                $dias === -1 => __('Tu suscripción venció ayer'),
                default => __('Tu suscripción venció hace :dias días', ['dias' => abs($dias)]),
            },
            'monto' => Decimal::bs((string) $this->suscripcion->precio_mensual),
            'receptor' => $this->ajustes->receptor_nombre ?: 'MoziStudios',
            'enlace' => route('suscripcion'),
        ]);
    }
}
