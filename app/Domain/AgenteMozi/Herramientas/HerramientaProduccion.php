<?php

namespace App\Domain\AgenteMozi\Herramientas;

use App\Domain\AgenteMozi\ContextoAgente;
use App\Domain\Produccion\Escalado;
use App\Domain\Reportes\Periodo;
use App\Models\Producto;
use App\Support\Decimal;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Base de las herramientas de Producción (AGENTE_MOZI_IA.md § 2.1): solo el administrador con la capacidad (Gate
 * usar-produccion), solo lectura. Producto y sucursal llegan por nombre o código y se resuelven dentro de la empresa
 * de la sesión; un nombre ambiguo vuelve como error de argumentos con hasta 10 coincidencias para que el modelo
 * pregunte. Stock utilizable de una materia prima = saldo de la sucursal − lotes vencidos, nunca negativo, como
 * DisponibilidadMaterias (lo vencido solo cuenta con Lotes y vencimientos activo y en productos que lo controlan).
 */
abstract class HerramientaProduccion extends HerramientaNegocio
{
    /** Ventana de demanda de UC-21 y UC-26 (IA-P1): los últimos 28 días, hoy incluido. */
    protected const DIAS_DEMANDA = 28;

    private const MAX_COINCIDENCIAS = 10;

    public function permiso(): ?string
    {
        return 'usar-produccion';
    }

    /**
     * Unidades netas vendidas (ventas − devoluciones, cada una por su fecha) en la ventana de demanda (por defecto los
     * últimos DIAS_DEMANDA días hasta hoy; el contraste histórico pasa otros días locales), por sucursal y producto: dos
     * consultas.
     *
     * @param  list<int>  $sucursales
     * @param  list<int>  $productos
     * @return array<int, array<int, string>> sucursal_id => producto_id => unidades netas (puede ser negativa)
     */
    protected function ventaNeta(ContextoAgente $contexto, array $sucursales, array $productos, ?string $desde = null, ?string $hasta = null): array
    {
        if ($sucursales === [] || $productos === []) {
            return [];
        }

        $desde ??= $contexto->fechaReferencia->subDays(self::DIAS_DEMANDA - 1)->format('Y-m-d');
        $hasta ??= $contexto->fechaReferencia->format('Y-m-d');
        [$inicio, $fin] = Periodo::rango($desde, $hasta)->limitesUtc();
        $vendidas = DB::table('venta_items as vi')
            ->join('ventas as v', fn (JoinClause $j) => $j->on('v.id', '=', 'vi.venta_id')->on('v.empresa_id', '=', 'vi.empresa_id'))
            ->where('vi.empresa_id', $contexto->empresaId)
            ->whereIn('v.sucursal_id', $sucursales)
            ->whereIn('vi.producto_id', $productos)
            ->where('v.fecha', '>=', $inicio)->where('v.fecha', '<', $fin)
            ->groupBy('v.sucursal_id', 'vi.producto_id')
            ->get(['v.sucursal_id', 'vi.producto_id', DB::raw('SUM(vi.cantidad) AS cantidad')]);
        $devueltas = DB::table('devolucion_items as di')
            ->join('devoluciones as d', fn (JoinClause $j) => $j->on('d.id', '=', 'di.devolucion_id')->on('d.empresa_id', '=', 'di.empresa_id'))
            ->where('di.empresa_id', $contexto->empresaId)
            ->whereIn('d.sucursal_id', $sucursales)
            ->whereIn('di.producto_id', $productos)
            ->where('d.fecha', '>=', $inicio)->where('d.fecha', '<', $fin)
            ->groupBy('d.sucursal_id', 'di.producto_id')
            ->get(['d.sucursal_id', 'di.producto_id', DB::raw('SUM(di.cantidad) AS cantidad')]);
        $neto = [];

        foreach ($vendidas as $fila) {
            $neto[(int) $fila->sucursal_id][(int) $fila->producto_id] = (string) $fila->cantidad;
        }

        foreach ($devueltas as $fila) {
            $actual = $neto[(int) $fila->sucursal_id][(int) $fila->producto_id] ?? '0';
            $neto[(int) $fila->sucursal_id][(int) $fila->producto_id] = Decimal::restar($actual, (string) $fila->cantidad);
        }

        return $neto;
    }

