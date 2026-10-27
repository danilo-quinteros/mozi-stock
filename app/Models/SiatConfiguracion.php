<?php

namespace App\Models;

use App\Enums\ModoFacturacion;
use App\Models\Concerns\BelongsToEmpresa;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Datos fiscales SIAT de una empresa (una fila por empresa). El token se guarda cifrado con
 * APP_KEY, está oculto en toArray/JSON y solo lo lee ContribuyenteEmpresa para cada llamada.
 *
 * @property int $id
 * @property int $empresa_id
 * @property int|null $nit
 * @property string|null $razon_social
 * @property ModoFacturacion $modo
 * @property int|null $metodo_pago_electronico
 * @property string|null $actividad_economica
 * @property int|null $producto_sin
 * @property int|null $unidad_medida
 * @property string|null $token
 * @property string|null $token_sufijo
 * @property Carbon|null $token_vence_en
 * @property Carbon|null $token_actualizado_en
 * @property int|null $token_actualizado_por
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class SiatConfiguracion extends Model
{
    use BelongsToEmpresa;

    protected $table = 'siat_configuraciones';

    /** @var list<string> */
    protected $hidden = ['token'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'modo' => 'automatica',
    ];

    protected function casts(): array
    {
        return [
            'nit' => 'integer',
            'modo' => ModoFacturacion::class,
            'metodo_pago_electronico' => 'integer',
            'producto_sin' => 'integer',
            'unidad_medida' => 'integer',
            'token' => 'encrypted',
            'token_vence_en' => 'datetime',
            'token_actualizado_en' => 'datetime',
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
}
