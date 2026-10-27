<?php

namespace App\Enums;

enum EstadoAviso: string
{
    case Pendiente = 'pendiente';
    case Enviando = 'enviando';
    case Enviado = 'enviado';
    // El servidor no tiene correo configurado (MAIL_MAILER array/log): solo queda el aviso del panel.
    case NoEnviado = 'no_enviado';
    case SinDestinatarios = 'sin_destinatarios';
    case Fallido = 'fallido';
    // Ya no corresponde al enviarse: el período se pagó o la empresa está desactivada.
    case Omitido = 'omitido';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Pendiente, self::Enviando => __('En cola'),
            self::Enviado => __('Enviado'),
            self::NoEnviado => __('Correo sin configurar'),
            self::SinDestinatarios => __('Sin destinatarios'),
            self::Fallido => __('Falló el envío'),
            self::Omitido => __('Omitido'),
        };
    }

    public function insignia(): string
    {
        return match ($this) {
            self::Pendiente, self::Enviando => 'info',
            self::Enviado => 'success',
            self::NoEnviado, self::SinDestinatarios, self::Omitido => 'warning',
            self::Fallido => 'danger',
        };
    }
}
