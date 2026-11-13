<?php

namespace App\Domain\Despachos\Actions;

use App\Domain\Auditoria\Auditor;
use App\Domain\Despachos\HistorialEntrega;
use App\Enums\EstadoEntrega;
use App\Models\EventoEntrega;
use App\Models\User;
use App\Models\Venta;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Quien entregó un pedido de cocina lo devuelve a En cocina durante 10 segundos (ESPECIFICACION_COCINA.md
 * § 5.3), sin nota ni administrador. Es el «Deshacer» del aviso que sigue a «Entregar».
 *
 * - Solo quien marcó la entrega, y solo si la entrega fue desde En cocina: la casilla «Entregado» al vender
 *   no se deshace así (decisión del propietario del 08/11/2026).
 * - Los 10 segundos se cuentan en el servidor desde entregado_en, con la venta bloqueada.
 * - Pasado el plazo, solo el administrador corrige, con nota (CorregirEstadoEntrega).
 *
 * Queda un evento de corrección con la nota automática «Deshecho por el mismo usuario». No mueve stock ni dinero.
 */
final class DeshacerEntrega
{
    public const SEGUNDOS = 10;

    public function __construct(
        private readonly HistorialEntrega $historial,
        private readonly Auditor $auditor,
    ) {}

    public function ejecutar(User $actor, Venta $venta): Venta
    {
        Gate::forUser($actor)->authorize('deshacerEntrega', $venta);

        return DB::transaction(function () use ($actor, $venta): Venta {
            $bloqueada = Venta::query()->whereKey($venta->id)->lockForUpdate()->firstOrFail();
            $ahora = Carbon::now();

            if ($bloqueada->estado_entrega !== EstadoEntrega::Entregado) {
                throw self::rechazo(__('La entrega ya no se puede deshacer: el pedido cambió mientras tanto (ahora es «:estado»).', ['estado' => $bloqueada->estado_entrega->etiqueta()]));
            }

            if ($bloqueada->entregado_por !== $actor->id) {
                throw self::rechazo(__('Solo quien entregó el pedido puede deshacerlo; si hace falta, pide a un administrador que lo corrija.'));
            }

            if (! self::desdeCocina($bloqueada)) {
                throw self::rechazo(__('Solo se deshace la entrega de un pedido que estaba en cocina.'));
            }

            if (! self::dentroDelPlazo($bloqueada, $ahora)) {
                throw self::rechazo(__('Pasaron más de :segundos segundos: pide a un administrador que corrija la entrega.', ['segundos' => self::SEGUNDOS]));
            }

            if (CancelarEntregaDevuelta::devueltaPorCompleto($bloqueada)) {
                throw self::rechazo(__('La venta ya se devolvió por completo: no vuelve a cocina.'));
            }

            $bloqueada->estado_entrega = EstadoEntrega::EnCocina;
            $bloqueada->entregado_por = null;
            $bloqueada->entregado_en = null;
            $bloqueada->entrega_recibio = null;
            $bloqueada->save();

            $nota = __('Deshecho por el mismo usuario');
            $this->historial->registrar($bloqueada, EstadoEntrega::Entregado, EstadoEntrega::EnCocina, $actor, $ahora, $nota, correccion: true);
            $this->auditor->registrar('venta.entrega_deshecha', $bloqueada, $actor, $bloqueada->empresa_id,
                ['estado_entrega' => EstadoEntrega::Entregado->value], ['estado_entrega' => EstadoEntrega::EnCocina->value, 'nota' => $nota]);

            return $bloqueada;
        }, attempts: 3);
    }

    /**
     * Si $actor todavía puede deshacer la entrega de $venta (para mostrar u ocultar el botón). Con la venta sin
     * bloquear: la acción lo vuelve a comprobar. Requiere los eventos cargados o hace una consulta.
     */
    public static function disponible(User $actor, Venta $venta, ?Carbon $ahora = null): bool
    {
        return $venta->estado_entrega === EstadoEntrega::Entregado
            && $venta->entregado_por === $actor->id
            && self::dentroDelPlazo($venta, $ahora ?? Carbon::now())
            && self::desdeCocina($venta)
            && Gate::forUser($actor)->allows('deshacerEntrega', $venta);
    }

    /**
     * Segundos que le quedan al botón «Deshacer» (0 si ya venció).
     */
    public static function segundosRestantes(Venta $venta, ?Carbon $ahora = null): int
    {
        if ($venta->entregado_en === null) {
            return 0;
        }

        $transcurridos = ($ahora ?? Carbon::now())->getTimestamp() - $venta->entregado_en->getTimestamp();

        return max(0, self::SEGUNDOS - $transcurridos);
    }

    /**
     * Segundos enteros: entregado_en se guarda sin fracción.
     */
    private static function dentroDelPlazo(Venta $venta, Carbon $ahora): bool
    {
        return $venta->entregado_en !== null
            && $ahora->getTimestamp() - $venta->entregado_en->getTimestamp() <= self::SEGUNDOS;
    }

    /**
     * La última transición fue la entrega de un pedido En cocina (no la casilla al vender ni una corrección).
     */
    private static function desdeCocina(Venta $venta): bool
    {
        $ultimo = $venta->relationLoaded('eventosEntrega')
            ? $venta->eventosEntrega->sortByDesc('id')->first()
            : EventoEntrega::query()->where('empresa_id', $venta->empresa_id)->where('venta_id', $venta->id)->orderByDesc('id')->first();

        return $ultimo !== null && ! $ultimo->es_correccion
            && $ultimo->estado_anterior === EstadoEntrega::EnCocina && $ultimo->estado_nuevo === EstadoEntrega::Entregado;
    }

    private static function rechazo(string $mensaje): ValidationException
    {
        return ValidationException::withMessages(['estado_entrega' => $mensaje]);
    }
}
