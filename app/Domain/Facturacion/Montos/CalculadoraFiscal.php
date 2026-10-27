<?php

namespace App\Domain\Facturacion\Montos;

use InvalidArgumentException;

/**
 * Montos de la factura compra-venta V3 a partir de una venta con precios FINALES (IVA incluido, D1).
 * Es el único lugar donde se decide el redondeo fiscal; la emisión solo usa su resultado.
 *
 * Reglas del SIN (anexo «Validaciones Documentos Sector», confirmadas en el piloto el 24/10/2026):
 *   subTotal = round(cantidad × precioNetoUnitario − montoDescuento)
 *   totalPrecioNeto = Σ subTotal − descuentoAdicional
 *   iva = round(totalPrecioNeto × 0,13), HALF-UP a 2 decimales, sin tolerancia (código 1065)
 *   montoaPagar = totalPrecioNeto + iva
 *
 * Cómo calcula, todo en centavos enteros (sin float):
 *   1. Busca el único totalPrecioNeto cuyo montoaPagar es EXACTAMENTE el total cobrado.
 *   2. Si no existe (≈ 11,5 % de los totales, por ejemplo Bs 1,00 o 100,00), aplica totalNoFacturable().
 *   3. Si existe, lo reparte entre las líneas. El neto unitario es el bruto de la línea ÷ 1,13 ÷ cantidad
 *      (con cantidades enteras, precio ÷ 1,13) redondeado hacia abajo o hacia arriba; los descuentos reales
 *      de la venta se pasan a neto del mismo modo.
 *   4. Primero busca una combinación con cada línea entera (un único neto por línea). Solo si no existe y
 *      DIVIDIR_LINEAS está activo, una línea de varias unidades sale como dos líneas del mismo producto:
 *      unas unidades con el neto hacia abajo y otras con el neto hacia arriba (Bs 0,01 de diferencia por
 *      unidad), dividiendo las menos líneas posible. Sin eso, por ejemplo, 3 × Bs 7,00 = Bs 21,00 no se
 *      podría expresar sin un descuento.
 *   5. Nunca agrega descuentos ni ajusta el IVA, y lo cobrado no cambia. Si aun así no cuadra, bloquea.
 *
 * PUNTOS DE CAMBIO cuando el propietario defina la solución definitiva:
 *   - totalNoFacturable(): qué hacer con los totales sin neto exacto (hoy: bloquear e informar).
 *   - DIVIDIR_LINEAS: false bloquea las facturas que solo cuadran dividiendo líneas.
 */
final class CalculadoraFiscal
{
    /** Permite expresar una línea como dos líneas del mismo producto con netos que difieren en Bs 0,01. */
    public const DIVIDIR_LINEAS = true;

    /** Máximo de líneas de detalle por factura (XSD compra-venta). */
    public const MAX_LINEAS = 500;

    /** Margen, en centavos, de las sumas parciales en la búsqueda sin dividir líneas (acota su costo). */
    private const VENTANA = 300;

    /** Costo de dividir una línea más: mayor que cualquier desvío, para dividir lo menos posible. */
    private const COSTO_DIVIDIR = 1_000_000_000;

