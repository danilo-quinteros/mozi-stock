<?php

namespace App\Domain\Despachos;

use App\Enums\EstadoEntrega;
use App\Models\EventoEntrega;
use App\Models\User;
use App\Models\Venta;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Piezas comunes de las transiciones de entrega (ESPECIFICACION_DESPACHOS.md § 3 y § 5.6): el evento de
 * solo inserción, los textos opcionales y el motivo de rechazo cuando la venta ya no está en el estado
 * esperado (doble clic, reintento o una devolución que ganó el bloqueo).
 */
final class HistorialEntrega
{
    public function registrar(
        Venta $venta,
        ?EstadoEntrega $anterior,
        EstadoEntrega $nuevo,
        User $usuario,
        CarbonInterface $momento,
        ?string $nota = null,
        bool $correccion = false,
    ): EventoEntrega {
        $evento = new EventoEntrega;
        $evento->empresa_id = $venta->empresa_id;
        $evento->venta_id = $venta->id;
        $evento->estado_anterior = $anterior;
        $evento->estado_nuevo = $nuevo;
        $evento->usuario_id = $usuario->id;
        $evento->nota = $nota;
        $evento->es_correccion = $correccion;
        $evento->created_at = Carbon::instance($momento);
        $evento->save();

        return $evento;
    }

    /**
     * Texto libre opcional («Retira», «Recibió», nota): sin espacios sobrantes, vacío = null.
     *
     * @throws ValidationException
     */
    public static function texto(?string $valor, int $maximo, string $campo, string $nombre): ?string
    {
        $texto = trim((string) $valor);

        if (mb_strlen($texto) > $maximo) {
            throw ValidationException::withMessages([$campo => __(':campo admite hasta :maximo caracteres.', ['campo' => $nombre, 'maximo' => $maximo])]);
        }

        return $texto === '' ? null : $texto;
    }

    /**
     * Nota obligatoria de las correcciones del administrador.
     *
     * @throws ValidationException
     */
    public static function notaObligatoria(?string $valor): string
    {
        $nota = self::texto($valor, 250, 'nota', __('La nota'));

        if ($nota === null) {
            throw ValidationException::withMessages(['nota' => __('Escribe una nota que explique el cambio.')]);
        }

        return $nota;
    }

    /**
     * Rechazo de una transición que encontró la venta en otro estado, con quién y cuándo la cambió.
     * Leído con la venta bloqueada: el segundo clic de un doble envío llega aquí. $accion es «despachar»,
     * «entregar» (venta de despachos) o «entregar_pedido» (pedido de cocina).
     */
    public function rechazo(Venta $venta, string $accion): ValidationException
    {
        $mensaje = match ($venta->estado_entrega) {
            EstadoEntrega::Cancelado => __('La venta está cancelada: ya no admite cambios de entrega.'),
            EstadoEntrega::PorDespachar => __('La venta todavía no fue despachada: primero el almacén debe autorizar la salida.'),
            EstadoEntrega::EnCocina => __('El pedido está en cocina: se entrega sin despacho.'),
            EstadoEntrega::Despachado => __('Ya despachada por :usuario :momento.', $this->quien($venta, $venta->despachado_por, $venta->despachado_en)),
            EstadoEntrega::Entregado => match (true) {
                $accion === 'despachar' && $venta->despachado_por !== null => __('Ya despachada por :usuario :momento.', $this->quien($venta, $venta->despachado_por, $venta->despachado_en)),
                $venta->entregado_por === null => __('La venta ya está entregada.'),
                // Cocina § 5.2: «Ya entregado por {usuario} a las {hora}».
                $accion === 'entregar_pedido' => __('Ya entregado por :usuario :momento.', $this->quien($venta, $venta->entregado_por, $venta->entregado_en)),
                default => __('Ya entregada por :usuario :momento.', $this->quien($venta, $venta->entregado_por, $venta->entregado_en)),
            },
        };

        return ValidationException::withMessages(['estado_entrega' => $mensaje]);
    }

    /**
     * @return array{usuario: string, momento: string}
     */
    private function quien(Venta $venta, ?int $usuarioId, ?CarbonInterface $momento): array
    {
        $nombre = $usuarioId === null ? null
            : DB::table('users')->where('empresa_id', $venta->empresa_id)->where('id', $usuarioId)->value('name');

        return ['usuario' => is_string($nombre) ? $nombre : __('otro usuario'), 'momento' => self::momento($momento)];
    }

    /**
     * «a las 10:42» si fue hoy en la hora del negocio; si no, «el 05/11/2026 a las 10:42».
     */
    public static function momento(?CarbonInterface $momento): string
    {
        if ($momento === null) {
            return '';
        }

        $zona = (string) config('mozistock.business_timezone');
        $local = Carbon::instance($momento)->setTimezone($zona);

        return $local->isSameDay(Carbon::now($zona))
            ? __('a las :hora', ['hora' => $local->format('H:i')])
            : __('el :fecha a las :hora', ['fecha' => $local->format('d/m/Y'), 'hora' => $local->format('H:i')]);
    }
}
