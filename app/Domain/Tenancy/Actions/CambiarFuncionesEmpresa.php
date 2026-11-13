<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Auditoria\Auditor;
use App\Domain\Catalogo\ListasSugeridas;
use App\Domain\Inventario\ControlLotes;
use App\Domain\Tenancy\CapacidadesEmpresa;
use App\Enums\Capacidad;
use App\Enums\EstadoEntrega;
use App\Enums\Rubro;
use App\Models\Empresa;
use App\Models\EmpresaCapacidad;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Única escritura del rubro y de las funciones del negocio de una empresa ya creada
 * (ESPECIFICACION_RUBROS_Y_CAPACIDADES.md § 4 a § 6), incluido el límite de demora de cocina
 * (ESPECIFICACION_COCINA.md § 4.4).
 *
 * Recibe el estado final deseado (rubro + capacidades encendidas) y, bajo lockForUpdate() sobre la
 * empresa, aplica solo la diferencia. Repetir la misma solicitud (doble clic, reintento) encuentra el
 * estado ya aplicado y no hace nada: un solo cambio y un solo registro de auditoría.
 *
 * Nunca toca el stock total, ventas, pagos, caja ni créditos. «Lotes y vencimientos» (rubros § 5.2 y lotes § 9):
 * apagarla con productos marcados exige escribir DESACTIVAR y deja en cero el saldo de cada lote (con su movimiento en
 * el libro por lote, sin tocar stock_niveles); encenderla de nuevo pasa el stock de los productos marcados al lote de
 * regularización. Las marcas de los productos se conservan.
 *
 * «Tallas y colores» (rubros § 5 y tallas § 3.1): encenderla crea la lista sugerida de tallas y colores si la empresa
 * todavía no tiene ninguna. Apagarla siempre se permite y no toca ningún dato: cada variante sigue siendo un producto
 * real que se vende como suelto; los modelos se ocultan y, al reactivar, vuelven a agruparse.
 */
final class CambiarFuncionesEmpresa
{
    /** Ventas pendientes que se nombran al rechazar el apagado de despachos o cocina. */
    private const MUESTRA_PENDIENTES = 10;

    /** Palabra que hay que escribir para apagar «Lotes y vencimientos» con productos marcados (rubros § 5.2). */
    public const CONFIRMAR_LOTES = 'DESACTIVAR';

    public function __construct(
        private readonly Auditor $auditor,
        private readonly CapacidadesEmpresa $capacidades,
        private readonly ControlLotes $control,
        private readonly ListasSugeridas $listas,
    ) {}

    /** Límite de «Avisar pedidos con demora» (ESPECIFICACION_COCINA.md § 4.4). */
    public const MINUTOS_ALERTA_MIN = 1;

    public const MINUTOS_ALERTA_MAX = 240;

