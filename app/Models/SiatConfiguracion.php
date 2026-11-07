<?php

namespace App\Models;

use App\Enums\ModalidadFacturacion;
use App\Enums\ModoFacturacion;
use App\Models\Concerns\BelongsToEmpresa;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Datos fiscales SIAT de una empresa (una fila por empresa). El token y la contraseña del certificado
 * se guardan cifrados con APP_KEY, están ocultos en toArray/JSON y solo los lee el dominio para llamadas fiscales.
 *
 * @property int $id
 * @property int $empresa_id
 * @property int|null $nit
 * @property string|null $razon_social
 * @property ModoFacturacion $modo
 * @property ModalidadFacturacion $modalidad
 * @property int|null $metodo_pago_electronico
 * @property string|null $actividad_economica
 * @property int|null $producto_sin
 * @property int|null $unidad_medida
 * @property string|null $token
 * @property string|null $token_sufijo
 * @property Carbon|null $token_vence_en
 * @property Carbon|null $token_actualizado_en
 * @property int|null $token_actualizado_por
 * @property string|null $certificado_ruta
 * @property string|null $certificado_nombre
 * @property string|null $certificado_pass
 * @property Carbon|null $certificado_vence_en
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class SiatConfiguracion extends Model
{
    use BelongsToEmpresa;

    protected $table = 'siat_configuraciones';

    protected $guarded = ['id'];

    /** @var list<string> */
    protected $hidden = ['token', 'certificado_pass'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'modo' => 'automatica',
        'modalidad' => 'computarizada',
    ];

    protected function casts(): array
    {
        return [
            'nit' => 'integer',
            'modo' => ModoFacturacion::class,
            'modalidad' => ModalidadFacturacion::class,
            'metodo_pago_electronico' => 'integer',
            'producto_sin' => 'integer',
            'unidad_medida' => 'integer',
            'token' => 'encrypted',
            'token_vence_en' => 'datetime',
            'token_actualizado_en' => 'datetime',
            'certificado_pass' => 'encrypted',
            'certificado_vence_en' => 'datetime',
        ];
    }

    /**
     * Token en claro para una llamada al SIN. Nunca se registra ni se muestra.
     *
     * @throws DecryptException si APP_KEY cambió después de guardarlo
     */
    public function tokenDescifrado(): string
    {
        return (string) $this->token;
    }

    public function tieneToken(): bool
    {
        return $this->token_sufijo !== null;
    }

    public function tokenVencido(): bool
    {
        return $this->token_vence_en !== null && $this->token_vence_en->isPast();
    }

    /** Aviso con un mes de anticipación (decisión D6: avisar antes del vencimiento). */
    public function tokenPorVencer(): bool
    {
        return $this->token_vence_en !== null && ! $this->tokenVencido() && $this->token_vence_en->lessThan(now()->addDays(30));
    }

    public function esElectronica(): bool
    {
        return $this->modalidad === ModalidadFacturacion::Electronica;
    }

    public function esComputarizada(): bool
    {
        return ! $this->esElectronica();
    }

    public function tieneCertificado(): bool
    {
        return filled($this->certificado_ruta) && filled($this->certificado_pass);
    }

    public function certificadoVencido(): bool
    {
        return $this->certificado_vence_en !== null && $this->certificado_vence_en->isPast();
    }

    public function certificadoPorVencer(): bool
    {
        return $this->certificado_vence_en !== null && ! $this->certificadoVencido() && $this->certificado_vence_en->lessThan(now()->addDays(30));
    }

    public function certificadoRutaAbsoluta(): ?string
    {
        if (blank($this->certificado_ruta)) {
            return null;
        }

        return Storage::disk('local')->path($this->certificado_ruta);
    }

    /**
     * @throws DecryptException si APP_KEY cambió
     */
    public function certificadoPasswordDescifrado(): ?string
    {
        return filled($this->certificado_pass) ? (string) $this->certificado_pass : null;
    }
}