    /**
     * Sucursal activa de la empresa por nombre; null si no se pidió ninguna.
     */
    protected function sucursal(ContextoAgente $contexto, mixed $nombre): ?stdClass
    {
        $texto = is_string($nombre) ? trim($nombre) : '';

        if ($texto === '') {
            return null;
        }

        $base = fn () => DB::table('sucursales')->where('empresa_id', $contexto->empresaId)->where('activa', true);
        $exactas = $base()->where('nombre', $texto)->limit(2)->get(['id', 'nombre']);

        if ($exactas->count() === 1) {
            return $exactas->first();
        }

        $parecidas = $base()->where('nombre', 'like', $this->patron($texto))->orderBy('nombre')->limit(self::MAX_COINCIDENCIAS + 1)->get(['id', 'nombre']);

        return match (true) {
            $parecidas->count() === 1 => $parecidas->first(),
            $parecidas->isEmpty() => throw new ArgumentoInvalido("No hay una sucursal activa llamada «{$texto}». Sucursales: ".$base()->orderBy('nombre')->limit(20)->pluck('nombre')->implode(', ').'.'),
            default => throw new ArgumentoInvalido("«{$texto}» coincide con varias sucursales: ".$parecidas->take(self::MAX_COINCIDENCIAS)->pluck('nombre')->implode(', ').'. Pregunta cuál.'),
        };
    }

    /**
     * Producto de la empresa por nombre completo, nombre o código (SKU); null si no se pidió ninguno.
     *
     * @param  callable(Builder): mixed|null  $filtro  restricción extra sobre el alias p (p. ej. solo con receta)
     */
    protected function producto(ContextoAgente $contexto, mixed $texto, string $clase, string $que, ?callable $filtro = null): ?stdClass
    {
        $buscado = is_string($texto) ? trim($texto) : '';

        if ($buscado === '') {
            return null;
        }

        $nombre = Producto::sqlNombreCompleto('p', 'u');
        $base = fn () => DB::table('productos as p')
            ->join('unidades_medida as u', 'u.id', '=', 'p.unidad_medida_id')
            ->where('p.empresa_id', $contexto->empresaId)
            ->where('p.clase', $clase)
            ->when($filtro !== null, fn (Builder $q) => $filtro($q))
            ->select(['p.id', 'p.tipo_venta', 'u.admite_decimales', 'u.abreviatura', DB::raw("{$nombre} AS nombre")]);
        $exactos = $base()->where(fn (Builder $q) => $q->where('p.sku', $buscado)->orWhereRaw("{$nombre} = ?", [$buscado]))->limit(2)->get();

        if ($exactos->count() === 1) {
            return $exactos->first();
        }

        $patron = $this->patron($buscado);
        $parecidos = $exactos->count() > 1 ? $exactos : $base()
            ->where(fn (Builder $q) => $q->where('p.nombre', 'like', $patron)->orWhere('p.sku', 'like', $patron)->orWhereRaw("{$nombre} LIKE ?", [$patron]))
            ->orderBy('p.nombre')->limit(self::MAX_COINCIDENCIAS + 1)->get();

        return match (true) {
            $parecidos->count() === 1 => $parecidos->first(),
            $parecidos->isEmpty() => throw new ArgumentoInvalido("No encontré {$que} «{$buscado}» en la empresa. Pide el nombre exacto o consulta la lista con query_business_data."),
            default => throw new ArgumentoInvalido("«{$buscado}» coincide con varios: ".$parecidos->take(self::MAX_COINCIDENCIAS)->pluck('nombre')->implode(', ').'. Pregunta cuál.'),
        };
    }

