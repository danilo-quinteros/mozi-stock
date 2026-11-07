<?php

namespace App\Livewire\AgenteMozi;

use App\Domain\AgenteMozi\Actions\EnviarMensaje;
use App\Domain\AgenteMozi\Ia\GestorProveedores;
use App\Domain\AgenteMozi\Lienzo\ProtocoloLienzo;
use App\Enums\TipoArtefacto;
use App\Models\AgenteArtefacto;
use App\Models\AgenteConversacion;
use App\Models\AgenteMensaje;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Pantalla de Agente Mozi (§ 3): chat a la izquierda y Lienzo a la derecha; en móvil, solo chat con
 * botones «Ver gráfico / tabla / datos / visualización» que abren la vista Lienzo. Pide al layout el
 * menú lateral contraído sin cambiar la preferencia guardada del usuario.
 */
#[Title('Agente Mozi')]
#[Layout('layouts::app', ['sidebarAuto' => 'collapsed'])]
class Panel extends Component
{
    /** Preguntas de ejemplo del motor demo: cada una tiene su escenario de demostración. */
    public const SUGERENCIAS = [
        '¿Qué productos se van a quedar sin stock esta semana?',
        '¿Cuánto vendí y cuánto cobré realmente esta semana?',
        '¿Qué productos dejan más margen?',
        '¿En qué productos tengo dinero sin movimiento?',
        '¿Cuánto podríamos vender el próximo mes?',
        'Crea una visualización personalizada de la cobertura de stock',
    ];

    /** Preguntas de ejemplo con IA: se responden con datos reales (UC-11 a UC-18 y la consulta genérica). */
    public const SUGERENCIAS_IA = [
        '¿Qué sucursal vendió más este mes?',
        '¿Cuánto vendí y cuánto cobré realmente esta semana?',
        '¿A qué clientes debo cobrar primero?',
        '¿Qué pagos a proveedores vencen en los próximos 15 días?',
        '¿Qué productos están en o bajo su stock mínimo?',
        '¿Qué productos se devuelven más y por qué?',
    ];

    /** Con IA el vendedor solo ve su sucursal y nada de finanzas: sus ejemplos se quedan en ese alcance. */
    public const SUGERENCIAS_IA_VENDEDOR = [
        '¿Cuánto vendí hoy en mi sucursal?',
        '¿Qué productos están en o bajo su stock mínimo?',
        '¿Qué pedidos pendientes tengo y qué les falta?',
        '¿Cómo va mi caja?',
    ];

    /** Conversación abierta: solo la cambian nueva() y abrir(), después de autorizar. */
    #[Locked]
    public ?int $conversacionId = null;

    public function mount(): void
    {
        $this->authorize('usar-agente-mozi');
    }

    /**
     * Devuelve true si el mensaje quedó registrado; el navegador solo entonces descarta el borrador.
     */
    public function enviar(string $texto, string $clave): bool
    {
        $this->authorize('usar-agente-mozi');
        $this->resetErrorBag();
        $actor = $this->actor();
        $llave = 'agente-mozi:'.$actor->id;

        if (RateLimiter::tooManyAttempts($llave, (int) config('agente_mozi.limites.mensajes_por_minuto'))) {
            $this->addError('texto', __('Enviaste muchos mensajes seguidos. Espera :segundos segundos.', ['segundos' => RateLimiter::availableIn($llave)]));

            return false;
        }

        RateLimiter::hit($llave, 60);

        try {
            // Estados de trabajo en vivo (§ 6.5): solo textos aptos para la interfaz, nunca razonamiento del modelo.
            $respuesta = app(EnviarMensaje::class)->ejecutar($actor, $this->conversacionId, $texto, $clave,
                fn (string $fase) => $this->stream($fase, replace: true, name: 'agente-fase'));
        } catch (ValidationException $e) {
            $this->addError('texto', (string) collect($e->errors())->flatten()->first());

            return false;
        }

        $this->conversacionId = $respuesta->conversacion_id;
        $this->publicarLienzo($respuesta->artefactos()->exists() ? $respuesta->id : null);

        return true;
    }

    public function nueva(): void
    {
        $this->conversacionId = null;
        $this->resetErrorBag();
        $this->publicarLienzo(null);
    }

    public function abrir(int $id): void
    {
        $conversacion = AgenteConversacion::query()->findOrFail($id);
        $this->authorize('view', $conversacion);

        $this->conversacionId = $conversacion->id;
        $this->resetErrorBag();
        $this->publicarLienzo(null);
    }

    public function render(): View
    {
        $actor = $this->actor();
        $mensajes = $this->mensajes();
        $sucursalId = $actor->sucursalActivaId();
        $conIa = app(GestorProveedores::class)->motor() !== GestorProveedores::DEMO;
        $sugerencias = ! $conIa ? self::SUGERENCIAS : ($actor->esVendedor() ? self::SUGERENCIAS_IA_VENDEDOR : self::SUGERENCIAS_IA);

        return view('livewire.agente-mozi.panel', [
            'mensajes' => $mensajes,
            'historial' => AgenteConversacion::query()->where('usuario_id', $actor->id)
                ->orderByDesc('ultima_actividad_en')->orderByDesc('id')
                ->limit((int) config('agente_mozi.limites.conversaciones_historial'))
                ->get(['id', 'empresa_id', 'usuario_id', 'titulo', 'ultima_actividad_en']),
            'lienzo' => ['analisis' => ProtocoloLienzo::analisis($mensajes), 'foco' => null],
            'empresa' => $actor->empresa->nombre ?? '',
            'sucursal' => $sucursalId === null ? __('Todas las sucursales') : (Sucursal::query()->whereKey($sucursalId)->value('nombre') ?? ''),
            'sugerencias' => array_map(fn (string $s) => __($s), $sugerencias),
            'textos' => $this->textosLienzo(),
            'conIa' => $conIa,
        ]);
    }

