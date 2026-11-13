<?php

namespace App\Livewire\Catalogo;

use App\Domain\Catalogo\Actions\GuardarColor;
use App\Domain\Catalogo\Actions\GuardarGrupoTalla;
use App\Domain\Catalogo\Actions\GuardarTalla;
use App\Domain\Catalogo\ListasSugeridas;
use App\Enums\Capacidad;
use App\Livewire\Concerns\ExigeCapacidad;
use App\Livewire\Concerns\MuestraAvisos;
use App\Models\Color;
use App\Models\GrupoTalla;
use App\Models\Modelo;
use App\Models\Talla;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Configuración → Tallas y colores (ESPECIFICACION_TALLAS_Y_COLORES.md § 3): grupos de tallas con sus tallas y
 * colores de la empresa. Solo el administrador y con la capacidad «variantes»; toda regla vive en GuardarGrupoTalla,
 * GuardarTalla y GuardarColor. Los IDs que llegan del navegador se resuelven con el scope de empresa (404 si no son
 * de la empresa).
 */
#[Title('Tallas y colores')]
class TallasColores extends Component
{
    use ExigeCapacidad, MuestraAvisos;

    public bool $formGrupo = false;

    #[Locked]
    public ?int $grupoId = null;

    public string $grupoNombre = '';

    public bool $grupoActivo = true;

    public bool $formTalla = false;

    #[Locked]
    public ?int $tallaId = null;

    public string $tallaNombre = '';

    public string $tallaOrden = '0';

    public bool $tallaActiva = true;

    /** @var array<int|string, string> grupo => talla nueva escrita en su campo */
    public array $nuevaTalla = [];

    public bool $formColor = false;

    #[Locked]
    public ?int $colorId = null;

    public string $colorNombre = '';

    public string $colorCodigo = '';

    public string $colorMuestra = '';

    public bool $colorConMuestra = false;

    public bool $colorActivo = true;

    public function mount(): void
    {
        $this->exigirCapacidad(Capacidad::Variantes);
        $this->authorize('administrarListas', Modelo::class);
    }

    public function cargarSugeridas(ListasSugeridas $listas): void
    {
        $this->preparar();
        $this->avisar($listas->crearSiVacias((int) $this->actor()->empresa_id)
            ? __('Se cargó la lista sugerida de tallas y colores. Puedes editarla cuando quieras.')
            : __('Tu empresa ya tiene tallas o colores: la lista sugerida no se cargó.'), 'info');
    }

    public function nuevoGrupo(): void
    {
        $this->preparar();
        $this->reset('grupoId', 'grupoNombre', 'grupoActivo');
        $this->resetValidation();
        $this->formGrupo = true;
    }

    public function editarGrupo(int $id): void
    {
        $this->preparar();
        $grupo = GrupoTalla::query()->findOrFail($id);
        $this->resetValidation();
        $this->grupoId = $grupo->id;
        $this->grupoNombre = $grupo->nombre;
        $this->grupoActivo = $grupo->activo;
        $this->formGrupo = true;
    }

    public function guardarGrupo(GuardarGrupoTalla $accion): void
    {
        $this->preparar();
        $this->validate(['grupoNombre' => ['required', 'string', 'max:'.GuardarGrupoTalla::MAX_NOMBRE]], [], ['grupoNombre' => __('nombre')]);

        $this->conCampos(['nombre' => 'grupoNombre'], function () use ($accion): void {
            if ($this->grupoId === null) {
                $accion->crear($this->actor(), $this->grupoNombre);
                $this->avisar(__('Grupo de tallas creado.'));
            } else {
                $accion->actualizar($this->actor(), GrupoTalla::query()->findOrFail($this->grupoId), $this->grupoNombre, $this->grupoActivo);
                $this->avisar(__('Grupo de tallas actualizado.'));
            }
        });

        $this->formGrupo = false;
    }

