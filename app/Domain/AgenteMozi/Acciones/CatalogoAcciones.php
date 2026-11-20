<?php

namespace App\Domain\AgenteMozi\Acciones;

use App\Domain\AgenteMozi\DTOs\AccionPropuesta;
use App\Enums\Permiso;
use App\Models\Compra;
use App\Models\CuentaPagar;
use App\Models\Pedido;
use App\Models\Producto;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Lista cerrada de acciones de Agente Mozi (IA-P3): solo enlaces a pantallas que ya existen, sin datos
 * precargados salvo el cliente de «Registrar cobro» y el filtro de materia prima de Compras. El agente
 * nunca ejecuta nada: la pantalla de destino vuelve a autorizar y valida todo. La URL se arma al mostrar
 * el mensaje con route(), y una acción que el usuario ya no puede usar (cambió su rol o una función de
 * la empresa) no se muestra.
 */
final class CatalogoAcciones
{
    /** Acciones que puede ofrecer una herramienta en cada dataset. */
    public const MAXIMO_OFRECIDAS = 10;

    /** Acciones que se muestran como máximo en un mensaje. */
    public const MAXIMO_POR_MENSAJE = 4;

    /**
     * ruta: nombre de la ruta; parametro: nombre del parámetro de ruta que recibe el id; consulta: parámetro de
     * consulta que recibe el id; fijos: parámetros de consulta constantes.
     *
     * @var array<string, array{ruta: string, icono: string, parametro?: string, consulta?: string, fijos?: array<string, string>}>
     */
    private const TIPOS = [
        'registrar_cobro' => ['ruta' => 'creditos.cobrar', 'icono' => 'cash', 'consulta' => 'cliente'],
        'ver_cuenta_pagar' => ['ruta' => 'cuentas-pagar.ver', 'icono' => 'wallet', 'parametro' => 'cuenta'],
        'abrir_pedido' => ['ruta' => 'pedidos.editar', 'icono' => 'receipt', 'parametro' => 'pedido'],
        'nueva_orden' => ['ruta' => 'produccion.ordenes.crear', 'icono' => 'plus'],
        'abrir_orden' => ['ruta' => 'produccion.ordenes.ver', 'icono' => 'cocina', 'parametro' => 'orden'],
        'ingreso_materia_prima' => ['ruta' => 'compras.crear', 'icono' => 'cart', 'fijos' => ['materia_prima' => '1']],
        'ver_producto' => ['ruta' => 'productos.editar', 'icono' => 'box', 'parametro' => 'producto'],
        'nuevo_envasado' => ['ruta' => 'produccion.envasados.crear', 'icono' => 'plus'],
        'ver_lote' => ['ruta' => 'inventario.lote', 'icono' => 'history', 'parametro' => 'lote'],
    ];

    public static function existe(string $tipo): bool
    {
        return isset(self::TIPOS[$tipo]);
    }

    /** El tipo exige un id (de ruta o de consulta). */
    public static function usaId(string $tipo): bool
    {
        return isset(self::TIPOS[$tipo]['parametro']) || isset(self::TIPOS[$tipo]['consulta']);
    }

    public static function crear(string $tipo, ?int $id, string $etiqueta): AccionPropuesta
    {
        return new AccionPropuesta($tipo, $id, Str::limit(Str::squish($etiqueta), 80));
    }

    /**
     * Acción guardada (JSON de agente_mensajes) o propuesta por una herramienta: null si no cumple la lista cerrada.
     *
     * @param  array<mixed>  $datos
     */
    public static function desde(array $datos): ?AccionPropuesta
    {
        $tipo = $datos['tipo'] ?? null;
        $id = $datos['id'] ?? null;
        $etiqueta = $datos['etiqueta'] ?? null;

        if (! is_string($tipo) || ! self::existe($tipo) || ! is_string($etiqueta) || trim($etiqueta) === '') {
            return null;
        }

        if (self::usaId($tipo) !== (is_int($id) && $id > 0)) {
            return null;
        }

        return self::crear($tipo, self::usaId($tipo) ? $id : null, $etiqueta);
    }

    /** El usuario puede abrir la pantalla de destino (la pantalla igual vuelve a autorizar). */
    public static function permitida(User $usuario, string $tipo): bool
    {
        $puede = Gate::forUser($usuario);

        return match ($tipo) {
            // Como antes: la sugerencia de cobro va con los reportes (finanzas), además del permiso de cobrar.
            'registrar_cobro' => $puede->allows('ver-reportes') && $puede->allows('permiso', Permiso::CreditosCobrar),
            'ver_cuenta_pagar' => $puede->allows('viewAny', CuentaPagar::class),
            'abrir_pedido' => $puede->allows('viewAny', Pedido::class),
            'nueva_orden', 'abrir_orden', 'nuevo_envasado' => $puede->allows('usar-produccion'),
            'ingreso_materia_prima' => $puede->allows('usar-produccion') && $puede->allows('create', Compra::class),
            'ver_producto' => $puede->allows('usar-produccion') && $puede->allows('create', Producto::class),
            'ver_lote' => $puede->allows('analizar-lotes'),
            default => false,
        };
    }

    /**
     * Acciones de un mensaje listas para la vista: solo las válidas y permitidas, con su URL interna.
     *
     * @param  array<mixed>|null  $guardadas
     * @return list<array{url: string, etiqueta: string, icono: string}>
     */
    public static function paraVista(User $usuario, ?array $guardadas): array
    {
        $vista = [];

        foreach (array_slice($guardadas ?? [], 0, self::MAXIMO_POR_MENSAJE) as $datos) {
            $accion = is_array($datos) ? self::desde($datos) : null;

            if ($accion === null || ! self::permitida($usuario, $accion->tipo)) {
                continue;
            }

            $vista[] = ['url' => self::url($accion), 'etiqueta' => $accion->etiqueta, 'icono' => self::TIPOS[$accion->tipo]['icono']];
        }

        return $vista;
    }

    public static function url(AccionPropuesta $accion): string
    {
        $definicion = self::TIPOS[$accion->tipo];
        $parametros = $definicion['fijos'] ?? [];

        if (isset($definicion['parametro'])) {
            $parametros[$definicion['parametro']] = $accion->id;
        }

        if (isset($definicion['consulta'])) {
            $parametros[$definicion['consulta']] = $accion->id;
        }

        // Ruta relativa: el enlace nunca sale del sitio, aunque cambie el dominio.
        return route($definicion['ruta'], $parametros, false);
    }
}
