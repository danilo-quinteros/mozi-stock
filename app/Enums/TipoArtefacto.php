<?php

namespace App\Enums;

/**
 * Allowlist de artefactos del Lienzo de Agente Mozi (docs/AGENTE_MOZI_IA.md § 3.5). Un tipo que no
 * esté aquí no se valida, no se guarda y no se dibuja. sandbox_web es el único que ejecuta código, y
 * solo dentro del documento aislado.
 */
enum TipoArtefacto: string
{
    case Kpi = 'kpi';
    case Grafico = 'chart';
    case Tabla = 'table';
    case Hallazgo = 'insight';
    case Vacio = 'empty_state';
    case SandboxWeb = 'sandbox_web';

    public function requiereDataset(): bool
    {
        return in_array($this, [self::Kpi, self::Grafico, self::Tabla, self::SandboxWeb], true);
    }

    /**
     * Botón del chat que abre el artefacto en el Lienzo (en móvil, la vista Lienzo).
     */
    public function boton(): string
    {
        return match ($this) {
            self::Grafico => __('Ver gráfico'),
            self::Tabla => __('Ver tabla'),
            self::SandboxWeb => __('Ver visualización'),
            default => __('Ver datos'),
        };
    }
}
