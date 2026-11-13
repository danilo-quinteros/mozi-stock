<?php

namespace App\Domain\Suscripciones\Consultas;

use App\Domain\Reportes\Periodo;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Comprobantes de pago SaaS de todas las empresas (revisión manual de MoziStudios). Los
 * pendientes primero. Pantalla y CSV comparten la consulta (T23).
 */
final class ListadoComprobantes
{
    public const ESTADOS = ['pendiente', 'aprobado', 'rechazado'];

    public static function consulta(string $buscar, string $estado): Builder
    {
        $termino = trim($buscar);
        $patron = '%'.addcslashes($termino, '\\%_').'%';

        return DB::table('pagos_saas')
            ->join('empresas', 'empresas.id', '=', 'pagos_saas.empresa_id')
            ->join('users as remitentes', 'remitentes.id', '=', 'pagos_saas.enviado_por')
            ->leftJoin('users as revisores', 'revisores.id', '=', 'pagos_saas.revisado_por')
            ->select('pagos_saas.id', 'pagos_saas.empresa_id', 'empresas.nombre as empresa', 'pagos_saas.created_at', 'pagos_saas.tipo', 'pagos_saas.meses',
                'pagos_saas.sucursales_extra', 'pagos_saas.paquetes_usuarios',
                'pagos_saas.precio_mensual', 'pagos_saas.importe', 'pagos_saas.estado', 'pagos_saas.archivo_mime', 'pagos_saas.nota',
                'pagos_saas.revisado_en', 'pagos_saas.motivo_rechazo', 'pagos_saas.vencimiento_anterior', 'pagos_saas.vencimiento_nuevo',
                'remitentes.name as remitente', 'revisores.name as revisor')
            ->when($termino !== '', fn (Builder $q) => $q->where('empresas.nombre', 'like', $patron))
            ->when(in_array($estado, self::ESTADOS, true), fn (Builder $q) => $q->where('pagos_saas.estado', $estado))
            ->orderByRaw("pagos_saas.estado = 'pendiente' desc")
            ->orderByDesc('pagos_saas.id');
    }

    /**
     * @return array{pendientes: int, aprobados: int, rechazados: int}
     */
    public static function conteosDelMes(): array
    {
        [$desde, $hasta] = Periodo::mesActual()->limitesUtc();
        $fila = DB::table('pagos_saas')
            ->selectRaw("coalesce(sum(case when estado = 'pendiente' then 1 else 0 end), 0) as pendientes")
            ->selectRaw("coalesce(sum(case when estado = 'aprobado' and revisado_en >= ? and revisado_en < ? then 1 else 0 end), 0) as aprobados", [$desde, $hasta])
            ->selectRaw("coalesce(sum(case when estado = 'rechazado' and revisado_en >= ? and revisado_en < ? then 1 else 0 end), 0) as rechazados", [$desde, $hasta])
            ->first();

        return [
            'pendientes' => (int) ($fila->pendientes ?? 0),
            'aprobados' => (int) ($fila->aprobados ?? 0),
            'rechazados' => (int) ($fila->rechazados ?? 0),
        ];
    }

    public static function avisos(string $buscar): Builder
    {
        $termino = trim($buscar);
        $patron = '%'.addcslashes($termino, '\\%_').'%';

        return DB::table('avisos_suscripcion')
            ->join('empresas', 'empresas.id', '=', 'avisos_suscripcion.empresa_id')
            ->select('avisos_suscripcion.id', 'empresas.nombre as empresa', 'avisos_suscripcion.vencimiento', 'avisos_suscripcion.evento',
                'avisos_suscripcion.fecha_programada', 'avisos_suscripcion.estado', 'avisos_suscripcion.destinatarios',
                'avisos_suscripcion.enviado_en', 'avisos_suscripcion.created_at')
            ->when($termino !== '', fn (Builder $q) => $q->where('empresas.nombre', 'like', $patron))
            ->orderByDesc('avisos_suscripcion.id');
    }
}
