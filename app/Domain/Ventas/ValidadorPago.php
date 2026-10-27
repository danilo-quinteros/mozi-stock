<?php

namespace App\Domain\Ventas;

use App\Enums\MetodoPago;
use App\Support\Decimal;
use Illuminate\Validation\ValidationException;

/**
 * Reglas de pago al confirmar una venta (especificación § 3.4):
 *
 * | Modo        | Sin cuotas                  | Con cuotas                          |
 * | Efectivo    | efectivo = total            | efectivo = cuota inicial (0..total) |
 * | Electrónico | electrónico = total         | electrónico = cuota inicial         |
 * | Mixto       | efectivo + electrónico = total | efectivo + electrónico = cuota inicial |
 *
 * Nunca negativos ni por encima del total. Un cobro de Bs 0 no genera registro.
 */
final class ValidadorPago
{
    /**
     * @return array{metodo: MetodoPago|null, monto: string, efectivo: string, electronico: string}
     *
     * @throws ValidationException
     */
    public static function inicial(string $total, ?string $metodo, bool $enCuotas, ?string $cuotaInicial, ?string $efectivo, ?string $electronico): array
    {
        $esperado = $total;

        if ($enCuotas) {
            $inicial = Decimal::normalizar($cuotaInicial ?? '', Decimal::ESCALA_DINERO);

            if ($inicial === null || Decimal::comparar($inicial, '0', 2) < 0 || Decimal::comparar($inicial, $total, 2) > 0) {
                throw ValidationException::withMessages([
                    'pago_cuota_inicial' => __('La cuota inicial debe estar entre Bs 0,00 y el total (:total).', ['total' => Decimal::bs($total)]),
                ]);
            }

            $esperado = $inicial;
        }

        if (Decimal::comparar($esperado, '0', 2) === 0) {
            return ['metodo' => null, 'monto' => '0.00', 'efectivo' => '0.00', 'electronico' => '0.00'];
        }

        $modo = MetodoPago::tryFrom((string) $metodo)
            ?? throw ValidationException::withMessages(['pago_metodo' => __('Elige Efectivo, Electrónico o Mixto.')]);
        $esperado = Decimal::redondear($esperado);

        if ($modo === MetodoPago::Efectivo) {
            return ['metodo' => $modo, 'monto' => $esperado, 'efectivo' => $esperado, 'electronico' => '0.00'];
        }

        if ($modo === MetodoPago::Electronico) {
            return ['metodo' => $modo, 'monto' => $esperado, 'efectivo' => '0.00', 'electronico' => $esperado];
        }

        $enEfectivo = Decimal::normalizar(blank($efectivo) ? '0' : $efectivo, Decimal::ESCALA_DINERO);
        $enElectronico = Decimal::normalizar(blank($electronico) ? '0' : $electronico, Decimal::ESCALA_DINERO);

        if ($enEfectivo === null || $enElectronico === null
            || Decimal::comparar($enEfectivo, '0', 2) < 0 || Decimal::comparar($enElectronico, '0', 2) < 0) {
            throw ValidationException::withMessages(['pago_efectivo' => __('Los importes no pueden ser negativos (hasta 2 decimales).')]);
        }

        if (Decimal::comparar(Decimal::sumar($enEfectivo, $enElectronico, 2), $esperado, 2) !== 0) {
            throw ValidationException::withMessages([
                'pago_efectivo' => $enCuotas
                    ? __('Efectivo + electrónico deben sumar :importe (cuota inicial).', ['importe' => Decimal::bs($esperado)])
                    : __('Efectivo + electrónico deben sumar :importe (total del pedido).', ['importe' => Decimal::bs($esperado)]),
            ]);
        }

        return ['metodo' => $modo, 'monto' => $esperado, 'efectivo' => $enEfectivo, 'electronico' => $enElectronico];
    }

