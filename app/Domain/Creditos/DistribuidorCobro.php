<?php

namespace App\Domain\Creditos;

use App\Support\Decimal;
use Illuminate\Validation\ValidationException;

/**
 * Reparto de un cobro entre saldos pendientes (§ 24.1). Cálculo puro sobre importes
 * decimales; quien llama bloquea las filas y guarda el resultado.
 *
 * Cada pendiente se identifica con una clave «venta:ID», «cuota:ID» o «cuenta:ID». Nunca se
 * aplica más de lo que se adeuda ni se crea saldo a favor.
 */
final class DistribuidorCobro
{
    /**
     * Aplicación automática: los pendientes ya vienen ordenados por prioridad (vencimiento
     * más antiguo primero). Cubre cada saldo antes de pasar al siguiente.
     *
     * @param  list<array{clave: string, saldo: string}>  $pendientes
     * @return array<string, string> clave => importe aplicado
     *
     * @throws ValidationException
     */
    public static function automatico(array $pendientes, string $monto, string $campo = 'monto'): array
    {
        $restante = Decimal::redondear($monto);
        $aplicado = [];

        foreach ($pendientes as $pendiente) {
            if (Decimal::comparar($restante, '0', 2) <= 0) {
                break;
            }

            $parte = Decimal::comparar($pendiente['saldo'], $restante, 2) < 0 ? Decimal::redondear($pendiente['saldo']) : $restante;

            if (Decimal::comparar($parte, '0', 2) > 0) {
                $aplicado[$pendiente['clave']] = $parte;
                $restante = Decimal::restar($restante, $parte, Decimal::ESCALA_DINERO);
            }
        }

        if (Decimal::comparar($restante, '0', 2) > 0) {
            throw ValidationException::withMessages([$campo => __('El cobro supera el saldo pendiente por :importe.', ['importe' => Decimal::bs($restante)])]);
        }

        return $aplicado;
    }

    /**
     * Aplicación manual: el usuario indica cuánto va a cada saldo. Cada importe debe ser
     * válido y no superar su saldo, y la suma debe ser exactamente el cobro.
     *
     * @param  array<string, string>  $saldos  clave => saldo pendiente
     * @param  array<array-key, mixed>  $asignaciones  clave => importe ingresado
     * @return array<string, string> clave => importe aplicado (sin ceros)
     *
     * @throws ValidationException
     */
    public static function manual(array $saldos, array $asignaciones, string $monto, string $campo = 'asignaciones'): array
    {
        $aplicado = [];
        $suma = '0.00';

        foreach ($asignaciones as $clave => $valor) {
            $texto = is_scalar($valor) ? trim((string) $valor) : '';

            if ($texto === '') {
                continue;
            }

            $importe = Decimal::normalizar($texto, Decimal::ESCALA_DINERO);

            if ($importe === null || Decimal::comparar($importe, '0', 2) < 0) {
                throw ValidationException::withMessages([$campo => __('Los importes asignados deben ser válidos (hasta 2 decimales).')]);
            }

            if (Decimal::comparar($importe, '0', 2) === 0) {
                continue;
            }

            if (! array_key_exists((string) $clave, $saldos)) {
                throw ValidationException::withMessages([$campo => __('Uno de los saldos elegidos ya no está pendiente. Recarga la página.')]);
            }

            if (Decimal::comparar($importe, $saldos[(string) $clave], 2) > 0) {
                throw ValidationException::withMessages([$campo => __('Un importe asignado supera su saldo pendiente (:saldo).', ['saldo' => Decimal::bs($saldos[(string) $clave])])]);
            }

            $aplicado[(string) $clave] = $importe;
            $suma = Decimal::sumar($suma, $importe, Decimal::ESCALA_DINERO);
        }

        if ($aplicado === []) {
            throw ValidationException::withMessages([$campo => __('Asigna el cobro al menos a un saldo pendiente.')]);
        }

        if (Decimal::comparar($suma, $monto, 2) !== 0) {
            throw ValidationException::withMessages([$campo => __('Lo asignado (:asignado) debe ser igual al cobro (:cobro).', ['asignado' => Decimal::bs($suma), 'cobro' => Decimal::bs($monto)])]);
        }

        return $aplicado;
    }

    /**
     * Desglose informativo por medio de cada aplicación: el efectivo se asigna primero, en el
     * orden de aplicación, y el resto es electrónico. La suma por medio es exactamente la del cobro.
     *
     * @param  array<string, string>  $aplicado  clave => importe
     * @return array<string, array{efectivo: string, electronico: string}>
     */
    public static function medios(array $aplicado, string $efectivo): array
    {
        $restante = Decimal::redondear($efectivo);
        $medios = [];

        foreach ($aplicado as $clave => $importe) {
            $enEfectivo = Decimal::comparar($restante, $importe, 2) < 0 ? $restante : $importe;
            $restante = Decimal::restar($restante, $enEfectivo, Decimal::ESCALA_DINERO);
            $medios[$clave] = ['efectivo' => $enEfectivo, 'electronico' => Decimal::restar($importe, $enEfectivo, Decimal::ESCALA_DINERO)];
        }

        return $medios;
    }
}
