<?php

namespace App\Livewire\Concerns;

use App\Domain\Tenancy\CapacidadesEmpresa;
use App\Enums\Capacidad;
use App\Enums\Permiso;

/**
 * Casillas del catálogo de permisos (formulario de rol y permisos extra del usuario, ESPECIFICACION_PERMISOS.md § 8).
 * Solo prepara la pantalla: las acciones GuardarRol y GuardarUsuarioEmpresa vuelven a validar cada clave y a
 * agregar los requeridos en el servidor.
 */
trait EligePermisos
{
    /**
     * Permisos que otro marcado necesita (§ 4.4), con el nombre de quien los necesita: se muestran marcados y fijos.
     *
     * @param  list<Permiso>  $marcados
     * @return array<string, string> clave => etiqueta del permiso que lo requiere
     */
    protected static function requeridosPor(array $marcados): array
    {
        $requeridos = [];

        foreach ($marcados as $permiso) {
            foreach (Permiso::conRequeridos([$permiso]) as $requerido) {
                if ($requerido !== $permiso) {
                    $requeridos[$requerido->value] ??= $permiso->etiqueta();
                }
            }
        }

        return $requeridos;
    }

    /**
     * Claves marcadas con sus requeridos, en el orden del catálogo. Las desconocidas se descartan.
     *
     * @param  array<mixed>  $claves
     * @return list<Permiso>
     */
    protected static function conSusRequeridos(array $claves): array
    {
        return Permiso::conRequeridos(Permiso::desdeClaves($claves));
    }

    /**
     * Catálogo agrupado sin los permisos cuya capacidad está apagada (§ 4.5). Siguen guardados, pero ocultos.
     *
     * @return array<string, list<Permiso>>
     */
    protected static function gruposVisibles(int $empresaId): array
    {
        $grupos = [];

        foreach (Permiso::porGrupo() as $grupo => $permisos) {
            $visibles = array_values(array_filter($permisos, fn (Permiso $p): bool => self::permisoVisible($empresaId, $p)));

            if ($visibles !== []) {
                $grupos[$grupo] = $visibles;
            }
        }

        return $grupos;
    }

    /**
     * Permisos ocultos con las funciones que los habilitan, para la nota «Requiere la función X».
     *
     * @return array<string, string> etiqueta del permiso => funciones que lo habilitan
     */
    protected static function ocultosPorCapacidad(int $empresaId): array
    {
        $ocultos = [];

        foreach (Permiso::cases() as $permiso) {
            if (! self::permisoVisible($empresaId, $permiso)) {
                $ocultos[$permiso->etiqueta()] = implode(' '.__('o').' ', array_map(fn (Capacidad $c): string => $c->etiqueta(), $permiso->capacidades()));
            }
        }

        return $ocultos;
    }

    private static function permisoVisible(int $empresaId, Permiso $permiso): bool
    {
        $capacidades = app(CapacidadesEmpresa::class);

        return $permiso->capacidades() === []
            || array_filter($permiso->capacidades(), fn (Capacidad $c): bool => $capacidades->tiene($empresaId, $c)) !== [];
    }
}