    public function eliminarGrupo(int $id, GuardarGrupoTalla $accion): void
    {
        $this->preparar();
        $eliminado = $accion->eliminar($this->actor(), GrupoTalla::query()->findOrFail($id));
        $this->formGrupo = false;
        $this->avisar($eliminado ? __('Grupo de tallas eliminado.') : __('El grupo está en uso: se desactivó y ya no se ofrece para modelos nuevos.'), $eliminado ? 'success' : 'info');
    }

    public function agregarTalla(int $grupoId, GuardarTalla $accion): void
    {
        $this->preparar();
        $grupo = GrupoTalla::query()->findOrFail($grupoId);
        $nombre = trim((string) ($this->nuevaTalla[$grupoId] ?? ''));

        if ($nombre === '') {
            return;
        }

        $this->conCampos(['nombre' => 'nuevaTalla.'.$grupoId], fn () => $accion->crear($this->actor(), $grupo, $nombre));
        $this->nuevaTalla[$grupoId] = '';
        $this->avisar(__('Talla :talla agregada a :grupo.', ['talla' => $nombre, 'grupo' => $grupo->nombre]));
    }

    public function editarTalla(int $id): void
    {
        $this->preparar();
        $talla = Talla::query()->findOrFail($id);
        $this->resetValidation();
        $this->tallaId = $talla->id;
        $this->tallaNombre = $talla->nombre;
        $this->tallaOrden = (string) $talla->orden;
        $this->tallaActiva = $talla->activa;
        $this->formTalla = true;
    }

    public function guardarTalla(GuardarTalla $accion): void
    {
        $this->preparar();
        $this->validate([
            'tallaNombre' => ['required', 'string', 'max:15'],
            'tallaOrden' => ['required', 'integer', 'min:0', 'max:65535'],
        ], [], ['tallaNombre' => __('talla'), 'tallaOrden' => __('orden')]);

        $talla = Talla::query()->findOrFail($this->tallaId);
        $renombradas = 0;
        $this->conCampos(['nombre' => 'tallaNombre', 'orden' => 'tallaOrden'], function () use ($accion, $talla, &$renombradas): void {
            $renombradas = $accion->actualizar($this->actor(), $talla, $this->tallaNombre, (int) $this->tallaOrden, $this->tallaActiva);
        });

        $this->formTalla = false;
        $this->avisar($renombradas > 0
            ? trans_choice('Talla actualizada. Se renombró :count combinación.|Talla actualizada. Se renombraron :count combinaciones.', $renombradas)
            : __('Talla actualizada.'));
    }

    public function eliminarTalla(int $id, GuardarTalla $accion): void
    {
        $this->preparar();
        $eliminada = $accion->eliminar($this->actor(), Talla::query()->findOrFail($id));
        $this->formTalla = false;
        $this->avisar($eliminada ? __('Talla eliminada.') : __('La talla está en uso: se desactivó y ya no se ofrece para combinaciones nuevas.'), $eliminada ? 'success' : 'info');
    }

    public function nuevoColor(): void
    {
        $this->preparar();
        $this->reset('colorId', 'colorNombre', 'colorCodigo', 'colorMuestra', 'colorConMuestra', 'colorActivo');
        $this->resetValidation();
        $this->formColor = true;
    }

    /** Sugiere el código mientras se escribe el nombre de un color nuevo (§ 3.2). */
    public function updatedColorNombre(GuardarColor $accion): void
    {
        if ($this->colorId === null) {
            $this->colorCodigo = $accion->codigoSugerido((int) $this->actor()->empresa_id, $this->colorNombre);
        }
    }

    public function editarColor(int $id): void
    {
        $this->preparar();
        $color = Color::query()->findOrFail($id);
        $this->resetValidation();
        $this->colorId = $color->id;
        $this->colorNombre = $color->nombre;
        $this->colorCodigo = $color->codigo;
        $this->colorConMuestra = $color->muestra_hex !== null;
        $this->colorMuestra = (string) ($color->muestra_hex ?? '#000000');
        $this->colorActivo = $color->activo;
        $this->formColor = true;
    }

