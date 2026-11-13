<?php

namespace App\Domain\Catalogo\Actions;

use App\Domain\Auditoria\Auditor;
use App\Domain\Catalogo\Variantes;
use App\Models\Color;
use App\Models\Modelo;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Colores de la empresa (ESPECIFICACION_TALLAS_Y_COLORES.md § 3.2 y § 3.3). Solo el administrador, con la capacidad
 * activa. El código corto se sugiere a partir del nombre y se puede editar; cambiarlo no toca los SKU ya creados.
 * Renombrar un color regenera con una sola sentencia el nombre de sus variantes. Un color en uso no se borra: se
 * desactiva.
 */
final class GuardarColor
{
    public function __construct(
        private readonly Auditor $auditor,
        private readonly Variantes $variantes,
    ) {}

    /**
     * Código sugerido y libre en la empresa: NEG, o NEG2, NEG3… si ya existe.
     */
    public function codigoSugerido(int $empresaId, string $nombre, ?int $ignorar = null): string
    {
        $base = Variantes::codigoColor($nombre);

        if ($base === '') {
            return '';
        }

        $usados = DB::table('colores')->where('empresa_id', $empresaId)->where('codigo', 'like', $base.'%')
            ->when($ignorar !== null, fn ($q) => $q->where('id', '!=', $ignorar))
            ->pluck('codigo')->map(fn ($c): string => Str::upper((string) $c))->all();

        for ($n = 1; $n < 1000; $n++) {
            $codigo = $n === 1 ? $base : substr($base, 0, Variantes::MAX_CODIGO_COLOR - strlen((string) $n)).$n;

            if (! in_array($codigo, $usados, true)) {
                return $codigo;
            }
        }

        return $base;
    }

    public function crear(User $actor, string $nombre, ?string $codigo, ?string $muestra): Color
    {
        $empresaId = $this->autorizar($actor);
        [$nombre, $codigo, $muestra] = $this->validar($empresaId, $nombre, $codigo, $muestra, null);

        return DB::transaction(function () use ($actor, $empresaId, $nombre, $codigo, $muestra): Color {
            $color = new Color(['nombre' => $nombre, 'codigo' => $codigo, 'muestra_hex' => $muestra]);
            $color->empresa_id = $empresaId;
            $color->save();
            $this->auditor->registrar('color.creado', $color, $actor, $empresaId, despues: $color->only(['nombre', 'codigo', 'muestra_hex', 'activo']));

            return $color;
        });
    }

    /**
     * @return int variantes renombradas
     */
    public function actualizar(User $actor, Color $color, string $nombre, ?string $codigo, ?string $muestra, bool $activo): int
    {
        $empresaId = $this->autorizar($actor, $color->empresa_id);
        [$nombre, $codigo, $muestra] = $this->validar($empresaId, $nombre, $codigo, $muestra, $color->id);

        return DB::transaction(function () use ($actor, $empresaId, $color, $nombre, $codigo, $muestra, $activo): int {
            $bloqueado = Color::query()->whereKey($color->id)->lockForUpdate()->firstOrFail();
            $antes = $bloqueado->only(['nombre', 'codigo', 'muestra_hex', 'activo']);
            $bloqueado->nombre = $nombre;
            $bloqueado->codigo = $codigo;
            $bloqueado->muestra_hex = $muestra;
            $bloqueado->activo = $activo;

            if (! $bloqueado->isDirty()) {
                return 0;
            }

            $renombrar = $bloqueado->isDirty('nombre');
            $bloqueado->save();
            $renombradas = $renombrar ? Variantes::regenerarNombres($empresaId, 'color_id', $bloqueado->id) : 0;
            $this->auditor->registrar('color.actualizado', $bloqueado, $actor, $empresaId, $antes,
                $bloqueado->only(['nombre', 'codigo', 'muestra_hex', 'activo']) + ($renombrar ? ['variantes_renombradas' => $renombradas] : []));
            $color->setRawAttributes($bloqueado->getAttributes(), true);

            return $renombradas;
        });
    }

    /**
     * @return bool true si se eliminó; false si estaba en uso y solo se desactivó
     */
    public function eliminar(User $actor, Color $color): bool
    {
        $empresaId = $this->autorizar($actor, $color->empresa_id);

        return DB::transaction(function () use ($actor, $empresaId, $color): bool {
            $bloqueado = Color::query()->whereKey($color->id)->lockForUpdate()->firstOrFail();

            if (DB::table('productos')->where('empresa_id', $empresaId)->where('color_id', $bloqueado->id)->exists()) {
                $antes = $bloqueado->only(['nombre', 'codigo', 'activo']);
                $bloqueado->activo = false;
                $bloqueado->save();
                $this->auditor->registrar('color.desactivado', $bloqueado, $actor, $empresaId, $antes, $bloqueado->only(['nombre', 'codigo', 'activo']));
                $color->activo = false;

                return false;
            }

            $this->auditor->registrar('color.eliminado', $bloqueado, $actor, $empresaId, $bloqueado->only(['nombre', 'codigo', 'activo']));
            $bloqueado->delete();

            return true;
        });
    }

    private function autorizar(User $actor, ?int $empresaRegistro = null): int
    {
        Gate::forUser($actor)->authorize('administrarListas', Modelo::class);
        $empresaId = (int) $actor->empresa_id;
        abort_if($empresaRegistro !== null && $empresaRegistro !== $empresaId, 404);
        $this->variantes->exigir($empresaId);

        return $empresaId;
    }

    /**
     * @return array{0: string, 1: string, 2: string|null}
     */
    private function validar(int $empresaId, string $nombre, ?string $codigo, ?string $muestra, ?int $ignorar): array
    {
        $nombre = trim($nombre);
        $errores = [];

        if ($nombre === '' || mb_strlen($nombre) > Variantes::MAX_NOMBRE_COLOR) {
            $errores['nombre'] = __('Escribe un nombre de hasta :max caracteres.', ['max' => Variantes::MAX_NOMBRE_COLOR]);
        } elseif (Color::query()->where('empresa_id', $empresaId)->where('nombre', $nombre)->when($ignorar !== null, fn ($q) => $q->whereKeyNot($ignorar))->exists()) {
            $errores['nombre'] = __('Ya existe un color con ese nombre.');
        }

        $codigo = Str::upper(Str::ascii(trim((string) $codigo)));

        if ($codigo === '' && ! isset($errores['nombre'])) {
            $codigo = $this->codigoSugerido($empresaId, $nombre, $ignorar);
        }

        if (preg_match('/^[A-Z0-9]{1,'.Variantes::MAX_CODIGO_COLOR.'}$/', $codigo) !== 1) {
            $errores['codigo'] = __('El código lleva de 1 a :max letras o números, sin espacios.', ['max' => Variantes::MAX_CODIGO_COLOR]);
        } elseif (Color::query()->where('empresa_id', $empresaId)->where('codigo', $codigo)->when($ignorar !== null, fn ($q) => $q->whereKeyNot($ignorar))->exists()) {
            $errores['codigo'] = __('Ese código ya lo usa otro color.');
        }

        $muestra = trim((string) $muestra);

        if ($muestra !== '' && preg_match('/^#[0-9A-Fa-f]{6}$/', $muestra) !== 1) {
            $errores['muestra'] = __('Elige un color de muestra válido (#RRGGBB).');
        }

        if ($errores !== []) {
            throw ValidationException::withMessages($errores);
        }

        return [$nombre, $codigo, $muestra === '' ? null : Str::upper($muestra)];
    }
}
