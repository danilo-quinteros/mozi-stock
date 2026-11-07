<?php

namespace App\Domain\AgenteMozi\Demo;

use App\Domain\AgenteMozi\ContextoAgente;
use App\Domain\AgenteMozi\DTOs\ArtefactoPropuesto;
use App\Domain\AgenteMozi\DTOs\DatasetPropuesto;
use App\Domain\AgenteMozi\DTOs\RespuestaAgente;
use App\Enums\TipoArtefacto;
use App\Support\Decimal;

/**
 * Escenarios sintéticos del modo demostración. Todas las cifras son inventadas para revisar la
 * interfaz y llevan quality «demo» y synthetic = true: nunca deben leerse como datos de la empresa.
 * Los totales se calculan con Decimal (sin float) para que los indicadores cuadren con sus tablas.
 */
final class EscenariosDemo
{
    public const PROVEEDOR = 'demo';

    public function __construct(private readonly ContextoAgente $contexto) {}

    /** UC-01: cobertura de stock y riesgo de agotamiento en 7 días. */
    public function stock(bool $sinVistaPersonalizada = false): RespuestaAgente
    {
        $periodo = $this->periodo(0, 7);
        $detalle = $this->detalleCobertura($periodo);
        $resumen = new DatasetPropuesto('resumen', [
            $this->col('en_riesgo', __('Productos en riesgo'), 'integer'),
            $this->col('cobertura_minima', __('Cobertura mínima'), 'number'),
            $this->col('sin_datos', __('Sin historial suficiente'), 'integer'),
        ], [['en_riesgo' => 4, 'cobertura_minima' => '1.5', 'sin_datos' => 2]], $this->meta($periodo));
        $metodo = ['method' => __('Cobertura = stock utilizable ÷ demanda diaria estimada (media de 28 días sin contar días agotados).')];

        $texto = [
            __('Encontré **:n productos** con riesgo de agotarse en los próximos 7 días. El de menor cobertura es **Aceite 900 ml** (Sucursal Centro), con unos **1,5 días** estimados.', ['n' => 4]),
            __('Dos productos no tienen historial suficiente, así que no les asigno una fecha: «sin datos» no significa «sin demanda».'),
            __('En el Lienzo tienes los indicadores, el gráfico de cobertura y la tabla de detalle.'),
        ];

        if ($sinVistaPersonalizada) {
            array_unshift($texto, __('Las vistas personalizadas están desactivadas en este servidor; te muestro la versión con gráficos nativos.'));
        }

        return $this->respuesta($texto, [
            new ArtefactoPropuesto(TipoArtefacto::Kpi, __('Productos en riesgo (7 días)'), 'resumen',
                ['field' => 'en_riesgo', 'format' => 'integer', 'unit' => __('productos'), 'tone' => 'danger']),
            new ArtefactoPropuesto(TipoArtefacto::Kpi, __('Cobertura mínima'), 'resumen',
                ['field' => 'cobertura_minima', 'format' => 'decimal_1', 'unit' => __('días'), 'hint' => __('Aceite 900 ml · Sucursal Centro'), 'tone' => 'warning'], $metodo),
            new ArtefactoPropuesto(TipoArtefacto::Kpi, __('Sin historial suficiente'), 'resumen',
                ['field' => 'sin_datos', 'format' => 'integer', 'unit' => __('productos'), 'hint' => __('No reciben fecha estimada')]),
            new ArtefactoPropuesto(TipoArtefacto::Grafico, __('Cobertura estimada de stock'), 'cobertura', [
                'chart_type' => 'bar', 'orientation' => 'horizontal', 'sort' => 'asc', 'x_field' => 'producto',
                'series' => [['field' => 'cobertura_dias', 'label' => __('Cobertura (días)')]],
                'value_format' => 'decimal_1', 'value_unit' => __('días'),
            ], $metodo),
            new ArtefactoPropuesto(TipoArtefacto::Tabla, __('Detalle por producto y sucursal'), 'cobertura', [
                'columns' => [
                    ['field' => 'producto', 'label' => __('Producto'), 'format' => 'text'],
                    ['field' => 'sucursal', 'label' => __('Sucursal'), 'format' => 'text'],
                    ['field' => 'disponible', 'label' => __('Disponible'), 'format' => 'number'],
                    ['field' => 'demanda_diaria', 'label' => __('Demanda diaria'), 'format' => 'decimal_1'],
                    ['field' => 'cobertura_dias', 'label' => __('Cobertura (días)'), 'format' => 'decimal_1'],
                    ['field' => 'fecha_probable', 'label' => __('Agotamiento probable'), 'format' => 'date'],
                    ['field' => 'estado', 'label' => __('Estado'), 'format' => 'badge'],
                ],
                'default_sort' => ['field' => 'cobertura_dias', 'direction' => 'asc'],
            ], $metodo),
            new ArtefactoPropuesto(TipoArtefacto::Hallazgo, __('Cómo leer este análisis'), null, [
                'tone' => 'warning',
                'text' => __('La cobertura es una estimación con incertidumbre, no una fecha segura.'),
                'bullets' => [
                    __('Yogur frutilla 1 L está agotado: sus días sin stock no se cuentan como demanda cero.'),
                    __('Café molido 250 g tiene menos de 14 días de historial: se marca «sin datos».'),
                    __('Solo se considera stock disponible; no se suponen reposiciones futuras.'),
                ],
            ], $this->meta($periodo)),
        ], [$resumen, $detalle]);
    }