    /**
     * @param  list<LineaVenta>  $lineas
     * @param  string  $descuentoGlobal  descuento global final de la venta, con IVA
     * @param  string  $totalVenta  lo que paga el cliente (ventas.total)
     */
    public static function calcular(array $lineas, string $descuentoGlobal, string $totalVenta): ResultadoFiscal
    {
        $total = self::centavos($totalVenta);

        if ($lineas === []) {
            return ResultadoFiscal::bloqueada(__('La venta no tiene productos.'), self::texto($total));
        }

        if (count($lineas) > self::MAX_LINEAS) {
            return ResultadoFiscal::bloqueada(__('La factura tendría más de :maximo líneas de detalle.', ['maximo' => self::MAX_LINEAS]), self::texto($total));
        }

        $neto = self::netoExacto($total);

        if ($neto === null) {
            return self::totalNoFacturable($total);
        }

        $estados = [];

        foreach ($lineas as $indice => $linea) {
            $cantidad = self::centesimos($linea->cantidad);

            if ($cantidad === null) {
                return ResultadoFiscal::bloqueada(__('La línea :linea tiene una cantidad con más de 2 decimales; el SIN admite solo 2.', ['linea' => $indice + 1]), self::texto($total));
            }

            $estado = self::lineaInicial($cantidad, self::centavos($linea->precioUnitario), self::centavos($linea->descuento), self::centavos($linea->total));

            if ($estado === null) {
                return ResultadoFiscal::bloqueada(__('No se pudo calcular el neto de la línea :linea.', ['linea' => $indice + 1]), self::texto($total));
            }

            $estados[] = $estado;
        }

        $global = self::centavos($descuentoGlobal);
        $descuentos = $global === 0 ? [0] : self::netos($global);
        $elegido = self::masCercano($descuentos, $global);
        [$cercanos, $elegidoCercano, $exacta] = self::combinarSinDividir($estados, $descuentos, $elegido, $global, $neto);

        // Sin combinación exacta de líneas enteras se divide lo mínimo, primero desde la más cercana.
        if ($exacta || self::ajustar($cercanos, $descuentos, $elegidoCercano, $global, $neto)) {
            [$estados, $elegido] = [$cercanos, $elegidoCercano];
        } elseif (! self::ajustar($estados, $descuentos, $elegido, $global, $neto)) {
            return ResultadoFiscal::bloqueada(__('El total se puede facturar, pero sus líneas no se pueden expresar en netos sin agregar descuentos. La factura queda bloqueada.'), self::texto($total));
        }

        $resultado = [];
        $suma = 0;

        foreach ($estados as $indice => $estado) {
            $partes = self::partes($estado, $indice);

            if ($partes === null) {
                return ResultadoFiscal::bloqueada(__('No se pudo repartir el descuento de la línea :linea.', ['linea' => $indice + 1]), self::texto($total));
            }

            array_push($resultado, ...$partes);
            $suma += $estado->subtotal();
        }

        if (count($resultado) > self::MAX_LINEAS) {
            return ResultadoFiscal::bloqueada(__('La factura tendría más de :maximo líneas de detalle.', ['maximo' => self::MAX_LINEAS]), self::texto($total));
        }

        $descuentoNeto = $descuentos[$elegido];
        $iva = self::iva($neto);

        if ($suma - $descuentoNeto !== $neto || $neto + $iva !== $total) {
            // Garantía interna: el resultado siempre debe cumplir las fórmulas del SIN.
            return ResultadoFiscal::bloqueada(__('No se pudo cuadrar la factura con las fórmulas del SIN.'), self::texto($total));
        }

        return ResultadoFiscal::cuadra($resultado, self::texto($suma), $global === 0 ? null : self::texto($descuentoNeto),
            self::texto($neto), self::texto($iva), self::texto($total));
    }

    /**
     * Totales facturables inmediatamente inferior y superior cuando $total no tiene neto exacto; null si lo tiene.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function totalesVecinos(string $total): ?array
    {
        $centavos = self::centavos($total);

        if (self::netoExacto($centavos) !== null) {
            return null;
        }

        [$inferior, $superior] = self::vecinos($centavos);

        return [self::texto($inferior), self::texto($superior)];
    }

    /**
     * Decisión pendiente del propietario (D1): qué hacer cuando ningún neto da exactamente el total cobrado.
     * Mientras no haya una solución definitiva, la factura se bloquea: no se ajusta el IVA, no se agrega un
     * descuento y no se cambia lo cobrado. La venta sigue confirmada; solo esa factura queda sin emitir.
     */
    private static function totalNoFacturable(int $total): ResultadoFiscal
    {
        [$inferior, $superior] = self::vecinos($total);

        return ResultadoFiscal::bloqueada(
            __('El total Bs :total no se puede facturar exacto: con el IVA del SIN solo cuadran Bs :inferior o Bs :superior. La factura queda bloqueada hasta definir cómo tratar estos totales.', [
                'total' => self::texto($total), 'inferior' => self::texto($inferior), 'superior' => self::texto($superior),
            ]),
            self::texto($total),
            self::texto($inferior),
            self::texto($superior),
        );
    }

