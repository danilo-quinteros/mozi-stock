<?php

namespace App\Domain\Tenancy;

use App\Domain\Facturacion\PreparacionFacturacion;
use App\Models\Empresa;
use App\Models\MovimientoStock;
use App\Models\Producto;
use App\Models\Sucursal;
use App\Models\User;
use App\Models\Venta;

/**
 * Guía de configuración inicial que ve el administrador de una empresa nueva en el Inicio
 * (onboarding.html). Cada paso se marca solo, según los datos reales guardados. Cuando todos
 * están listos se anota la fecha en la empresa y la guía no vuelve a mostrarse.
 *
 * La parte SIAT no tiene datos propios: agrupa los puntos de PreparacionFacturacion, la misma
 * revisión que usa Facturación.
 */
final class GuiaConfiguracion
{
    /**
     * Pasos SIAT del onboarding => puntos de PreparacionFacturacion que agrupa. El token va primero:
     * al guardarlo se sincronizan los catálogos, y sin ellos no se puede elegir la actividad.
     */
    private const PASOS_SIAT = [
        ['titulo' => 'Asociación y token', 'detalle' => 'Conecta con el SIN', 'puntos' => ['token', 'catalogos']],
        ['titulo' => 'Datos fiscales', 'detalle' => 'Identifica a tu empresa', 'puntos' => ['datos', 'actividad']],
        ['titulo' => 'Sucursal y punto de venta', 'detalle' => 'Prepara la emisión', 'puntos' => ['puntos', 'cuis', 'cufd']],
        ['titulo' => 'Verificar conexión', 'detalle' => 'Comprueba la configuración', 'puntos' => ['habilitada', 'servidor']],
    ];

    /** Administrador de una empresa que aún no termina la guía. Una consulta por clave primaria. */
    public static function pendiente(User $usuario): bool
    {
        return $usuario->esAdministrador()
            && Empresa::query()->whereKey($usuario->empresa_id)->whereNull('guia_completada_en')->exists();
    }

    /**
     * Null si el usuario no es administrador o la empresa ya terminó la guía.
     *
     * @return array{pasos: list<array{clave: string, titulo: string, detalle: string, icono: string, accion: string, url: string|null, listo: bool}>, completados: int, siat: array{pasos: list<array{titulo: string, detalle: string, listo: bool, puntos: list<array{clave: string, punto: string, detalle: string, listo: bool, automatico: bool}>}>, listo: bool, actual: int}|null}|null
     */
    public static function para(User $usuario): ?array
    {
        if (! self::pendiente($usuario)) {
            return null;
        }

        $empresa = Empresa::query()->findOrFail((int) $usuario->empresa_id);
        $id = $empresa->id;
        $siat = $empresa->siat_habilitado ? self::siat($empresa) : null;

        // Sin URL: el paso se resuelve en un popup o en la tarjeta SIAT del mismo Inicio.
        $pasos = [
            self::paso('empresa', __('Datos de tu empresa'), __('Nombre y teléfono del negocio'), 'building', __('Completar'),
                null, filled($empresa->nombre) && filled($empresa->telefono)),
            self::paso('sucursal', __('Configura tu sucursal'), __('Agrega la dirección de «Casa Matriz» o registra otra sucursal'), 'store', __('Configurar'),
                null, Sucursal::query()->where('empresa_id', $id)->where('activa', true)->whereNotNull('direccion')->exists()),
            self::paso('productos', __('Agrega tus primeros productos'), __('Registra artículos y precios'), 'box', __('Comenzar'),
                route('productos.crear'), Producto::query()->where('empresa_id', $id)->exists()),
            self::paso('inventario', __('Carga tu inventario inicial'), __('Registra una compra o una entrada de stock por sucursal'), 'truck', __('Comenzar'),
                route('inventario.ajuste'), MovimientoStock::query()->where('empresa_id', $id)->where('cantidad', '>', 0)->exists()),
        ];

        if ($siat !== null) {
            $pasos[] = self::paso('siat', __('Configura la facturación SIAT'), __('Token, datos fiscales, sucursal y punto de venta del SIN'), 'receipt', __('Configurar'),
                null, $siat['listo']);
        }

        $pasos[] = self::paso('venta', __('Registra tu primera venta'), __('Comienza a controlar las ventas y cobros de tu negocio'), 'cart', __('Comenzar'),
            route('pos'), Venta::query()->where('empresa_id', $id)->exists());

        $completados = count(array_filter($pasos, fn (array $paso): bool => $paso['listo']));

        if ($completados === count($pasos)) {
            $empresa->forceFill(['guia_completada_en' => now()])->save();

            return null;
        }

        return ['pasos' => $pasos, 'completados' => $completados, 'siat' => $siat];
    }

    /**
     * @return array{pasos: list<array{titulo: string, detalle: string, listo: bool, puntos: list<array{clave: string, punto: string, detalle: string, listo: bool, automatico: bool}>}>, listo: bool, actual: int}
     */
    private static function siat(Empresa $empresa): array
    {
        $revision = [];
        foreach (PreparacionFacturacion::revisar($empresa) as $punto) {
            $revision[$punto['clave']] = $punto;
        }

        $pasos = [];
        foreach (self::PASOS_SIAT as $paso) {
            $puntos = array_map(fn (string $clave): array => $revision[$clave], $paso['puntos']);
            $pasos[] = [
                'titulo' => __($paso['titulo']),
                'detalle' => __($paso['detalle']),
                // CUIS y CUFD se obtienen solos al facturar: no detienen la guía.
                'listo' => collect($puntos)->every(fn (array $punto): bool => $punto['listo'] || $punto['automatico']),
                'puntos' => $puntos,
            ];
        }

        // «Verificar conexión» queda listo solo cuando los pasos anteriores también lo están.
        $pasos[3]['listo'] = collect($pasos)->every(fn (array $paso): bool => $paso['listo']);
        $actual = collect($pasos)->search(fn (array $paso): bool => ! $paso['listo']);

        return ['pasos' => $pasos, 'listo' => $pasos[3]['listo'], 'actual' => is_int($actual) ? $actual : 3];
    }

    /**
     * @return array{clave: string, titulo: string, detalle: string, icono: string, accion: string, url: string|null, listo: bool}
     */
    private static function paso(string $clave, string $titulo, string $detalle, string $icono, string $accion, ?string $url, bool $listo): array
    {
        return compact('clave', 'titulo', 'detalle', 'icono', 'accion', 'url', 'listo');
    }
}
