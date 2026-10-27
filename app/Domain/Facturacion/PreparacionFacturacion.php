<?php

namespace App\Domain\Facturacion;

use App\Models\Empresa;
use App\Models\SiatConfiguracion;
use App\Models\SiatCufd;
use App\Models\SiatCuis;
use App\Models\SiatPuntoVenta;
use App\Models\SiatSincronizacion;
use App\Models\SiatSucursal;
use MoziStudios\Siat\Configuracion\ConfiguracionSistema;
use MoziStudios\Siat\Excepciones\DatosInvalidos;
use MoziStudios\Siat\Sincronizacion\Catalogo;

/**
 * Lista de lo que falta para que una empresa emita facturas, en el orden en que se resuelve. Solo lee:
 * no consulta al SIN. Los puntos «automáticos» (CUIS y CUFD) se obtienen solos al facturar.
 */
final class PreparacionFacturacion
{
    /** Catálogos sin los que la factura queda bloqueada o no se puede anular. */
    public const CATALOGOS_NECESARIOS = [
        Catalogo::TiposDocumentoIdentidad, Catalogo::TiposMetodoPago, Catalogo::Actividades,
        Catalogo::ProductosServicios, Catalogo::UnidadesMedida, Catalogo::LeyendasFactura, Catalogo::MotivosAnulacion,
    ];

    /**
     * @return list<array{clave: string, punto: string, listo: bool, detalle: string, automatico: bool}>
     */
    public static function revisar(Empresa $empresa): array
    {
        $configuracion = DeEmpresa::consulta(SiatConfiguracion::class, $empresa->id)->first();

        try {
            app(ConfiguracionSistema::class);
            $servidor = true;
        } catch (DatosInvalidos) {
            $servidor = false;
        }

        $puntosActivos = DeEmpresa::consulta(SiatPuntoVenta::class, $empresa->id)->where('activo', true)->count();
        $sincronizados = DeEmpresa::consulta(SiatSincronizacion::class, $empresa->id)
            ->whereNotNull('sincronizado_en')->pluck('catalogo')->all();
        $faltantes = array_values(array_filter(self::CATALOGOS_NECESARIOS,
            fn (Catalogo $catalogo): bool => ! in_array($catalogo->name, $sincronizados, true)));
        $productoListo = $configuracion?->actividad_economica !== null && $configuracion->producto_sin !== null;

        return [
            self::punto('habilitada', __('Facturación habilitada por MoziStudios'), $empresa->siat_habilitado,
                $empresa->siat_habilitado ? __('Habilitada.') : __('MoziStudios la habilita desde el panel de empresas.')),
            self::punto('servidor', __('Configuración del servidor'), $servidor,
                $servidor ? __('Código de sistema y ambiente correctos.') : __('Falta el código de sistema o el ambiente no corresponde a la URL del SIN.')),
            self::punto('datos', __('NIT y razón social'), $configuracion?->nit !== null && filled($configuracion->razon_social),
                $configuracion?->nit !== null ? __('NIT :nit.', ['nit' => $configuracion->nit]) : __('Complétalos en «Datos fiscales».')),
            self::punto('token', __('Token delegado vigente'), $configuracion !== null && $configuracion->tieneToken() && ! $configuracion->tokenVencido(),
                $configuracion?->tieneToken() ? ($configuracion->tokenVencido() ? __('Vencido: carga uno nuevo.') : __('Termina en …:sufijo.', ['sufijo' => $configuracion->token_sufijo]))
                    : __('Cárgalo en «Datos fiscales».')),
            self::punto('puntos', __('Sucursal registrada y punto de venta activo'), $puntosActivos > 0 && DeEmpresa::consulta(SiatSucursal::class, $empresa->id)->exists(),
                $puntosActivos > 0 ? trans_choice(':count punto de venta activo.|:count puntos de venta activos.', $puntosActivos) : __('Regístralos en «Sucursales y puntos de venta».')),
            self::punto('catalogos', __('Catálogos del SIN sincronizados'), $faltantes === [],
                $faltantes === [] ? __('Al día.') : __('Faltan: :catalogos.', ['catalogos' => implode(', ', array_map(fn (Catalogo $c): string => __($c->etiqueta()), $faltantes))])),
            self::punto('actividad', __('Actividad y producto SIN'), $productoListo,
                $productoListo ? __('Actividad :actividad · producto :producto.', ['actividad' => $configuracion->actividad_economica, 'producto' => $configuracion->producto_sin])
                    : __('Elígelos en «Datos fiscales» después de sincronizar los catálogos.')),
            self::punto('cuis', __('CUIS vigente'), DeEmpresa::consulta(SiatCuis::class, $empresa->id)->where('vence_en', '>', now())->exists(),
                __('Se obtiene en «Sucursales y puntos de venta» o al facturar.'), true),
            self::punto('cufd', __('CUFD vigente'), DeEmpresa::consulta(SiatCufd::class, $empresa->id)->where('vence_en', '>', now())->exists(),
                __('Se renueva solo cada hora y al facturar.'), true),
        ];
    }

    /**
     * @return array{clave: string, punto: string, listo: bool, detalle: string, automatico: bool}
     */
    private static function punto(string $clave, string $punto, bool $listo, string $detalle, bool $automatico = false): array
    {
        return ['clave' => $clave, 'punto' => $punto, 'listo' => $listo, 'detalle' => $detalle, 'automatico' => $automatico];
    }
}