    /**
     * Estado inicial de una línea: entera (un único neto) y lo más cerca posible de su neto ideal (total final ÷ 1,13).
     */
    private static function lineaInicial(int $cantidad, int $precio, int $descuento, int $totalLinea): ?RepartoLinea
    {
        if ($cantidad <= 0) {
            return null;
        }

        $base = new RepartoLinea($cantidad, self::netosUnitarios($cantidad, $precio), $descuento === 0 ? [0] : self::netos($descuento), $totalLinea, self::DIVIDIR_LINEAS);
        $mejor = null;

        foreach ($base->sinDividir() as $estado) {
            if ($mejor === null || $estado->desvio() < $mejor->desvio()) {
                $mejor = $estado;
            }
        }

        return $mejor;
    }

    /**
     * Busca la combinación de líneas enteras (un único neto por línea) y del descuento global más cercana al
     * objetivo y, entre las igual de cercanas, la de menor desvío total. Si llega exacta, ninguna línea se
     * divide. Programación dinámica sobre la diferencia, en centavos, con el estado inicial; las sumas
     * parciales se acotan a VENTANA centavos más allá del tramo entre 0 y lo que falta.
     *
     * @param  array<int, RepartoLinea>  $estados
     * @param  list<int>  $descuentos  netos posibles del descuento global
     * @return array{0: array<int, RepartoLinea>, 1: int, 2: bool} estados, índice del descuento global y si es exacta
     */
    private static function combinarSinDividir(array $estados, array $descuentos, int $elegido, int $global, int $objetivo): array
    {
        $falta = $objetivo - self::netoActual($estados, $descuentos[$elegido]);
        $minimo = min(0, $falta) - self::VENTANA;
        $maximo = max(0, $falta) + self::VENTANA;
        $variantes = [];
        $opciones = [];

        foreach ($estados as $i => $estado) {
            $variantes[$i] = $estado->sinDividir();

            foreach ($variantes[$i] as $k => $variante) {
                $opciones[$i][$k] = [$variante->subtotal() - $estado->subtotal(), $variante->desvio() - $estado->desvio()];
            }
        }

        $tabla = [0 => 0];
        $padres = [];

        foreach ($opciones as $i => $deLinea) {
            $siguiente = [];

            foreach ($tabla as $diferencia => $costo) {
                foreach ($deLinea as $k => [$delta, $extra]) {
                    $nueva = $diferencia + $delta;

                    if ($nueva >= $minimo && $nueva <= $maximo && (! isset($siguiente[$nueva]) || $costo + $extra < $siguiente[$nueva])) {
                        $siguiente[$nueva] = $costo + $extra;
                        $padres[$i][$nueva] = [$diferencia, $k];
                    }
                }
            }

            $tabla = $siguiente;
        }

        // El descuento global resta: con la opción $k las líneas deben aportar $falta + (opción − elegida).
        $mejor = null;

        foreach ($descuentos as $k => $descuento) {
            $necesaria = $falta + $descuento - $descuentos[$elegido];
            $desvioGlobal = abs($descuento * 113 - $global * 100);

            foreach ($tabla as $diferencia => $costo) {
                $candidato = [abs($necesaria - $diferencia), $costo + $desvioGlobal, $diferencia, $k];

                if ($mejor === null || [$candidato[0], $candidato[1]] < [$mejor[0], $mejor[1]]) {
                    $mejor = $candidato;
                }
            }
        }

        // La tabla nunca está vacía: el estado inicial de cada línea es una de sus variantes (diferencia 0).
        [$distancia, , $diferencia, $indiceGlobal] = $mejor ?? [0, 0, 0, $elegido];

        for ($i = count($estados) - 1; $i >= 0; $i--) {
            [$diferencia, $k] = $padres[$i][$diferencia];
            $estados[$i] = $variantes[$i][$k];
        }

        return [$estados, $indiceGlobal, $distancia === 0];
    }