    /** UC-10: ventas confirmadas frente a dinero realmente cobrado. */
    public function ventasYCobros(): RespuestaAgente
    {
        $periodo = $this->periodo(-6, 0);
        $importes = [
            ['1840.00', '1520.00'], ['2210.50', '1985.50'], ['1675.00', '1702.00'], ['2980.00', '2140.00'],
            ['2430.00', '2275.00'], ['3120.00', '2605.50'], ['1355.00', '1210.00'],
        ];
        $filas = [];
        $ventas = '0';
        $cobros = '0';

        foreach ($importes as $i => [$venta, $cobro]) {
            $filas[] = ['fecha' => $this->dia($i - 6), 'ventas' => $venta, 'cobros' => $cobro];
            $ventas = Decimal::sumar($ventas, $venta, Decimal::ESCALA_DINERO);
            $cobros = Decimal::sumar($cobros, $cobro, Decimal::ESCALA_DINERO);
        }

        // Coherencia del ejemplo: lo vendido = cobrado de esas ventas + saldo pendiente; el resto de los
        // cobros son abonos de créditos anteriores, contados el día en que se recibieron.
        $pendiente = '2894.50';
        $abonosAnteriores = Decimal::restar($cobros, Decimal::restar($ventas, $pendiente, Decimal::ESCALA_DINERO), Decimal::ESCALA_DINERO);

        $resumen = new DatasetPropuesto('resumen', [
            $this->col('ventas', __('Ventas confirmadas'), 'money'),
            $this->col('cobros', __('Cobros reales'), 'money'),
            $this->col('pendiente', __('Crédito pendiente'), 'money'),
            $this->col('cantidad', __('Ventas'), 'integer'),
        ], [['ventas' => $ventas, 'cobros' => $cobros, 'pendiente' => $pendiente, 'cantidad' => 42]], $this->meta($periodo));
        $serie = new DatasetPropuesto('serie', [
            $this->col('fecha', __('Fecha'), 'date'),
            $this->col('ventas', __('Ventas confirmadas'), 'money'),
            $this->col('cobros', __('Cobros reales'), 'money'),
        ], $filas, $this->meta($periodo));

        return $this->respuesta([
            __('En los últimos 7 días se confirmaron ventas por **:ventas** y se cobraron realmente **:cobros**.', ['ventas' => Decimal::bs($ventas), 'cobros' => Decimal::bs($cobros)]),
            __('No son la misma cifra: :pendiente de lo vendido sigue a crédito y :abonos de los cobros son abonos de ventas anteriores.', ['pendiente' => Decimal::bs($pendiente), 'abonos' => Decimal::bs($abonosAnteriores)]),
        ], [
            new ArtefactoPropuesto(TipoArtefacto::Kpi, __('Ventas confirmadas'), 'resumen', ['field' => 'ventas', 'format' => 'bs', 'hint' => __(':n ventas · no es dinero cobrado', ['n' => 42])]),
            new ArtefactoPropuesto(TipoArtefacto::Kpi, __('Cobros reales'), 'resumen', ['field' => 'cobros', 'format' => 'bs', 'hint' => __('Por fecha efectiva de cobro'), 'tone' => 'good']),
            new ArtefactoPropuesto(TipoArtefacto::Kpi, __('Crédito pendiente'), 'resumen', ['field' => 'pendiente', 'format' => 'bs', 'hint' => __('De las ventas del período'), 'tone' => 'warning']),
            new ArtefactoPropuesto(TipoArtefacto::Grafico, __('Ventas y cobros por día'), 'serie', [
                'chart_type' => 'line', 'x_field' => 'fecha', 'value_format' => 'bs',
                'series' => [['field' => 'ventas', 'label' => __('Ventas confirmadas')], ['field' => 'cobros', 'label' => __('Cobros reales')]],
            ]),
            new ArtefactoPropuesto(TipoArtefacto::Hallazgo, __('Ventas ≠ dinero cobrado'), null, [
                'tone' => 'info',
                'text' => __('Una venta a crédito de Bs 200 con cuota inicial de Bs 50 suma Bs 200 a ventas el día de la venta, pero solo Bs 50 a cobros. Cada abono se cuenta el día en que se recibe.'),
                'bullets' => [__('Las cotizaciones no se incluyen en ventas ni en cobros.'), __('Los cobros salen solo del libro de caja, sin duplicar pagos.')],
            ], $this->meta($periodo)),
        ], [$resumen, $serie]);
    }

