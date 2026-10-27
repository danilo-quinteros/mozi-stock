<?php

namespace App\Domain\Facturacion\Montos;

/**
 * @internal Estado de una línea mientras CalculadoraFiscal busca sus netos. Inmutable: cada movimiento
 *           devuelve un estado nuevo. Montos en centavos y cantidades en centésimos, siempre enteros.
 *
 * Con $dividir, las unidades enteras pueden ir al neto bajo o al alto: cada unidad que pasa al alto suma
 * exactamente Bs 0,01 al subtotal. Sin $dividir, toda la línea usa un único neto ($u).
 */
final class RepartoLinea
{
    /**
     * @param  list<int>  $netos  netos posibles del precio: uno si es exacto, o el de abajo y el de arriba
     * @param  list<int>  $descuentos  netos posibles del descuento de la línea ([0] si no tiene)
     * @param  int  $total  total final de la línea (con IVA), para medir el desvío
     */
    public function __construct(
        public readonly int $cantidad,
        public readonly array $netos,
        public readonly array $descuentos,
        public readonly int $total,
        public readonly bool $dividir,
        public readonly int $u = 0,
        public readonly int $d = 0,
        public readonly int $alto = 0,
        public readonly bool $fraccionAlta = false,
    ) {}

    public function con(?int $u = null, ?int $d = null, ?int $alto = null, ?bool $fraccionAlta = null): self
    {
        return new self($this->cantidad, $this->netos, $this->descuentos, $this->total, $this->dividir,
            $u ?? $this->u, $d ?? $this->d, $alto ?? $this->alto, $fraccionAlta ?? $this->fraccionAlta);
    }

    public function divisible(): bool
    {
        return $this->dividir && count($this->netos) === 2;
    }

    public function enteras(): int
    {
        return $this->divisible() ? intdiv($this->cantidad, 100) : 0;
    }

    public function fraccion(): int
    {
        return $this->divisible() ? $this->cantidad % 100 : 0;
    }

    public function netoBajo(): int
    {
        return $this->divisible() ? $this->netos[0] : $this->netos[$this->u];
    }

    public function netoAlto(): int
    {
        return $this->divisible() ? $this->netos[1] : $this->netos[$this->u];
    }

    public function cantidadAlta(): int
    {
        return $this->alto * 100 + ($this->fraccionAlta ? $this->fraccion() : 0);
    }

    public function cantidadBaja(): int
    {
        return $this->cantidad - $this->cantidadAlta();
    }

    public function descuento(): int
    {
        return $this->descuentos[$this->d];
    }

    public function brutoBajo(): int
    {
        return self::redondear($this->cantidadBaja() * $this->netoBajo());
    }

    public function brutoAlto(): int
    {
        return self::redondear($this->cantidadAlta() * $this->netoAlto());
    }

    public function subtotal(): int
    {
        return $this->brutoBajo() + $this->brutoAlto() - $this->descuento();
    }

    /** Distancia, en 1/113 de centavo, entre el subtotal neto y su ideal: total final ÷ 1,13. */
    public function desvio(): int
    {
        return abs($this->subtotal() * 113 - $this->total * 100);
    }

    /** La línea sale como dos líneas fiscales: unas unidades con el neto bajo y otras con el alto. */
    public function dividida(): bool
    {
        return $this->cantidadBaja() > 0 && $this->cantidadAlta() > 0;
    }

    /**
     * Variantes en que la línea sale entera, con un único neto: todas las unidades abajo o todas arriba.
     *
     * @return list<self>
     */
    public function sinDividir(): array
    {
        $variantes = [];

        foreach (array_keys($this->descuentos) as $d) {
            if ($this->divisible()) {
                $variantes[] = $this->con(d: $d, alto: 0, fraccionAlta: false);
                $variantes[] = $this->con(d: $d, alto: $this->enteras(), fraccionAlta: true);
            } else {
                foreach (array_keys($this->netos) as $u) {
                    $variantes[] = $this->con(u: $u, d: $d);
                }
            }
        }

        return array_values(array_filter($variantes, fn (self $v): bool => $v->subtotal() >= 0));
    }

    /**
     * Variantes que cambian el subtotal exactamente en $sentido centavos (+1 o −1).
     *
     * @return list<self>
     */
    public function movimientos(int $sentido): array
    {
        $variantes = [];

        if ($this->alto + $sentido >= 0 && $this->alto + $sentido <= $this->enteras()) {
            $variantes[] = $this->con(alto: $this->alto + $sentido);
        }

        if (count($this->descuentos) === 2) {
            $variantes[] = $this->con(d: 1 - $this->d);
        }

        if ($this->fraccion() > 0) {
            $variantes[] = $this->con(fraccionAlta: ! $this->fraccionAlta);
        }

        if (! $this->dividir && count($this->netos) === 2) {
            $variantes[] = $this->con(u: 1 - $this->u);
        }

        $actual = $this->subtotal();

        return array_values(array_filter($variantes, fn (self $v): bool => $v->subtotal() - $actual === $sentido && $v->subtotal() >= 0));
    }

    /** Centésimos de cantidad × centavos → centavos, HALF-UP, una sola vez por línea (como el SIN). */
    private static function redondear(int $centesimosPorCentavos): int
    {
        return intdiv($centesimosPorCentavos + 50, 100);
    }
}
