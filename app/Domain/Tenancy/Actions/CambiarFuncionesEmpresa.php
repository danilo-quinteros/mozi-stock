<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Auditoria\Auditor;
use App\Domain\Tenancy\CapacidadesEmpresa;
use App\Enums\Capacidad;
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
 * (ESPECIFICACION_RUBROS_Y_CAPACIDADES.md § 4 a § 6).
 *
 * Recibe el estado final deseado (rubro + capacidades encendidas) y, bajo lockForUpdate() sobre la
 * empresa, aplica solo la diferencia. Repetir la misma solicitud (doble clic, reintento) encuentra el
 * estado ya aplicado y no hace nada: un solo cambio y un solo registro de auditoría.
 *
 * Nunca toca stock, ventas, pagos, caja ni créditos.
 */
final class CambiarFuncionesEmpresa
{
    public function __construct(
        private readonly Auditor $auditor,
        private readonly CapacidadesEmpresa $capacidades,
    ) {}

    /**
     * @param  list<Capacidad>  $capacidades  las que deben quedar encendidas; el resto queda apagado
     * @return bool true si algo cambió
     */
    public function ejecutar(User $actor, Empresa $empresa, Rubro $rubro, array $capacidades): bool
    {
        Gate::forUser($actor)->authorize('cambiarFunciones', $empresa);

        $deseadas = $this->normalizar($capacidades);
        $this->validarCompatibles($deseadas);

        try {
            return DB::transaction(function () use ($actor, $empresa, $rubro, $deseadas): bool {
                $bloqueada = Empresa::query()->whereKey($empresa->id)->lockForUpdate()->firstOrFail();

                // Estado real bajo el bloqueo (no la lectura en memoria de la petición).
                $actuales = $this->normalizar(EmpresaCapacidad::withoutGlobalScopes()
                    ->where('empresa_id', $bloqueada->id)->where('activa', true)
                    ->pluck('capacidad')->all());

                if ($bloqueada->rubro === $rubro && $actuales === $deseadas) {
                    return false;
                }

                $activar = array_values(array_diff($this->valores($deseadas), $this->valores($actuales)));
                $desactivar = array_values(array_diff($this->valores($actuales), $this->valores($deseadas)));

                foreach ($desactivar as $valor) {
                    $this->verificarApagado($bloqueada, Capacidad::from($valor));
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

                $antes = ['rubro' => $bloqueada->rubro->value, 'capacidades' => $this->valores($actuales)];

                if ($bloqueada->rubro !== $rubro) {
                    $bloqueada->rubro = $rubro;
                    $bloqueada->save();
                }

                $this->auditor->registrar('empresa.funciones_cambiadas', $bloqueada, $actor, $bloqueada->id, $antes, [
                    'rubro' => $rubro->value,
                    'capacidades' => $this->valores($deseadas),
                    'activadas' => $activar,
                    'desactivadas' => $desactivar,
                ]);

                $empresa->setAttribute('rubro', $rubro);
                $empresa->syncOriginalAttribute('rubro');

                return true;
            });
        } finally {
            // Confirmado o revertido, la próxima lectura de esta petición vuelve a la base.
            $this->capacidades->olvidar($empresa->id);
        }
    }

    /**
     * Reglas de apagado de § 5.2 (despachos y cocina sin ventas pendientes de entrega, lotes con
     * confirmación escrita). Cada módulo agrega la suya en su etapa (D1, K1, L3); hoy ninguna capacidad
     * tiene datos propios que proteger, así que apagarla siempre se permite.
     */
    private function verificarApagado(Empresa $empresa, Capacidad $capacidad): void
    {
        // Punto de extensión: lanzar ValidationException con la lista de pendientes cuando corresponda.
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
