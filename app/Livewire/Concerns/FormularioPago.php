<?php

namespace App\Livewire\Concerns;

use App\Support\Decimal;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Campos comunes de un cobro o de un pago posterior (§ 24.4): método, importe o desglose
 * mixto, caja del efectivo (EligeCaja), referencia, nota y comprobante opcional. Los importes
 * en pantalla son informativos; la acción valida todo en servidor.
 */
trait FormularioPago
{
    use EligeCaja, WithFileUploads;

    #[Locked]
    public string $clave = '';

    public string $metodo = 'efectivo';

    public string $monto = '';

    public string $efectivo = '';

    public string $electronico = '';

    public string $referencia = '';

    public string $nota = '';

    /** @var TemporaryUploadedFile|null */
    public $comprobante = null;

    /**
     * Prepara un formulario limpio: clave nueva y la caja por defecto de la sucursal elegida.
     */
    protected function iniciarPago(): void
    {
        $this->reset('metodo', 'monto', 'efectivo', 'electronico', 'referencia', 'nota', 'comprobante');
        $this->clave = (string) Str::uuid();
        $this->elegirCajaPorDefecto();
    }

    /**
     * @return array{metodo: string, monto: string, efectivo: string, electronico: string, referencia: string, nota: string, externo: bool, turno_id: int|null, comprobante: TemporaryUploadedFile|null, clave: string}
     */
    protected function datosPago(): array
    {
        return $this->datosCaja() + [
            'metodo' => $this->metodo,
            'monto' => $this->monto,
            'efectivo' => $this->efectivo,
            'electronico' => $this->electronico,
            'referencia' => $this->referencia,
            'nota' => $this->nota,
            'comprobante' => $this->comprobante instanceof TemporaryUploadedFile ? $this->comprobante : null,
            'clave' => $this->clave,
        ];
    }

    /**
     * Suma informativa del desglose mixto.
     */
    protected function sumaMixta(): string
    {
        return Decimal::sumar(
            Decimal::normalizar($this->efectivo === '' ? '0' : $this->efectivo, 2) ?? '0',
            Decimal::normalizar($this->electronico === '' ? '0' : $this->electronico, 2) ?? '0',
            Decimal::ESCALA_DINERO,
        );
    }
}