    /**
     * Abono posterior (V3 § 6): Efectivo o Electrónico con un importe; Mixto con dos importes
     * cuya suma es el abono. Estrictamente mayor que 0 y nunca mayor que el saldo.
     *
     * @return array{metodo: MetodoPago, monto: string, efectivo: string, electronico: string}
     *
     * @throws ValidationException
     */
    public static function abono(string $saldo, ?string $metodo, ?string $monto, ?string $efectivo, ?string $electronico): array
    {
        $modo = MetodoPago::tryFrom((string) $metodo)
            ?? throw ValidationException::withMessages(['metodo' => __('Elige Efectivo, Electrónico o Mixto.')]);

        if ($modo === MetodoPago::Mixto) {
            $enEfectivo = Decimal::normalizar(blank($efectivo) ? '0' : $efectivo, Decimal::ESCALA_DINERO);
            $enElectronico = Decimal::normalizar(blank($electronico) ? '0' : $electronico, Decimal::ESCALA_DINERO);

            if ($enEfectivo === null || $enElectronico === null
                || Decimal::comparar($enEfectivo, '0', 2) < 0 || Decimal::comparar($enElectronico, '0', 2) < 0) {
                throw ValidationException::withMessages(['efectivo' => __('Los importes no pueden ser negativos (hasta 2 decimales).')]);
            }
        } else {
            $importe = Decimal::normalizar($monto ?? '', Decimal::ESCALA_DINERO);

            if ($importe === null || Decimal::comparar($importe, '0', 2) < 0) {
                throw ValidationException::withMessages(['monto' => __('Ingresa un importe válido (hasta 2 decimales).')]);
            }

            [$enEfectivo, $enElectronico] = $modo === MetodoPago::Efectivo ? [$importe, '0.00'] : ['0.00', $importe];
        }

        $total = Decimal::sumar($enEfectivo, $enElectronico, Decimal::ESCALA_DINERO);
        $campo = $modo === MetodoPago::Mixto ? 'efectivo' : 'monto';

        if (Decimal::comparar($total, '0', 2) <= 0) {
            throw ValidationException::withMessages([$campo => __('El abono debe ser mayor que Bs 0,00.')]);
        }

        if (Decimal::comparar($total, $saldo, 2) > 0) {
            throw ValidationException::withMessages([$campo => __('El abono no puede superar el saldo pendiente (:saldo).', ['saldo' => Decimal::bs($saldo)])]);
        }

        return ['metodo' => $modo, 'monto' => $total, 'efectivo' => $enEfectivo, 'electronico' => $enElectronico];
    }

    /**
     * Pago de un importe que ya fijó el servidor (total de una compra o reembolso de una
     * devolución): Efectivo o Electrónico por el importe completo; Mixto con dos importes que
     * suman exactamente ese importe. El importe debe ser mayor que 0.
     *
     * @return array{metodo: MetodoPago, efectivo: string, electronico: string}
     *
     * @throws ValidationException
     */
    public static function exacto(string $importe, ?string $metodo, ?string $efectivo, ?string $electronico, string $concepto): array
    {
        $modo = MetodoPago::tryFrom((string) $metodo)
            ?? throw ValidationException::withMessages(['metodo' => __('Elige Efectivo, Electrónico o Mixto.')]);
        $importe = Decimal::redondear($importe);

        if ($modo === MetodoPago::Efectivo) {
            return ['metodo' => $modo, 'efectivo' => $importe, 'electronico' => '0.00'];
        }

        if ($modo === MetodoPago::Electronico) {
            return ['metodo' => $modo, 'efectivo' => '0.00', 'electronico' => $importe];
        }

        $enEfectivo = Decimal::normalizar(blank($efectivo) ? '0' : $efectivo, Decimal::ESCALA_DINERO);
        $enElectronico = Decimal::normalizar(blank($electronico) ? '0' : $electronico, Decimal::ESCALA_DINERO);

        if ($enEfectivo === null || $enElectronico === null
            || Decimal::comparar($enEfectivo, '0', 2) < 0 || Decimal::comparar($enElectronico, '0', 2) < 0) {
            throw ValidationException::withMessages(['efectivo' => __('Los importes no pueden ser negativos (hasta 2 decimales).')]);
        }

        if (Decimal::comparar(Decimal::sumar($enEfectivo, $enElectronico, 2), $importe, 2) !== 0) {
            throw ValidationException::withMessages([
                'efectivo' => __('Efectivo + electrónico deben sumar :importe (:concepto).', ['importe' => Decimal::bs($importe), 'concepto' => __($concepto)]),
            ]);
        }

        return ['metodo' => $modo, 'efectivo' => $enEfectivo, 'electronico' => $enElectronico];
    }
}