    /** UC-05: volumen frente a margen bruto. */
    public function rentabilidad(): RespuestaAgente
    {
        $periodo = $this->periodo(-29, 0);
        $productos = [
            ['Arroz 5 kg', 320, '15040.00', '13.0'], ['Aceite 900 ml', 410, '7790.00', '11.0'],
            ['Leche entera 1 L', 520, '3640.00', '14.0'], ['Detergente 1 kg', 145, '3770.00', '30.0'],
            ['Café molido 250 g', 88, '3168.00', '38.0'], ['Fideo 400 g', 260, '1820.00', '21.0'],
            ['Azúcar 2 kg', 190, '2850.00', '8.0'], ['Yogur frutilla 1 L', 130, '1950.00', '27.0'],
        ];
        $filas = array_map(fn (array $p) => [
            'producto' => $p[0], 'unidades' => $p[1], 'venta_neta' => $p[2], 'margen_pct' => $p[3],
            'utilidad_bruta' => Decimal::redondear(bcdiv(bcmul($p[2], $p[3], 4), '100', 4)),
        ], $productos);

        $datos = new DatasetPropuesto('productos', [
            $this->col('producto', __('Producto'), 'string'),
            $this->col('unidades', __('Unidades'), 'integer'),
            $this->col('venta_neta', __('Venta neta'), 'money'),
            $this->col('utilidad_bruta', __('Utilidad bruta'), 'money'),
            $this->col('margen_pct', __('Margen bruto'), 'percent'),
        ], $filas, $this->meta($periodo, ['method' => __('Utilidad bruta = venta neta − costo atribuible (demo).')]));

        return $this->respuesta([
            __('**Arroz 5 kg** es el que más vende, pero deja solo un **13 %** de margen bruto. **Café molido 250 g** vende poco y deja un **38 %**.'),
            __('Ojo: utilidad bruta no es utilidad neta, porque no descuenta gastos. Con datos reales, este análisis necesita primero un método de costeo.'),
        ], [
            new ArtefactoPropuesto(TipoArtefacto::Grafico, __('Volumen frente a margen bruto'), 'productos', [
                'chart_type' => 'scatter', 'x_field' => 'unidades', 'label_field' => 'producto', 'value_format' => 'percent',
                'series' => [['field' => 'margen_pct', 'label' => __('Margen bruto (%)')]], 'x_label' => __('Unidades vendidas'), 'y_label' => __('Margen bruto (%)'),
            ]),
            new ArtefactoPropuesto(TipoArtefacto::Grafico, __('Utilidad bruta por producto'), 'productos', [
                'chart_type' => 'bar', 'x_field' => 'producto', 'sort' => 'desc', 'value_format' => 'bs',
                'series' => [['field' => 'utilidad_bruta', 'label' => __('Utilidad bruta')]],
            ]),
            new ArtefactoPropuesto(TipoArtefacto::Tabla, __('Rentabilidad por producto'), 'productos', [
                'columns' => [
                    ['field' => 'producto', 'label' => __('Producto'), 'format' => 'text'],
                    ['field' => 'unidades', 'label' => __('Unidades'), 'format' => 'integer'],
                    ['field' => 'venta_neta', 'label' => __('Venta neta'), 'format' => 'bs'],
                    ['field' => 'utilidad_bruta', 'label' => __('Utilidad bruta'), 'format' => 'bs'],
                    ['field' => 'margen_pct', 'label' => __('Margen bruto'), 'format' => 'percent'],
                ],
                'default_sort' => ['field' => 'utilidad_bruta', 'direction' => 'desc'],
            ]),
            new ArtefactoPropuesto(TipoArtefacto::Hallazgo, __('Antes de usar datos reales'), null, [
                'tone' => 'warning',
                'text' => __('Hoy las ventas de MoziStock no guardan su costo. Hasta definir el método de costeo, la utilidad real se mostrará como «no calculable» en lugar de estimarla.'),
                'bullets' => [__('Solo los roles con permiso financiero verán costos y márgenes.')],
            ], $this->meta($periodo)),
        ], [$datos]);
    }