    /**
     * @param  list<Capacidad>  $capacidades  las que deben quedar encendidas; el resto queda apagado
     * @param  int|null  $minutosAlertaCocina  minutos para resaltar pedidos demorados; null = no cambia
     * @param  string|null  $confirmacionLotes  lo escrito para apagar «Lotes y vencimientos» (DESACTIVAR)
     * @return bool true si algo cambió
     */
    public function ejecutar(User $actor, Empresa $empresa, Rubro $rubro, array $capacidades, ?int $minutosAlertaCocina = null, ?string $confirmacionLotes = null): bool
    {
        Gate::forUser($actor)->authorize('cambiarFunciones', $empresa);

        $deseadas = $this->normalizar($capacidades);
        $this->validarCompatibles($deseadas);

        if ($minutosAlertaCocina !== null && ($minutosAlertaCocina < self::MINUTOS_ALERTA_MIN || $minutosAlertaCocina > self::MINUTOS_ALERTA_MAX)) {
            throw ValidationException::withMessages(['minutos_alerta_cocina' => __('Elige entre :min y :max minutos.', [
                'min' => self::MINUTOS_ALERTA_MIN, 'max' => self::MINUTOS_ALERTA_MAX,
            ])]);
        }

        try {
            return DB::transaction(function () use ($actor, $empresa, $rubro, $deseadas, $minutosAlertaCocina, $confirmacionLotes): bool {
                $bloqueada = Empresa::query()->whereKey($empresa->id)->lockForUpdate()->firstOrFail();

                // Estado real bajo el bloqueo (no la lectura en memoria de la petición).
                $actuales = $this->normalizar(EmpresaCapacidad::withoutGlobalScopes()
                    ->where('empresa_id', $bloqueada->id)->where('activa', true)
                    ->pluck('capacidad')->all());
                $minutosAntes = $bloqueada->minutos_alerta_cocina;
                $minutos = $minutosAlertaCocina ?? $minutosAntes;

                if ($bloqueada->rubro === $rubro && $actuales === $deseadas && $minutos === $minutosAntes) {
                    return false;
                }

                $activar = array_values(array_diff($this->valores($deseadas), $this->valores($actuales)));
                $desactivar = array_values(array_diff($this->valores($actuales), $this->valores($deseadas)));

                foreach ($desactivar as $valor) {
                    $this->verificarApagado($bloqueada, Capacidad::from($valor));
                }

                $lotes = Capacidad::LotesVencimientos->value;

                if (in_array($lotes, $desactivar, true)) {
                    $this->exigirConfirmacionLotes($bloqueada->id, $confirmacionLotes);
                }

                $ahora = now();
                $filas = array_merge(
                    array_map(fn (string $valor): array => $this->fila($bloqueada, $valor, true, $actor, $ahora), $activar),
                    array_map(fn (string $valor): array => $this->fila($bloqueada, $valor, false, $actor, $ahora), $desactivar),
                );

                if ($filas !== []) {
                    // Una sola sentencia: crea la fila la primera vez y después solo cambia el interruptor.
                    DB::table('empresa_capacidades')->upsert($filas, ['empresa_id', 'capacidad'], ['activa', 'cambiada_por', 'updated_at']);
                }

                $efectoLotes = $this->aplicarLotes($actor, $bloqueada->id, in_array($lotes, $activar, true), in_array($lotes, $desactivar, true));

                if (in_array(Capacidad::Variantes->value, $activar, true)) {
                    $this->listas->crearSiVacias($bloqueada->id);
                }

                $antes = ['rubro' => $bloqueada->rubro->value, 'capacidades' => $this->valores($actuales)];
                $despues = [
                    'rubro' => $rubro->value,
                    'capacidades' => $this->valores($deseadas),
                    'activadas' => $activar,
                    'desactivadas' => $desactivar,
                ] + $efectoLotes;

                // El límite de demora solo figura en la auditoría cuando cambia.
                if ($minutos !== $minutosAntes) {
                    $antes['minutos_alerta_cocina'] = $minutosAntes;
                    $despues['minutos_alerta_cocina'] = $minutos;
                }

                if ($bloqueada->rubro !== $rubro || $minutos !== $minutosAntes) {
                    $bloqueada->rubro = $rubro;
                    $bloqueada->minutos_alerta_cocina = $minutos;
                    $bloqueada->save();
                }

                $this->auditor->registrar('empresa.funciones_cambiadas', $bloqueada, $actor, $bloqueada->id, $antes, $despues);

                foreach (['rubro' => $rubro, 'minutos_alerta_cocina' => $minutos] as $campo => $valor) {
                    $empresa->setAttribute($campo, $valor);
                    $empresa->syncOriginalAttribute($campo);
                }

                return true;
            });
        } finally {
            // Confirmado o revertido, la próxima lectura de esta petición vuelve a la base.
            $this->capacidades->olvidar($empresa->id);
        }
    }

    /**
     * Reglas de apagado de § 5.2. Despachos (D1): no mientras haya ventas Por despachar o Despachado.
     * Cocina (K1): no mientras haya ventas En cocina. En ambos casos se muestra la lista para atenderlas
     * primero. Lotes se apaga con confirmación escrita (exigirConfirmacionLotes); variantes se apaga siempre, sin
     * cambiar ningún dato (las variantes siguen como productos sueltos y los modelos se conservan).
     */
    private function verificarApagado(Empresa $empresa, Capacidad $capacidad): void
    {
        $estados = EstadoEntrega::pendientes($capacidad);

        if ($estados === []) {
            return;
        }

        // Índice (empresa_id, estado_entrega, fecha): no recorre las ventas ya entregadas.
        $pendientes = DB::table('ventas')->where('empresa_id', $empresa->id)
            ->whereIn('estado_entrega', array_map(fn (EstadoEntrega $e): string => $e->value, $estados));
        $total = (clone $pendientes)->count();

        if ($total === 0) {
            return;
        }

        $codigos = $pendientes->orderBy('fecha')->orderBy('id')->limit(self::MUESTRA_PENDIENTES)->pluck('numero')
            ->map(fn ($numero): string => 'V-'.str_pad((string) $numero, 5, '0', STR_PAD_LEFT))->implode(', ');
        $reemplazos = ['total' => $total, 'ventas' => $codigos, 'mas' => $total > self::MUESTRA_PENDIENTES ? __(' y :n más', ['n' => $total - self::MUESTRA_PENDIENTES]) : ''];

        throw ValidationException::withMessages(['capacidades' => $capacidad === Capacidad::Cocina
            ? trans_choice('{1} No puedes desactivar «Pedidos en cocina»: la venta :ventas todavía está en cocina. Entrégala, o cancélala con una devolución, y vuelve a intentarlo.|[2,*] No puedes desactivar «Pedidos en cocina»: :total ventas todavía están en cocina (:ventas:mas). Entrégalas, o cancélalas con una devolución, y vuelve a intentarlo.', $total, $reemplazos)
            : trans_choice('{1} No puedes desactivar «Despachos»: la venta :ventas todavía no se entregó. Despáchala y entrégala, o cancélala con una devolución, y vuelve a intentarlo.|[2,*] No puedes desactivar «Despachos»: :total ventas todavía no se entregaron (:ventas:mas). Despáchalas y entrégalas, o cancélalas con una devolución, y vuelve a intentarlo.', $total, $reemplazos),
        ]);
    }