    /**
     * Stock por sucursal y producto (saldo y vencido) de las sucursales y productos indicados: dos consultas.
     *
     * @param  list<int>  $sucursales
     * @param  list<int>  $productos
     * @return array<int, array<int, array{existencia: string, vencido: string, utilizable: string}>> sucursal_id => producto_id => stock
     */
    protected function stockUtilizable(ContextoAgente $contexto, array $sucursales, array $productos): array
    {
        if ($sucursales === [] || $productos === []) {
            return [];
        }

        $saldos = DB::table('stock_niveles')
            ->where('empresa_id', $contexto->empresaId)
            ->whereIn('sucursal_id', $sucursales)
            ->whereIn('producto_id', $productos)
            ->get(['sucursal_id', 'producto_id', 'cantidad']);
        $vencidas = $contexto->lotes() ? DB::table('stock_lotes as sl')
            ->join('lotes as l', fn (JoinClause $j) => $j->on('l.id', '=', 'sl.lote_id')->on('l.empresa_id', '=', 'sl.empresa_id'))
            ->join('productos as p', fn (JoinClause $j) => $j->on('p.id', '=', 'sl.producto_id')->on('p.empresa_id', '=', 'sl.empresa_id'))
            ->where('sl.empresa_id', $contexto->empresaId)
            ->whereIn('sl.sucursal_id', $sucursales)
            ->whereIn('sl.producto_id', $productos)
            ->where('p.controla_vencimiento', true)
            ->where('sl.cantidad', '>', 0)
            ->whereNotNull('l.vencimiento')
            ->where('l.vencimiento', '<', $contexto->fechaReferencia->format('Y-m-d'))
            ->groupBy('sl.sucursal_id', 'sl.producto_id')
            ->get(['sl.sucursal_id', 'sl.producto_id', DB::raw('SUM(sl.cantidad) AS cantidad')]) : collect();
        $vencido = [];

        foreach ($vencidas as $fila) {
            $vencido[(int) $fila->sucursal_id][(int) $fila->producto_id] = (string) $fila->cantidad;
        }

        $stock = [];

        foreach ($sucursales as $sucursalId) {
            foreach ($productos as $productoId) {
                $stock[$sucursalId][$productoId] = $this->disponible('0', $vencido[$sucursalId][$productoId] ?? '0');
            }
        }

        foreach ($saldos as $fila) {
            $sucursalId = (int) $fila->sucursal_id;
            $productoId = (int) $fila->producto_id;
            $stock[$sucursalId][$productoId] = $this->disponible((string) $fila->cantidad, $vencido[$sucursalId][$productoId] ?? '0');
        }

        return $stock;
    }

    /**
     * Máximo producible con una receta y el material disponible (UC-19): por línea, disponible × base ÷ cantidad (las
     * líneas enteras con el disponible entero, porque se escalan hacia arriba: M5); el menor manda. Se redondea hacia
     * abajo a la regla del producto y se comprueba con Escalado, así nunca deja faltantes.
     *
     * @param  list<array{producto_id: int, cantidad: string, entera: bool}>  $lineas
     * @param  array<int, string>  $disponible  producto_id => cantidad
     * @return array{maximo: string, limita: int|null, por_linea: array<int, string>}
     */
    protected function maximo(array $lineas, string $base, bool $productoEntero, array $disponible): array
    {
        $maximo = null;
        $limita = null;
        $porLinea = [];

        foreach ($lineas as $linea) {
            if (Decimal::comparar($linea['cantidad'], '0') <= 0) {
                continue;
            }

            $tengo = $disponible[$linea['producto_id']] ?? '0';
            $tengo = $linea['entera'] ? Decimal::dividir($tengo, '1', 0) : $tengo;
            $alcanza = Decimal::dividir(Decimal::multiplicar($tengo, $base, 6), $linea['cantidad'], 3);
            $porLinea[$linea['producto_id']] = $alcanza;

            if ($maximo === null || Decimal::comparar($alcanza, $maximo) < 0) {
                $maximo = $alcanza;
                $limita = $linea['producto_id'];
            }
        }

        if ($maximo === null) {
            return ['maximo' => '0', 'limita' => null, 'por_linea' => []];
        }

        $paso = $productoEntero ? '1' : '0.001';
        $maximo = $productoEntero ? Decimal::dividir($maximo, '1', 0) : Decimal::redondear($maximo, 3);

        // El redondeo de las líneas (3 decimales o entero hacia arriba) puede pedir apenas más: se baja un paso.
        for ($intento = 0; $intento < 5 && Decimal::comparar($maximo, '0') > 0 && ! $this->alcanza($lineas, $base, $maximo, $disponible); $intento++) {
            $maximo = Decimal::restar($maximo, $paso);
        }

        return ['maximo' => Decimal::comparar($maximo, '0') > 0 ? $maximo : '0', 'limita' => $limita, 'por_linea' => $porLinea];
    }

    /**
     * @param  list<array{producto_id: int, cantidad: string, entera: bool}>  $lineas
     * @param  array<int, string>  $disponible
     */
    protected function alcanza(array $lineas, string $base, string $cantidad, array $disponible): bool
    {
        foreach ($lineas as $linea) {
            if (Decimal::comparar(Escalado::teorica($cantidad, $base, $linea['cantidad'], $linea['entera']), $disponible[$linea['producto_id']] ?? '0') > 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array{existencia: string, vencido: string, utilizable: string}
     */
    private function disponible(string $existencia, string $vencido): array
    {
        $utilizable = Decimal::restar($existencia, $vencido);

        return ['existencia' => $existencia, 'vencido' => $vencido, 'utilizable' => Decimal::comparar($utilizable, '0') < 0 ? '0.000' : $utilizable];
    }

    private function patron(string $texto): string
    {
        return '%'.addcslashes($texto, '\\%_').'%';
    }
}
