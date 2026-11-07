<?php

namespace App\Domain\AgenteMozi\Herramientas;

use App\Domain\AgenteMozi\ContextoAgente;
use App\Domain\AgenteMozi\Datos\Campo;
use App\Domain\AgenteMozi\Datos\CatalogoDatos;
use App\Domain\AgenteMozi\Datos\ConsultaDatos;
use App\Domain\AgenteMozi\DTOs\DatasetPropuesto;
use Illuminate\Validation\Rule;

/**
 * query_business_data (§ 5.1): consulta genérica de solo lectura para preguntas sin herramienta
 * propia. El modelo lee la pregunta y rellena los argumentos; Laravel valida contra el catálogo y
 * ejecuta. La descripción y las fuentes ofrecidas dependen del rol (el vendedor no ve fuentes ni
 * campos financieros), pero la autoridad al ejecutar es siempre ContextoAgente.
 */
final class ConsultarDatos extends HerramientaNegocio
{
    public function __construct(private readonly bool $administrador = false) {}

    public function nombre(): string
    {
        return 'query_business_data';
    }

    public function descripcion(): string
    {
        $catalogo = CatalogoDatos::paraDescribir($this->administrador);
        $nombres = fn (array $campos) => implode(', ', array_map(fn (Campo $c) => $c->tipo === 'string' ? $c->nombre : "{$c->nombre}:{$c->tipo}", $campos));
        $fuentes = array_map(function (string $nombre) use ($catalogo, $nombres): string {
            $fuente = $catalogo->fuente($nombre);

            return "- {$nombre}: {$fuente->descripcion} Campos: {$nombres($fuente->visibles($this->administrador, false))}. Medidas: {$nombres($fuente->visibles($this->administrador, true))}.";
        }, $catalogo->fuentesPermitidas());

        return 'Consulta genérica de solo lectura sobre los datos reales de la empresa, para preguntas sin una herramienta específica: buscar, listar, contar, sumar, agrupar, rankings o tendencias. '
            .'Modos: listar (fields; vacío = campos principales); agregar (group_by con hasta 2 campos y measures con hasta 6; measures sin group_by = una fila de totales, apta para un KPI; group_by sin measures cuenta filas). '
            .'Filtros (hasta 8): field, op y value como texto (números con punto decimal, fechas AAAA-MM-DD) o values para in. El texto se compara sin distinguir mayúsculas ni tildes. Una medida solo se filtra en una agregación. '
            .'date_from y date_to (AAAA-MM-DD, días de La Paz) filtran la fecha principal de la fuente; los campos fecha, semana, mes, anio, dia_semana y hora sirven para agrupar. '
            .'Productos, clientes y proveedores inactivos se excluyen salvo que filtres por estado. Devuelve hasta limit filas (100 por defecto, máximo 500) y total_matches: si truncated es true viste un recorte y debes decirlo. '
            .($this->administrador ? 'Consulta toda la empresa; filtra por sucursal si la pregunta lo pide. ' : 'Solo ves la sucursal del usuario. ')
            ."Fuentes:\n".implode("\n", $fuentes);
    }