    /** UC-03: capital inmovilizado en productos sin ventas. */
    public function capital(): RespuestaAgente
    {
        $periodo = $this->periodo(-89, 0);
        $productos = [
            ['Cera para pisos 1 L', 'Limpieza', 124, '48', '1920.00', -124],
            ['Jugo durazno 2 L', 'Bebidas', 97, '62', '1488.00', -97],
            ['Lentejas 1 kg', 'Abarrotes', 88, '70', '1260.00', -88],
            ['Gaseosa lima 3 L', 'Bebidas', 75, '40', '992.00', -75],
            ['Limpiavidrios 500 ml', 'Limpieza', 66, '50', '1200.00', -66],
            ['Quinua 1 kg', 'Abarrotes', 63, '35', '630.00', null],
            ['Queso crema 200 g', 'Lácteos', 61, '46', '1150.00', -61],
        ];
        $filas = [];
        $categorias = [];
        $total = '0';

        foreach ($productos as [$nombre, $categoria, $dias, $unidades, $valor, $ultima]) {
            $filas[] = ['producto' => $nombre, 'categoria' => __($categoria), 'dias_sin_venta' => $dias, 'unidades' => $unidades,
                'valor_costo' => $valor, 'ultima_venta' => $ultima === null ? null : $this->dia($ultima)];
            $categorias[$categoria] = Decimal::sumar($categorias[$categoria] ?? '0', $valor, Decimal::ESCALA_DINERO);
            $total = Decimal::sumar($total, $valor, Decimal::ESCALA_DINERO);
        }

        $meta = $this->meta($periodo, ['method' => __('Valor a costo de compra de las existencias sin ventas en 60 días o más (demo).')]);
        $resumen = new DatasetPropuesto('resumen', [
            $this->col('valor', __('Capital sin movimiento'), 'money'),
            $this->col('productos', __('Productos'), 'integer'),
        ], [['valor' => $total, 'productos' => count($filas)]], $meta);
        $porCategoria = new DatasetPropuesto('categorias', [
            $this->col('categoria', __('Categoría'), 'string'),
            $this->col('valor', __('Valor a costo'), 'money'),
        ], array_map(fn (string $c, string $v) => ['categoria' => __($c), 'valor' => $v], array_keys($categorias), $categorias), $meta);
        $detalle = new DatasetPropuesto('detalle', [
            $this->col('producto', __('Producto'), 'string'),
            $this->col('categoria', __('Categoría'), 'string'),
            $this->col('dias_sin_venta', __('Días sin venta'), 'integer'),
            $this->col('unidades', __('Unidades'), 'number'),
            $this->col('valor_costo', __('Valor a costo'), 'money'),
            $this->col('ultima_venta', __('Última venta'), 'date', true),
        ], $filas, $meta);

        return $this->respuesta([
            __('Tienes **:total** a costo en **:n productos** que no se venden hace 60 días o más. La mayor parte está en **Limpieza** y **Bebidas**.', ['total' => Decimal::bs($total), 'n' => count($filas)]),
            __('Las causas que propongo son hipótesis, no hechos: revisa precio, visibilidad y estacionalidad antes de decidir.'),
        ], [
            new ArtefactoPropuesto(TipoArtefacto::Kpi, __('Capital sin movimiento'), 'resumen', ['field' => 'valor', 'format' => 'bs', 'hint' => __('Valor a costo, no ingreso esperado'), 'tone' => 'warning']),
            new ArtefactoPropuesto(TipoArtefacto::Kpi, __('Productos estancados'), 'resumen', ['field' => 'productos', 'format' => 'integer', 'unit' => __('productos'), 'hint' => __('60 días o más sin ventas')]),
            new ArtefactoPropuesto(TipoArtefacto::Grafico, __('Capital inmovilizado por categoría'), 'categorias', [
                'chart_type' => 'donut', 'x_field' => 'categoria', 'sort' => 'desc', 'value_format' => 'bs',
                'series' => [['field' => 'valor', 'label' => __('Valor a costo')]],
            ]),
            new ArtefactoPropuesto(TipoArtefacto::Tabla, __('Productos sin movimiento'), 'detalle', [
                'columns' => [
                    ['field' => 'producto', 'label' => __('Producto'), 'format' => 'text'],
                    ['field' => 'categoria', 'label' => __('Categoría'), 'format' => 'badge'],
                    ['field' => 'dias_sin_venta', 'label' => __('Días sin venta'), 'format' => 'integer'],
                    ['field' => 'unidades', 'label' => __('Unidades'), 'format' => 'number'],
                    ['field' => 'valor_costo', 'label' => __('Valor a costo'), 'format' => 'bs'],
                    ['field' => 'ultima_venta', 'label' => __('Última venta'), 'format' => 'date'],
                ],
                'default_sort' => ['field' => 'valor_costo', 'direction' => 'desc'],
            ]),
            new ArtefactoPropuesto(TipoArtefacto::Hallazgo, __('Posibles acciones'), null, [
                'tone' => 'info',
                'text' => __('Hipótesis a validar: bebidas frías con menos rotación en invierno, precio por encima de la competencia o poca exhibición.'),
                'bullets' => [
                    __('Promoción sin bajar del margen mínimo.'),
                    __('Detener nuevas compras hasta reducir existencias.'),
                    __('Traspasar a una sucursal con más demanda.'),
                    __('Quinua 1 kg nunca se vendió: revisa si está visible en el punto de venta.'),
                ],
            ], $meta),
        ], [$resumen, $porCategoria, $detalle]);
    }

