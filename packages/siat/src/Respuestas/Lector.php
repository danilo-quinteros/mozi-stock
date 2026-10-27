<?php

namespace MoziStudios\Siat\Respuestas;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use MoziStudios\Siat\Excepciones\ErrorComunicacion;

/**
 * Lectura tolerante de los objetos que devuelve SoapClient: un campo ausente es null y una lista
 * puede llegar vacía, como objeto único o como arreglo.
 *
 * @internal
 */
final class Lector
{
    private const ZONA_SIN = 'America/La_Paz';

    public static function objeto(object $origen, string $campo): object
    {
        $valor = $origen->{$campo} ?? null;

        if (! is_object($valor)) {
            throw new ErrorComunicacion("La respuesta del SIN no contiene «{$campo}».");
        }

        return $valor;
    }

    public static function texto(object $origen, string $campo): ?string
    {
        $valor = $origen->{$campo} ?? null;

        return is_scalar($valor) && (string) $valor !== '' ? (string) $valor : null;
    }

    public static function entero(object $origen, string $campo): ?int
    {
        $valor = $origen->{$campo} ?? null;

        return is_numeric($valor) ? (int) $valor : null;
    }

    public static function booleano(object $origen, string $campo): bool
    {
        $valor = $origen->{$campo} ?? false;

        return $valor === true || $valor === 'true' || $valor === 1 || $valor === '1';
    }

    public static function fecha(object $origen, string $campo): ?DateTimeImmutable
    {
        $texto = self::texto($origen, $campo);

        if ($texto === null) {
            return null;
        }

        // El SIN entrega algunas fechas sin zona (p. ej. sincronizarFechaHora) en hora de Bolivia: se leen
        // en America/La_Paz y no en la zona por defecto de PHP. Si la fecha trae su desfase, prevalece.
        try {
            return new DateTimeImmutable($texto, new DateTimeZone(self::ZONA_SIN));
        } catch (Exception) {
            return null;
        }
    }

    /**
     * @return list<object>
     */
    public static function lista(object $origen, string $campo): array
    {
        $valor = $origen->{$campo} ?? null;

        if (is_object($valor)) {
            return [$valor];
        }

        return is_array($valor) ? array_values(array_filter($valor, is_object(...))) : [];
    }

    /**
     * @return list<Mensaje>
     */
    public static function mensajes(object $origen, string $campo = 'mensajesList'): array
    {
        return array_map(fn (object $mensaje): Mensaje => new Mensaje(
            self::entero($mensaje, 'codigo') ?? 0,
            self::texto($mensaje, 'descripcion') ?? '',
            isset($mensaje->advertencia) ? self::booleano($mensaje, 'advertencia') : null,
            self::entero($mensaje, 'numeroArchivo'),
            self::entero($mensaje, 'numeroDetalle'),
        ), self::lista($origen, $campo));
    }
}