    /**
     * Solo si no hay combinación sin dividir: mueve, de a Bs 0,01, unidades entre netos, descuentos netos o
     * el descuento global hasta que el neto total sea exactamente el buscado. Cada paso elige el movimiento
     * que menos aleja del ideal y evita dividir otra línea si puede seguir moviendo una ya dividida; como
     * cada paso acerca un centavo, termina en tantos pasos como centavos faltaban.
     *
     * @param  array<int, RepartoLinea>  $estados
     * @param  list<int>  $descuentos  netos posibles del descuento global
     */
    private static function ajustar(array &$estados, array $descuentos, int &$elegido, int $global, int $objetivo): bool
    {
        while (true) {
            $falta = $objetivo - self::netoActual($estados, $descuentos[$elegido]);

            if ($falta === 0) {
                return true;
            }

            $sentido = $falta > 0 ? 1 : -1;
            $mejor = null;

            foreach ($estados as $i => $estado) {
                foreach ($estado->movimientos($sentido) as $nuevo) {
                    $costo = $nuevo->desvio() - $estado->desvio() + ($nuevo->dividida() && ! $estado->dividida() ? self::COSTO_DIVIDIR : 0);

                    if ($mejor === null || $costo < $mejor['costo']) {
                        $mejor = ['costo' => $costo, 'linea' => $i, 'estado' => $nuevo, 'global' => null];
                    }
                }
            }

            // El descuento global resta: para subir el neto se usa su opción menor, y al revés.
            $otro = 1 - $elegido;

            if (isset($descuentos[$otro]) && $sentido === $descuentos[$elegido] - $descuentos[$otro]) {
                $costo = abs($descuentos[$otro] * 113 - $global * 100) - abs($descuentos[$elegido] * 113 - $global * 100);

                if ($mejor === null || $costo < $mejor['costo']) {
                    $mejor = ['costo' => $costo, 'linea' => null, 'estado' => null, 'global' => $otro];
                }
            }

            if ($mejor === null) {
                return false;
            }

            if ($mejor['global'] !== null) {
                $elegido = $mejor['global'];
            } else {
                $estados[$mejor['linea']] = $mejor['estado'];
            }
        }
    }

    /**
     * Partes fiscales de una línea: unidades al neto bajo y al neto alto. El descuento neto va a la parte
     * baja si cabe; si no, se completa en la alta.
     *
     * @return list<LineaFiscal>|null
     */
    private static function partes(RepartoLinea $estado, int $indice): ?array
    {
        $descuento = $estado->descuento();
        $enBajo = min($descuento, $estado->brutoBajo());
        $enAlto = $descuento - $enBajo;

        if ($enAlto > $estado->brutoAlto()) {
            return null;
        }

        $conDescuento = $estado->descuentos !== [0];
        $partes = [];

        foreach ([[$estado->cantidadBaja(), $estado->netoBajo(), $enBajo, $estado->brutoBajo()], [$estado->cantidadAlta(), $estado->netoAlto(), $enAlto, $estado->brutoAlto()]] as [$cantidad, $unitario, $parte, $bruto]) {
            if ($cantidad > 0) {
                $partes[] = new LineaFiscal($indice, self::texto($cantidad), self::texto($unitario),
                    $conDescuento ? self::texto($parte) : null, self::texto($bruto - $parte));
            }
        }

        return $partes;
    }