    /** UC-04: pronóstico semanal con banda de incertidumbre. */
    public function pronostico(): RespuestaAgente
    {
        $reales = ['9850.00', '10240.00', '9710.00', '10980.00', '11320.00', '10870.00', '11650.00', '11940.00'];
        $estimadas = [['12100.00', '11300.00', '12900.00'], ['12280.00', '11250.00', '13310.00'], ['12350.00', '11080.00', '13620.00'], ['12520.00', '11010.00', '14030.00']];
        $lunes = $this->contexto->fechaReferencia->startOfWeek();
        $filas = [];

        foreach ($reales as $i => $real) {
            // La última semana real también abre la estimación, para que ambas líneas se unan.
            $ultima = $i === count($reales) - 1;
            $filas[] = ['semana' => $lunes->subWeeks(8 - $i)->format('Y-m-d'), 'real' => $real,
                'estimado' => $ultima ? $real : null, 'inferior' => $ultima ? $real : null, 'superior' => $ultima ? $real : null];
        }

        $total = '0';
        $inferior = '0';
        $superior = '0';

        foreach ($estimadas as $i => [$estimado, $bajo, $alto]) {
            $filas[] = ['semana' => $lunes->addWeeks($i)->format('Y-m-d'), 'real' => null, 'estimado' => $estimado, 'inferior' => $bajo, 'superior' => $alto];
            $total = Decimal::sumar($total, $estimado, Decimal::ESCALA_DINERO);
            $inferior = Decimal::sumar($inferior, $bajo, Decimal::ESCALA_DINERO);
            $superior = Decimal::sumar($superior, $alto, Decimal::ESCALA_DINERO);
        }

        $anterior = array_reduce(array_slice($reales, -4), fn (string $suma, string $v) => Decimal::sumar($suma, $v, Decimal::ESCALA_DINERO), '0');
        $variacion = Decimal::redondear(bcmul(bcsub(bcdiv($total, $anterior, 6), '1', 6), '100', 4), 1);
        $periodo = ['from' => $lunes->subWeeks(8)->format('Y-m-d'), 'to' => $lunes->addWeeks(4)->subDay()->format('Y-m-d')];
        $meta = $this->meta($periodo, ['method' => __('Media móvil de 4 semanas con tendencia suave (demo). Rango orientativo: suma de las bandas semanales.')]);

        $resumen = new DatasetPropuesto('resumen', [
            $this->col('estimado', __('Estimación 4 semanas'), 'money'),
            $this->col('variacion', __('Variación'), 'percent'),
        ], [['estimado' => $total, 'variacion' => $variacion]], $meta);
        $serie = new DatasetPropuesto('serie', [
            $this->col('semana', __('Semana'), 'date'),
            $this->col('real', __('Ventas reales'), 'money', true),
            $this->col('estimado', __('Estimación'), 'money', true),
            $this->col('inferior', __('Límite inferior'), 'money', true),
            $this->col('superior', __('Límite superior'), 'money', true),
        ], $filas, $meta);

        return $this->respuesta([
            __('Para las próximas 4 semanas estimo ventas de unos **:total**, con un rango orientativo entre :bajo y :alto.', ['total' => Decimal::bs($total), 'bajo' => Decimal::bs($inferior), 'alto' => Decimal::bs($superior)]),
            __('Es una estimación, no una cifra garantizada: la banda se ensancha cuanto más lejos está la semana.'),
        ], [
            new ArtefactoPropuesto(TipoArtefacto::Kpi, __('Estimación próximas 4 semanas'), 'resumen', ['field' => 'estimado', 'format' => 'bs',
                'hint' => __('Rango :bajo – :alto', ['bajo' => Decimal::bs($inferior), 'alto' => Decimal::bs($superior)])]),
            new ArtefactoPropuesto(TipoArtefacto::Kpi, __('Frente a las últimas 4 semanas'), 'resumen', ['field' => 'variacion', 'format' => 'percent', 'hint' => __('Variación estimada'), 'tone' => 'good']),
            new ArtefactoPropuesto(TipoArtefacto::Grafico, __('Ventas semanales: real y estimación'), 'serie', [
                'chart_type' => 'line', 'x_field' => 'semana', 'value_format' => 'bs',
                'series' => [['field' => 'real', 'label' => __('Ventas reales')], ['field' => 'estimado', 'label' => __('Estimación'), 'style' => 'dashed']],
                'band' => ['lower_field' => 'inferior', 'upper_field' => 'superior', 'label' => __('Rango probable')],
            ]),
            new ArtefactoPropuesto(TipoArtefacto::Hallazgo, __('Nota metodológica'), null, [
                'tone' => 'info',
                'text' => __('Línea base de demostración. En la Fase 5 se comparará con suavizado exponencial mediante backtesting temporal y solo se usará un modelo más complejo si mejora a esta línea base.'),
                'bullets' => [__('Se usan ventas confirmadas menos devoluciones; nunca cobros ni cotizaciones.')],
            ], $meta),
        ], [$resumen, $serie]);
    }