    /**
     * Productos que controlan lote y vencimiento: los que pierden el detalle por lote al apagar la capacidad.
     */
    public function productosConLote(int $empresaId): int
    {
        return DB::table('productos')->where('empresa_id', $empresaId)->where('controla_vencimiento', true)->count();
    }

    /**
     * Rubros § 5.2: sin productos marcados no hay nada que perder y no se pide confirmación (§ 5.3).
     */
    private function exigirConfirmacionLotes(int $empresaId, ?string $confirmacion): void
    {
        $total = $this->productosConLote($empresaId);

        if ($total === 0 || mb_strtoupper(trim((string) $confirmacion)) === self::CONFIRMAR_LOTES) {
            return;
        }

        throw ValidationException::withMessages(['confirmacion_lotes' => self::mensajeLotes($total)]);
    }

    /**
     * Texto de la confirmación escrita (rubros § 5.2), igual en la pantalla y en el rechazo del servidor.
     */
    public static function mensajeLotes(int $productos): string
    {
        return trans_choice('Se dejará de controlar el lote de :count producto. El stock total no cambia, pero el sistema ya no sabrá de qué lote es cada unidad y permitirá vender productos vencidos. Escribe :palabra para continuar.|Se dejará de controlar el lote de :count productos. El stock total no cambia, pero el sistema ya no sabrá de qué lote es cada unidad y permitirá vender productos vencidos. Escribe :palabra para continuar.', $productos, ['palabra' => self::CONFIRMAR_LOTES]);
    }

    /**
     * Efecto en el stock por lote, en la misma transacción. Apagar: cada lote queda en cero (libro por lote, sin tocar
     * stock_niveles). Encender: el stock de los productos marcados pasa al lote de regularización.
     *
     * @return array<string, int|string> datos para la auditoría
     */
    private function aplicarLotes(User $actor, int $empresaId, bool $activa, bool $desactiva): array
    {
        if ($desactiva) {
            $enCero = $this->control->vaciar($actor, $empresaId, null, __('Se desactivó «Lotes y vencimientos»: el stock deja de llevarse por lote.'));

            return $enCero === 0 ? [] : ['lotes_en_cero' => $enCero];
        }

        if (! $activa) {
            return [];
        }

        $marcados = array_values(DB::table('productos')->where('empresa_id', $empresaId)->where('controla_vencimiento', true)->orderBy('id')
            ->pluck('id')->map(fn ($id): int => (int) $id)->all());

        return $marcados === [] ? [] : ['regularizado' => $this->control->iniciar($actor, $empresaId, $marcados, __('Se activó «Lotes y vencimientos»: el stock de los productos marcados pasa al lote de regularización.'))];
    }

    /**
     * Cocina y despachos no pueden estar activas a la vez. La pantalla lo impide y aquí se valida
     * siempre, aunque la petición llegue manipulada. Pública para que la pantalla avise antes del resumen.
     *
     * @param  list<Capacidad>  $capacidades
     */
    public function validarCompatibles(array $capacidades): void
    {
        foreach ($capacidades as $capacidad) {
            foreach ($capacidad->incompatibles() as $otra) {
                if (in_array($otra, $capacidades, true)) {
                    throw ValidationException::withMessages(['capacidades' => __('«:una» y «:otra» no pueden estar activas a la vez: elige solo una.', [
                        'una' => $capacidad->etiqueta(),
                        'otra' => $otra->etiqueta(),
                    ])]);
                }
            }
        }
    }

    /**
     * Sin repetidos y en el orden del enum, para comparar estados.
     *
     * @param  array<int, Capacidad>  $capacidades
     * @return list<Capacidad>
     */
    private function normalizar(array $capacidades): array
    {
        return array_values(array_filter(Capacidad::cases(), fn (Capacidad $c): bool => in_array($c, $capacidades, true)));
    }

    /**
     * @param  list<Capacidad>  $capacidades
     * @return list<string>
     */
    private function valores(array $capacidades): array
    {
        return array_map(fn (Capacidad $c): string => $c->value, $capacidades);
    }

    /**
     * @return array{empresa_id: int, capacidad: string, activa: bool, cambiada_por: int, created_at: CarbonInterface, updated_at: CarbonInterface}
     */
    private function fila(Empresa $empresa, string $capacidad, bool $activa, User $actor, CarbonInterface $ahora): array
    {
        return [
            'empresa_id' => $empresa->id,
            'capacidad' => $capacidad,
            'activa' => $activa,
            'cambiada_por' => $actor->id,
            'created_at' => $ahora,
            'updated_at' => $ahora,
        ];
    }
}