    /**
     * Botones del mensaje que abren sus artefactos en el Lienzo, agrupados por tipo de vista.
     *
     * @return list<array{etiqueta: string, icono: string, artefacto: int, cantidad: int}>
     */
    public static function botones(AgenteMensaje $mensaje): array
    {
        $grupos = [];

        foreach ($mensaje->artefactos as $artefacto) {
            /** @var AgenteArtefacto $artefacto */
            $etiqueta = $artefacto->tipo->boton();
            $grupos[$etiqueta] ??= [
                'etiqueta' => $etiqueta,
                'icono' => match ($artefacto->tipo) {
                    TipoArtefacto::Grafico => 'chart',
                    TipoArtefacto::Tabla => 'table',
                    TipoArtefacto::SandboxWeb => 'sparkles',
                    default => 'dashboard',
                },
                'artefacto' => $artefacto->id,
                'cantidad' => 0,
            ];
            $grupos[$etiqueta]['cantidad']++;
        }

        return array_values($grupos);
    }

    /**
     * @return Collection<int, AgenteMensaje>
     */
    private function mensajes(): Collection
    {
        if ($this->conversacionId === null) {
            return new Collection;
        }

        return AgenteMensaje::query()->where('conversacion_id', $this->conversacionId)
            ->with('artefactos.dataset:id,ref')
            ->orderByDesc('id')
            ->limit((int) config('agente_mozi.limites.mensajes_por_conversacion'))
            ->get()->reverse()->values();
    }

    /**
     * El Lienzo vive en JS (wire:ignore): recibe los análisis de la conversación y cuál enfocar.
     */
    private function publicarLienzo(?int $foco): void
    {
        $this->dispatch('agente-mozi-lienzo', analisis: ProtocoloLienzo::analisis($this->mensajes()), foco: $foco);
    }

    /**
     * Textos traducidos que usa el Lienzo en el navegador.
     *
     * @return array<string, string|array<string, string>>
     */
    private function textosLienzo(): array
    {
        return [
            'lienzo' => __('Lienzo'),
            'vacioTitulo' => __('Tu Lienzo está listo'),
            'vacioTexto' => __('Cuando preguntes algo, aquí aparecerán indicadores, gráficos y tablas con su fuente, período y fecha de cálculo.'),
            'nuevo' => __('Nuevo'),
            'ampliar' => __('Ampliar Lienzo'),
            'reducir' => __('Mostrar el chat'),
            'calidad' => [
                'actual' => __('Dato real'),
                'estimated' => __('Estimación'),
                'demo' => __('Datos sintéticos'),
                'insufficient' => __('Datos insuficientes'),
            ],
            'periodo' => __('Período'),
            'calculado' => __('Calculado'),
            'fuente' => __('Fuente'),
            'fuentes' => ['demo_sintetico' => __('Datos sintéticos de demostración')],
            'ambito' => __('Ámbito'),
            'metodo' => __('Método'),
            'nota' => __('Nota'),
            'otros' => __('Otros'),
            'sinDatos' => __('Sin datos'),
            'cargando' => __('Cargando datos…'),
            'noDisponible' => __('Los datos de este artefacto ya no están disponibles.'),
            'buscar' => __('Buscar en la tabla'),
            'filas' => __(':n filas'),
            'pagina' => __('Página :actual de :total'),
            'anterior' => __('Anterior'),
            'siguiente' => __('Siguiente'),
            'exportar' => __('Exportar CSV'),
            'sinResultados' => __('Ningún resultado coincide con la búsqueda.'),
            'ordenar' => __('Ordenar por :columna'),
            'graficoSinDatos' => __(':n elementos sin datos no se grafican.'),
            'graficoError' => __('No se pudo dibujar el gráfico. Revisa la tabla con los mismos datos.'),
            'graficoResumen' => __(':titulo. :n puntos. Valor más alto: :max (:etiqueta).'),
            'sandboxDemo' => __('Vista aislada · código de demostración'),
            'sandboxIa' => __('Visualización generada por IA'),
            'restablecer' => __('Restablecer vista'),
            'verTabla' => __('Ver tabla verificable'),
            'verVista' => __('Volver a la vista'),
            'sandboxFallo' => __('La vista personalizada no pudo mostrarse. Te muestro la tabla verificable con los mismos datos.'),
            'sandboxTiempo' => __('La vista tardó demasiado en responder.'),
            'sandboxNavego' => __('La vista intentó navegar a otra página y se detuvo.'),
            'sandboxError' => __('La vista tuvo un error al ejecutarse.'),
            'sandboxRuido' => __('La vista envió demasiados mensajes y se detuvo.'),
            'analisis' => __('Análisis de esta charla'),
            'indice' => __('Contenido del análisis'),
        ];
    }

    private function actor(): User
    {
        /** @var User $actor */
        $actor = Auth::user();

        return $actor;
    }
}