    /** Vista personalizada sandbox_web (código de demostración propio) con su tabla verificable. */
    public function visualizacion(): RespuestaAgente
    {
        $periodo = $this->periodo(0, 7);

        return $this->respuesta([
            __('Preparé una vista personalizada de la cobertura de stock. Corre en un entorno aislado: no puede leer tu sesión, llamar a MoziStock ni conectarse a internet.'),
            __('Si algo falla, el Lienzo te muestra la tabla verificable con los mismos datos.'),
        ], [
            new ArtefactoPropuesto(TipoArtefacto::SandboxWeb, __('Panel interactivo de cobertura'), 'cobertura', VistaCoberturaDemo::spec(), ['origin' => 'demo']),
            new ArtefactoPropuesto(TipoArtefacto::Hallazgo, __('Sobre las vistas personalizadas'), null, [
                'tone' => 'info',
                'text' => __('Las vistas personalizadas se ejecutan en un iframe aislado con una política de seguridad estricta. Reciben solo los datos autorizados de esta respuesta y no pueden cambiar cifras, fuentes ni períodos.'),
            ], $this->meta($periodo)),
        ], [$this->detalleCobertura($periodo)]);
    }

    /** Pregunta sin escenario de demostración: solo texto, el Lienzo conserva lo anterior. */
    public function ayuda(): RespuestaAgente
    {
        return $this->respuesta([
            __('Todavía estoy en modo demostración y no consulto los datos de tu empresa. Puedo mostrarte cómo se verán estos análisis:'),
            '- '.__('Productos que se quedarán sin stock')."\n- ".__('Ventas frente a cobros reales')."\n- ".__('Margen por producto')
                ."\n- ".__('Capital sin movimiento')."\n- ".__('Pronóstico de ventas')."\n- ".__('Una vista personalizada'),
            __('Elige una sugerencia o pregúntame por alguno de estos temas.'),
        ], [], []);
    }

