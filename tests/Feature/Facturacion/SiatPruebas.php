<?php

namespace Tests\Feature\Facturacion;

use App\Domain\Facturacion\PreparacionFacturacion;
use App\Models\Empresa;
use App\Models\SiatConfiguracion;
use App\Models\SiatPuntoVenta;
use App\Models\SiatSucursal;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use MoziStudios\Siat\Configuracion\ConfiguracionSistema;
use MoziStudios\Siat\Sincronizacion\Catalogo;
use MoziStudios\Siat\Soap\Transporte;
use MoziStudios\Siat\Testing\TransporteFalso;

/**
 * Datos y respuestas del SIN para las pruebas de facturación (fase 2). Sin red: el transporte del
 * paquete se reemplaza por uno falso que solo registra el sufijo del token.
 */
final class SiatPruebas
{
    public const SISTEMA = 'ABC123DEF456';

    public static function configurar(): TransporteFalso
    {
        config([
            'siat.codigo_sistema' => self::SISTEMA,
            'siat.ambiente' => 2,
            'siat.url_base' => ConfiguracionSistema::URL_PILOTO,
        ]);

        $transporte = new TransporteFalso;
        app()->instance(Transporte::class, $transporte);

        return $transporte;
    }

    /**
     * Token con la forma de un JWT del SIN (la firma no se verifica en MoziStock).
     */
    public static function token(int $nit, string $sistema = self::SISTEMA, ?int $vence = null, string $firma = 'firmaSECRETA_q7Zx'): string
    {
        $parte = fn (array $datos): string => rtrim(strtr(base64_encode((string) json_encode($datos)), '+/', '-_'), '=');

        return $parte(['typ' => 'JWT', 'alg' => 'HS512']).'.'.$parte([
            'sub' => 'usuario',
            'codigoSistema' => $sistema,
            'nitDelegado' => $nit,
            'nit' => base64_encode((string) gzencode((string) $nit)),
            'exp' => $vence ?? now()->addYear()->getTimestamp(),
            'iat' => now()->getTimestamp(),
        ]).'.'.$firma;
    }

    /**
     * Empresa habilitada con NIT y token cargados, su sucursal 0 en el SIN y el punto de venta 0.
     * Se crea antes de autenticar a nadie (el modelo exige el contexto de la empresa propia).
     *
     * @param  array<string, mixed>  $empresa
     * @return array{empresa: Empresa, admin: User, sucursal: Sucursal, siatSucursal: SiatSucursal, punto: SiatPuntoVenta, configuracion: SiatConfiguracion}
     */
    public static function empresaFiscal(int $nit = 1023456029, array $empresa = [], string $firma = 'firmaSECRETA_q7Zx'): array
    {
        [$registro, $admin] = empresaConAdministrador($empresa + ['siat_habilitado' => true]);
        $sucursal = Sucursal::factory()->create(['empresa_id' => $registro->id, 'nombre' => 'Casa Matriz']);

        $configuracion = new SiatConfiguracion;
        $configuracion->empresa_id = $registro->id;
        $configuracion->nit = $nit;
        $configuracion->razon_social = 'EMPRESA '.$nit;
        $configuracion->token = self::token($nit, firma: $firma);
        $configuracion->token_sufijo = substr($firma, -4);
        $configuracion->token_vence_en = Carbon::now()->addYear();
        $configuracion->save();

        $siatSucursal = new SiatSucursal;
        $siatSucursal->empresa_id = $registro->id;
        $siatSucursal->sucursal_id = $sucursal->id;
        $siatSucursal->codigo = 0;
        $siatSucursal->municipio = 'Santa Cruz';
        $siatSucursal->save();

        $punto = new SiatPuntoVenta;
        $punto->empresa_id = $registro->id;
        $punto->siat_sucursal_id = $siatSucursal->id;
        $punto->codigo = 0;
        $punto->nombre = 'Casa Matriz (sin punto de venta)';
        $punto->activo = true;
        $punto->save();

        return ['empresa' => $registro, 'admin' => $admin, 'sucursal' => $sucursal, 'siatSucursal' => $siatSucursal, 'punto' => $punto, 'configuracion' => $configuracion];
    }

    /**
     * @return array<string, mixed>
     */
    public static function cuis(string $codigo = 'C2FD8E5A', string $vence = '2027-10-24T10:00:00.000-04:00', bool $transaccion = true): array
    {
        return ['RespuestaCuis' => ['codigo' => $codigo, 'fechaVigencia' => $vence, 'transaccion' => $transaccion]];
    }

    /**
     * @return array<string, mixed>
     */
    public static function cufd(string $control = 'A19E23EF34124CD', ?string $vence = null): array
    {
        return ['RespuestaCufd' => [
            'codigo' => 'BQUE+QytqQUDBKVUFOSVRPQkxVRFZNVFVJBMDAwMDAwM'.$control,
            'codigoControl' => $control,
            'codigoInterno' => 'C',
            'direccion' => 'AV. BANZER 123',
            'fechaVigencia' => $vence ?? now('America/La_Paz')->addDay()->format('Y-m-d\TH:i:s.vP'),
            'transaccion' => true,
        ]];
    }

    /**
     * Respuesta de sincronización con la forma que el SIN usa para cada catálogo.
     *
     * @param  list<array{0: string, 1: string}>  $items  código y descripción
     * @return array<string, mixed>
     */
    public static function catalogo(Catalogo $catalogo, array $items): array
    {
        $filas = array_map(fn (array $item): array => [
            'codigoClasificador' => $item[0], 'descripcion' => $item[1],
            'codigoCaeb' => $item[0], 'tipoActividad' => 'P',
            'codigoDocumentoSector' => $item[0], 'tipoDocumentoSector' => $item[1],
            'codigoActividad' => $item[0], 'descripcionLeyenda' => $item[1],
            'codigoProducto' => $item[0], 'descripcionProducto' => $item[1],
        ], $items);

        return [$catalogo->envoltorioRespuesta() => ['transaccion' => true, $catalogo->propiedadLista() => $filas]];
    }

    /**
     * Respuestas para la sincronización que el asistente hace al validar un token: CUIS de casa matriz
     * (la empresa aún no registró sucursales en el SIN) y un valor por catálogo.
     */
    public static function responderSincronizacion(TransporteFalso $transporte): void
    {
        $transporte->responder(self::cuis());

        foreach (Catalogo::cases() as $catalogo) {
            $transporte->responder(self::catalogo($catalogo, [['10', 'VALOR '.$catalogo->name]]));
        }
    }

    /**
     * Marca como sincronizados los catálogos sin los que no se factura (sin llamar al SIN) y deja la
     * actividad y el producto SIN dados en esos catálogos.
     */
    public static function catalogosSincronizados(int $empresaId, string $actividad = '620100', string $producto = '83141'): void
    {
        foreach (PreparacionFacturacion::CATALOGOS_NECESARIOS as $catalogo) {
            DB::table('siat_sincronizaciones')->insert([
                'empresa_id' => $empresaId, 'catalogo' => $catalogo->name, 'cantidad' => 1,
                'sincronizado_en' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        DB::table('siat_catalogos')->insert([
            ['empresa_id' => $empresaId, 'catalogo' => Catalogo::Actividades->name, 'codigo' => $actividad, 'descripcion' => 'ACTIVIDAD '.$actividad, 'atributos' => null],
            ['empresa_id' => $empresaId, 'catalogo' => Catalogo::ProductosServicios->name, 'codigo' => $producto, 'descripcion' => 'PRODUCTO '.$producto, 'atributos' => null],
        ]);
    }
}
