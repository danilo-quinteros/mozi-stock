<?php

namespace MoziStudios\Siat\Sincronizacion;

use MoziStudios\Siat\Respuestas\ItemCatalogo;
use MoziStudios\Siat\Respuestas\Lector;

/**
 * Catálogos del servicio FacturacionSincronizacion V3. El valor es la operación SOAP; cada caso sabe
 * en qué envoltorio y lista llega su respuesta y cómo normalizar cada fila.
 */
enum Catalogo: string
{
    case Actividades = 'sincronizarActividades';
    case ActividadesDocumentoSector = 'sincronizarListaActividadesDocumentoSector';
    case LeyendasFactura = 'sincronizarListaLeyendasFactura';
    case MensajesServicios = 'sincronizarListaMensajesServicios';
    case ProductosServicios = 'sincronizarListaProductosServicios';
    case EventosSignificativos = 'sincronizarParametricaEventosSignificativos';
    case MotivosAnulacion = 'sincronizarParametricaMotivoAnulacion';
    case PaisesOrigen = 'sincronizarParametricaPaisOrigen';
    case TiposDocumentoIdentidad = 'sincronizarParametricaTipoDocumentoIdentidad';
    case TiposDocumentoSector = 'sincronizarParametricaTipoDocumentoSector';
    case TiposEmision = 'sincronizarParametricaTipoEmision';
    case TiposHabitacion = 'sincronizarParametricaTipoHabitacion';
    case TiposMetodoPago = 'sincronizarParametricaTipoMetodoPago';
    case TiposMoneda = 'sincronizarParametricaTipoMoneda';
    case TiposPuntoVenta = 'sincronizarParametricaTipoPuntoVenta';
    case TiposFactura = 'sincronizarParametricaTiposFactura';
    case UnidadesMedida = 'sincronizarParametricaUnidadMedida';

    /** Nombre para mostrar (en español; quien integra puede traducirlo). */
    public function etiqueta(): string
    {
        return match ($this) {
            self::Actividades => 'Actividades económicas',
            self::ActividadesDocumentoSector => 'Actividades por documento sector',
            self::LeyendasFactura => 'Leyendas de factura',
            self::MensajesServicios => 'Mensajes de servicios',
            self::ProductosServicios => 'Productos y servicios',
            self::EventosSignificativos => 'Eventos significativos',
            self::MotivosAnulacion => 'Motivos de anulación',
            self::PaisesOrigen => 'Países',
            self::TiposDocumentoIdentidad => 'Tipos de documento de identidad',
            self::TiposDocumentoSector => 'Tipos de documento sector',
            self::TiposEmision => 'Tipos de emisión',
            self::TiposHabitacion => 'Tipos de habitación',
            self::TiposMetodoPago => 'Métodos de pago',
            self::TiposMoneda => 'Monedas',
            self::TiposPuntoVenta => 'Tipos de punto de venta',
            self::TiposFactura => 'Tipos de factura',
            self::UnidadesMedida => 'Unidades de medida',
        };
    }

    public function envoltorioRespuesta(): string
    {
        return match ($this) {
            self::Actividades => 'RespuestaListaActividades',
            self::ActividadesDocumentoSector => 'RespuestaListaActividadesDocumentoSector',
            self::LeyendasFactura => 'RespuestaListaParametricasLeyendas',
            self::ProductosServicios => 'RespuestaListaProductos',
            default => 'RespuestaListaParametricas',
        };
    }

    public function propiedadLista(): string
    {
        return match ($this) {
            self::Actividades => 'listaActividades',
            self::ActividadesDocumentoSector => 'listaActividadesDocumentoSector',
            self::LeyendasFactura => 'listaLeyendas',
            default => 'listaCodigos',
        };
    }

    public function item(object $fila): ItemCatalogo
    {
        $texto = static fn (string $campo): string => Lector::texto($fila, $campo) ?? '';

        return match ($this) {
            self::Actividades => new ItemCatalogo($texto('codigoCaeb'), $texto('descripcion'), [
                'tipoActividad' => $texto('tipoActividad'),
            ]),
            self::ActividadesDocumentoSector => new ItemCatalogo($texto('codigoDocumentoSector'), $texto('tipoDocumentoSector'), [
                'codigoActividad' => $texto('codigoActividad'),
            ]),
            // Las leyendas no tienen código propio: se agrupan por actividad económica.
            self::LeyendasFactura => new ItemCatalogo($texto('codigoActividad'), $texto('descripcionLeyenda')),
            self::ProductosServicios => new ItemCatalogo($texto('codigoProducto'), $texto('descripcionProducto'), [
                'codigoActividad' => $texto('codigoActividad'),
                'nandina' => $texto('nandina'),
            ]),
            default => new ItemCatalogo($texto('codigoClasificador'), $texto('descripcion')),
        };
    }

    public function esPorEmpresa(): bool
    {
        return in_array($this, [
            self::Actividades,
            self::ProductosServicios,
            self::LeyendasFactura,
            self::ActividadesDocumentoSector,
        ], true);
    }

    /**
     * Catálogos autorizados específicamente según el NIT de la empresa.
     *
     * @return list<self>
     */
    public static function porEmpresa(): array
    {
        return [
            self::Actividades,
            self::ProductosServicios,
            self::LeyendasFactura,
            self::ActividadesDocumentoSector,
        ];
    }

    /**
     * Catálogos paramétricos del SIN comunes a nivel nacional.
     *
     * @return list<self>
     */
    public static function globales(): array
    {
        return [
            self::UnidadesMedida,
            self::TiposMetodoPago,
            self::TiposDocumentoIdentidad,
            self::TiposMoneda,
            self::MotivosAnulacion,
            self::TiposPuntoVenta,
            self::TiposFactura,
            self::TiposEmision,
            self::EventosSignificativos,
            self::PaisesOrigen,
            self::TiposDocumentoSector,
            self::TiposHabitacion,
            self::MensajesServicios,
        ];
    }
}