    /**
     * @param  list<int>  $netos
     */
    private static function masCercano(array $netos, int $final): int
    {
        $mejor = 0;

        foreach ($netos as $i => $neto) {
            if (abs($neto * 113 - $final * 100) < abs($netos[$mejor] * 113 - $final * 100)) {
                $mejor = $i;
            }
        }

        return $mejor;
    }

    /** Neto (totalPrecioNeto) cuyo montoaPagar es exactamente $total; montoaPagar crece con el neto, así que es único. */
    private static function netoExacto(int $total): ?int
    {
        $base = intdiv($total * 100, 113);

        for ($neto = max(0, $base - 2); $neto <= $base + 2; $neto++) {
            if ($neto + self::iva($neto) === $total) {
                return $neto;
            }
        }

        return null;
    }

    /**
     * @return array{0: int, 1: int}
     */
    private static function vecinos(int $total): array
    {
        $inferior = $total - 1;
        while ($inferior > 0 && self::netoExacto($inferior) === null) {
            $inferior--;
        }

        $superior = $total + 1;
        while (self::netoExacto($superior) === null) {
            $superior++;
        }

        return [max(0, $inferior), $superior];
    }

    /** IVA del SIN en centavos: round(neto × 13 / 100) HALF-UP; el neto nunca es negativo. */
    private static function iva(int $neto): int
    {
        return intdiv($neto * 13 + 50, 100);
    }

    /**
     * @param  array<int, RepartoLinea>  $estados
     */
    private static function netoActual(array $estados, int $descuentoGlobal): int
    {
        return array_sum(array_map(fn (RepartoLinea $e): int => $e->subtotal(), $estados)) - $descuentoGlobal;
    }

    /**
     * Neto (÷ 1,13) de un importe final en centavos: el exacto, o redondeado hacia abajo y hacia arriba.
     *
     * @return list<int>
     */
    private static function netos(int $final): array
    {
        $base = intdiv($final * 100, 113);

        return $final * 100 === $base * 113 ? [$base] : [$base, $base + 1];
    }

    /**
     * Netos unitarios posibles de una línea: su importe bruto ÷ 1,13 ÷ cantidad, exacto o hacia abajo y arriba.
     * Con cantidades enteras coincide con precio ÷ 1,13; con fraccionarias (1,10 × Bs 5,04 = Bs 5,54) evita
     * netos cuyo subtotal salta el ideal de la línea (4,46 × 1,10 = 4,91; el ideal es 4,90 = 4,45 × 1,10).
     *
     * @return list<int>
     */
    private static function netosUnitarios(int $cantidad, int $precio): array
    {
        $bruto = intdiv($cantidad * $precio + 50, 100);
        $divisor = 113 * $cantidad;
        $base = intdiv($bruto * 10000, $divisor);

        return $bruto * 10000 === $base * $divisor ? [$base] : [$base, $base + 1];
    }

    private static function centavos(string $valor): int
    {
        if (! is_numeric($valor) || preg_match('/^\d+(\.\d+)?$/', $valor) !== 1 || bccomp($valor, bcadd($valor, '0', 2), 10) !== 0) {
            throw new InvalidArgumentException("Importe inválido para la factura: {$valor}");
        }

        return (int) bcmul($valor, '100', 0);
    }

    /** Cantidad en centésimos; null si tiene más de 2 decimales distintos de cero. */
    private static function centesimos(string $cantidad): ?int
    {
        if (! is_numeric($cantidad) || preg_match('/^\d+(\.\d+)?$/', $cantidad) !== 1) {
            throw new InvalidArgumentException("Cantidad inválida para la factura: {$cantidad}");
        }

        return bccomp($cantidad, bcadd($cantidad, '0', 2), 10) === 0 ? (int) bcmul($cantidad, '100', 0) : null;
    }

    private static function texto(int $centavos): string
    {
        $absoluto = abs($centavos);

        return ($centavos < 0 ? '-' : '').intdiv($absoluto, 100).'.'.str_pad((string) ($absoluto % 100), 2, '0', STR_PAD_LEFT);
    }
}