    public function guardarColor(GuardarColor $accion): void
    {
        $this->preparar();
        $this->validate(['colorNombre' => ['required', 'string', 'max:40'], 'colorCodigo' => ['nullable', 'string', 'max:6']],
            [], ['colorNombre' => __('nombre'), 'colorCodigo' => __('código')]);
        $muestra = $this->colorConMuestra ? $this->colorMuestra : null;
        $renombradas = 0;

        $this->conCampos(['nombre' => 'colorNombre', 'codigo' => 'colorCodigo', 'muestra' => 'colorMuestra'], function () use ($accion, $muestra, &$renombradas): void {
            if ($this->colorId === null) {
                $accion->crear($this->actor(), $this->colorNombre, $this->colorCodigo, $muestra);
            } else {
                $renombradas = $accion->actualizar($this->actor(), Color::query()->findOrFail($this->colorId), $this->colorNombre, $this->colorCodigo, $muestra, $this->colorActivo);
            }
        });

        $this->avisar($this->colorId === null ? __('Color creado.') : ($renombradas > 0
            ? trans_choice('Color actualizado. Se renombró :count combinación.|Color actualizado. Se renombraron :count combinaciones.', $renombradas)
            : __('Color actualizado.')));
        $this->formColor = false;
    }

    public function eliminarColor(int $id, GuardarColor $accion): void
    {
        $this->preparar();
        $eliminado = $accion->eliminar($this->actor(), Color::query()->findOrFail($id));
        $this->formColor = false;
        $this->avisar($eliminado ? __('Color eliminado.') : __('El color está en uso: se desactivó y ya no se ofrece para combinaciones nuevas.'), $eliminado ? 'success' : 'info');
    }

    public function cerrar(): void
    {
        $this->formGrupo = false;
        $this->formTalla = false;
        $this->formColor = false;
        $this->resetValidation();
    }

    public function render(): View
    {
        $empresaId = (int) $this->actor()->empresa_id;
        // Combinaciones por talla y por color (una consulta cada una) para mostrar qué está en uso.
        $porTalla = DB::table('productos')->where('empresa_id', $empresaId)->whereNotNull('talla_id')->groupBy('talla_id')
            ->selectRaw('talla_id, COUNT(*) as total')->pluck('total', 'talla_id');
        $porColor = DB::table('productos')->where('empresa_id', $empresaId)->whereNotNull('color_id')->groupBy('color_id')
            ->selectRaw('color_id, COUNT(*) as total')->pluck('total', 'color_id');

        return view('livewire.catalogo.tallas-colores', [
            'grupos' => GrupoTalla::query()->with('tallas')->orderByDesc('activo')->orderBy('orden')->orderBy('nombre')->get(),
            'colores' => Color::query()->orderByDesc('activo')->orderBy('nombre')->get(),
            'porTalla' => $porTalla,
            'porColor' => $porColor,
            'tallaEditada' => $this->tallaId === null ? null : Talla::query()->with('grupo')->find($this->tallaId),
        ]);
    }

    /**
     * Cada acción vuelve a exigir la capacidad y el permiso (la petición puede llegar manipulada).
     */
    private function preparar(): void
    {
        $this->exigirCapacidad(Capacidad::Variantes);
        $this->authorize('administrarListas', Modelo::class);
    }

    /**
     * Ejecuta la acción y lleva sus errores a los campos de la pantalla.
     *
     * @param  array<string, string>  $campos  campo de la acción => propiedad del componente
     */
    private function conCampos(array $campos, callable $accion): void
    {
        try {
            $accion();
        } catch (ValidationException $e) {
            $errores = [];

            foreach ($e->errors() as $campo => $mensajes) {
                $errores[$campos[$campo] ?? $campo] = $mensajes;
            }

            throw ValidationException::withMessages($errores);
        }
    }

    private function actor(): User
    {
        /** @var User */
        return Auth::user();
    }
}