    /**
     * @param  array{from: string, to: string}  $periodo
     */
    private function detalleCobertura(array $periodo): DatasetPropuesto
    {
        $productos = [
            ['Aceite 900 ml', 'Centro', '3', '2.0', '1.5', 1, 'Crítico'],
            ['Arroz 5 kg', 'Norte', '6', '2.0', '3.0', 3, 'Crítico'],
            ['Leche entera 1 L', 'Centro', '14', '3.5', '4.0', 4, 'Atención'],
            ['Detergente 1 kg', 'Sur', '9', '1.5', '6.0', 6, 'Atención'],
            ['Azúcar 2 kg', 'Norte', '25', '1.8', '13.9', 13, 'Estable'],
            ['Fideo 400 g', 'Centro', '40', '2.2', '18.2', 18, 'Estable'],
            ['Café molido 250 g', 'Sur', '7', null, null, null, 'Sin datos'],
            ['Yogur frutilla 1 L', 'Centro', '0', null, null, null, 'Sin datos'],
        ];

        return new DatasetPropuesto('cobertura', [
            $this->col('producto', __('Producto'), 'string'),
            $this->col('sucursal', __('Sucursal'), 'string'),
            $this->col('disponible', __('Disponible'), 'number'),
            $this->col('demanda_diaria', __('Demanda diaria'), 'number', true),
            $this->col('cobertura_dias', __('Cobertura (días)'), 'number', true),
            $this->col('fecha_probable', __('Agotamiento probable'), 'date', true),
            $this->col('estado', __('Estado'), 'string'),
        ], array_values(array_map(fn (array $p) => [
            'producto' => $p[0], 'sucursal' => $p[1], 'disponible' => $p[2], 'demanda_diaria' => $p[3], 'cobertura_dias' => $p[4],
            'fecha_probable' => $p[5] === null ? null : $this->dia($p[5]), 'estado' => __($p[6]),
        ], $productos)), $this->meta($periodo, ['method' => __('Cobertura = stock utilizable ÷ demanda diaria estimada (media de 28 días sin contar días agotados).')]));
    }

    /**
     * @param  list<string>  $parrafos
     * @param  list<ArtefactoPropuesto>  $artefactos
     * @param  list<DatasetPropuesto>  $datasets
     */
    private function respuesta(array $parrafos, array $artefactos, array $datasets): RespuestaAgente
    {
        array_unshift($parrafos, __('**Modo demostración** · Las cifras son sintéticas y no provienen de tu empresa.'));

        return new RespuestaAgente(implode("\n\n", $parrafos), $artefactos, $datasets, self::PROVEEDOR);
    }

    /**
     * @param  array{from: string, to: string}  $periodo
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function meta(array $periodo, array $extra = []): array
    {
        return $extra + [
            'quality' => 'demo',
            'synthetic' => true,
            'origin' => 'demo',
            'source' => 'demo_sintetico',
            'scope' => __('Demo · todas las sucursales'),
            'period' => $periodo,
            'calculated_at' => $this->contexto->fechaReferencia->toIso8601String(),
            'currency' => 'BOB',
            'timezone' => $this->contexto->zonaHoraria,
        ];
    }

    /**
     * @return array{from: string, to: string}
     */
    private function periodo(int $desde, int $hasta): array
    {
        return ['from' => $this->dia($desde), 'to' => $this->dia($hasta)];
    }

    private function dia(int $desplazamiento): string
    {
        return $this->contexto->fechaReferencia->addDays($desplazamiento)->format('Y-m-d');
    }

    /**
     * @return array{field: string, label: string, type: string, nullable: bool}
     */
    private function col(string $campo, string $etiqueta, string $tipo, bool $vacio = false): array
    {
        return ['field' => $campo, 'label' => $etiqueta, 'type' => $tipo, 'nullable' => $vacio];
    }
}