    public function parametros(): array
    {
        $texto = ['type' => 'string'];

        return [
            'type' => 'object',
            'properties' => [
                'source' => ['type' => 'string', 'enum' => CatalogoDatos::paraDescribir($this->administrador)->fuentesPermitidas(), 'description' => 'Fuente de datos.'],
                'fields' => ['type' => 'array', 'items' => $texto, 'description' => 'Modo listar: campos a devolver (hasta 20).'],
                'group_by' => ['type' => 'array', 'items' => $texto, 'description' => 'Modo agregar: hasta 2 campos para agrupar.'],
                'measures' => ['type' => 'array', 'items' => $texto, 'description' => 'Modo agregar: hasta 6 medidas de la fuente.'],
                'filters' => ['type' => 'array', 'description' => 'Hasta 8 filtros, todos se cumplen a la vez.', 'items' => [
                    'type' => 'object',
                    'properties' => [
                        'field' => ['type' => 'string', 'description' => 'Campo o medida de la fuente.'],
                        'op' => ['type' => 'string', 'enum' => ConsultaDatos::OPERADORES],
                        'value' => ['type' => 'string', 'description' => 'Valor para comparar (no se usa con in, is_empty ni is_not_empty).'],
                        'values' => ['type' => 'array', 'items' => $texto, 'description' => 'Solo para in: hasta 50 valores.'],
                    ],
                    'required' => ['field', 'op'],
                    'additionalProperties' => false,
                ]],
                'date_from' => ['type' => 'string', 'description' => 'Primer día (AAAA-MM-DD) de la fecha principal.'],
                'date_to' => ['type' => 'string', 'description' => 'Último día (AAAA-MM-DD), incluido.'],
                'sort' => ['type' => 'object', 'properties' => [
                    'field' => ['type' => 'string'],
                    'direction' => ['type' => 'string', 'enum' => ['asc', 'desc']],
                ], 'required' => ['field', 'direction'], 'additionalProperties' => false],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 500, 'description' => 'Máximo de filas. Por defecto 100.'],
            ],
            'required' => ['source'],
            'additionalProperties' => false,
        ];
    }

    public function reglas(): array
    {
        return [
            'source' => ['required', 'string', Rule::in(CatalogoDatos::FUENTES)],
            'fields' => ['sometimes', 'array', 'max:20'],
            'fields.*' => ['string', 'max:40'],
            'group_by' => ['sometimes', 'array', 'max:2'],
            'group_by.*' => ['string', 'max:40'],
            'measures' => ['sometimes', 'array', 'max:6'],
            'measures.*' => ['string', 'max:40'],
            'filters' => ['sometimes', 'array', 'max:8'],
            'filters.*' => ['array'],
            'filters.*.field' => ['required', 'string', 'max:40'],
            'filters.*.op' => ['required', 'string', Rule::in(ConsultaDatos::OPERADORES)],
            'filters.*.value' => ['nullable'],
            'filters.*.values' => ['sometimes', 'array', 'max:50'],
            'filters.*.values.*' => ['nullable'],
            'date_from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'date_to' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'sort' => ['sometimes', 'array'],
            'sort.field' => ['required_with:sort', 'string', 'max:40'],
            'sort.direction' => ['required_with:sort', Rule::in(['asc', 'desc'])],
            'limit' => ['sometimes', 'integer', 'between:1,500'],
        ];
    }

    public function permiso(): ?string
    {
        return null;
    }

    public function avance(): string
    {
        return __('Consultando los datos del negocio…');
    }

    public function ejecutar(ContextoAgente $contexto, array $argumentos): array
    {
        $catalogo = new CatalogoDatos($contexto);
        $resultado = (new ConsultaDatos($catalogo))->ejecutar($argumentos);
        $fuente = $resultado['fuente'];
        $filas = count($resultado['filas']);
        $total = $resultado['total'];
        $metodo = implode('; ', array_filter([
            "Consulta de {$fuente->nombre}",
            $resultado['agrupado'] === [] ? null : 'agrupada por '.implode(', ', $resultado['agrupado']),
            $resultado['filtros'] === [] ? null : 'filtros: '.implode(' · ', $resultado['filtros']),
        ]));

        return [[
            'dataset' => new DatasetPropuesto($fuente->nombre, $resultado['columnas'], $resultado['filas'],
                array_filter(['period' => $resultado['periodo']]) + $this->meta($contexto, $fuente->nombre, null, metodo: $metodo, extra: [
                    'total_matches' => $total,
                    'truncated' => $total > $filas,
                ])),
            'descripcion' => "{$fuente->descripcion} {$metodo}. Filas devueltas: {$filas} de {$total} coincidencias".($total > $filas ? ' (recortado: no son todas).' : '.'),
        ]];
    }
}
