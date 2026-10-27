<?php

namespace App\Domain\Facturacion\Actions;

use App\Domain\Auditoria\Auditor;
use App\Domain\Facturacion\CodigosSiat;
use App\Domain\Facturacion\DeEmpresa;
use App\Domain\Facturacion\ErrorFacturacion;
use App\Models\Empresa;
use App\Models\SiatCatalogo;
use App\Models\SiatPuntoVenta;
use App\Models\SiatSucursal;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use MoziStudios\Siat\ClienteSiat;
use MoziStudios\Siat\Configuracion\PuntoEmision;
use MoziStudios\Siat\Excepciones\DatosInvalidos;
use MoziStudios\Siat\Excepciones\SiatException;
use MoziStudios\Siat\Sincronizacion\Catalogo;

/**
 * Puntos de venta en el SIN (FacturacionOperaciones): registrar, traer los ya registrados y cerrar.
 * Se opera con el CUIS del punto 0 de la sucursal. Registrar y cerrar cambian el registro fiscal y
 * se bloquean durante «Entrar como»; traer los existentes solo actualiza la copia local.
 */
final class GestionarPuntosVenta
{
    public function __construct(
        private readonly ClienteSiat $cliente,
        private readonly CodigosSiat $codigos,
        private readonly Auditor $auditor,
    ) {}

    /**
     * @return array<string, list<string>>
     */
    public static function reglas(): array
    {
        return [
            'tipo' => ['required', 'integer', 'min:1'],
            'nombre' => ['required', 'string', 'max:120'],
            'descripcion' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * @throws ErrorFacturacion|SiatException
     */
    public function registrar(User $actor, SiatSucursal $sucursal, int $tipo, string $nombre, string $descripcion): SiatPuntoVenta
    {
        $empresa = Empresa::query()->findOrFail($sucursal->empresa_id);
        Gate::forUser($actor)->authorize('configurar-facturacion', $empresa);

        $tipoSin = DeEmpresa::consulta(SiatCatalogo::class, $empresa->id)
            ->where('catalogo', Catalogo::TiposPuntoVenta->name)->where('codigo', (string) $tipo)->first()
            ?? throw ValidationException::withMessages(['tipo' => __('Elige un tipo del catálogo sincronizado del SIN.')]);

        $principal = $this->principal($sucursal);
        $cuis = $this->codigos->cuis($principal);
        [$contribuyente] = $this->codigos->preparar($principal);

        // Un doble clic no debe registrar dos puntos en el SIN: el segundo pedido se rechaza.
        $bloqueo = Cache::lock("siat:registro-pv:{$sucursal->id}", 60);

        if (! $bloqueo->get()) {
            throw new ErrorFacturacion(__('Ya hay un registro en curso para esta sucursal. Espera unos segundos.'));
        }

        try {
            $respuesta = $this->cliente->operaciones($contribuyente)->registroPuntoVenta($sucursal->codigo, $cuis->codigo, $tipo, $nombre, $descripcion);
        } catch (DatosInvalidos $error) {
            throw ValidationException::withMessages(['nombre' => $error->getMessage()]);
        } finally {
            $bloqueo->release();
        }

        if (! $respuesta->transaccion || $respuesta->codigoPuntoVenta === null) {
            throw ErrorFacturacion::delSin(__('El SIN no registró el punto de venta.'), $respuesta->mensajes);
        }

        return DB::transaction(function () use ($actor, $empresa, $sucursal, $respuesta, $nombre, $tipoSin): SiatPuntoVenta {
            $punto = $this->guardarLocal($sucursal, (int) $respuesta->codigoPuntoVenta, trim($nombre), $tipoSin->descripcion);
            $this->auditor->registrar('siat.punto_venta_registrado', $punto, $actor, $empresa->id,
                despues: ['sucursal' => $sucursal->codigo, 'codigo' => $punto->codigo, 'nombre' => $punto->nombre]);

            return $punto;
        });
    }

    /**
     * Trae los puntos de venta que el SIN ya tiene registrados para la sucursal.
     *
     * @return int puntos nuevos en MoziStock
     *
     * @throws ErrorFacturacion|SiatException
     */
    public function importar(User $actor, SiatSucursal $sucursal): int
    {
        $empresa = Empresa::query()->findOrFail($sucursal->empresa_id);
        Gate::forUser($actor)->authorize('facturacion-siat', $empresa);

        $principal = $this->principal($sucursal);
        $cuis = $this->codigos->cuis($principal);
        [$contribuyente] = $this->codigos->preparar($principal);
        $respuesta = $this->cliente->operaciones($contribuyente)->consultaPuntoVenta($sucursal->codigo, $cuis->codigo);

        if (! $respuesta->transaccion && $respuesta->puntos === []) {
            throw ErrorFacturacion::delSin(__('El SIN no devolvió los puntos de venta.'), $respuesta->mensajes);
        }

        return DB::transaction(function () use ($actor, $empresa, $sucursal, $respuesta): int {
            $nuevos = 0;

            foreach ($respuesta->puntos as $puntoSin) {
                if ($puntoSin->codigo === 0) {
                    continue;
                }

                $punto = $this->guardarLocal($sucursal, $puntoSin->codigo, $puntoSin->nombre !== '' ? $puntoSin->nombre : __('Punto de venta :codigo', ['codigo' => $puntoSin->codigo]), $puntoSin->tipo);
                $nuevos += $punto->wasRecentlyCreated ? 1 : 0;
            }

            if ($nuevos > 0) {
                $this->auditor->registrar('siat.puntos_venta_importados', $sucursal, $actor, $empresa->id, despues: ['nuevos' => $nuevos]);
            }

            return $nuevos;
        });
    }

    /**
     * @throws ErrorFacturacion|SiatException
     */
    public function cerrar(User $actor, SiatPuntoVenta $punto): SiatPuntoVenta
    {
        $empresa = Empresa::query()->findOrFail($punto->empresa_id);
        Gate::forUser($actor)->authorize('configurar-facturacion', $empresa);

        if ($punto->codigo === 0) {
            throw new ErrorFacturacion(__('El punto de venta 0 representa a la sucursal y no se cierra.'));
        }

        if (! $punto->activo) {
            return $punto;
        }

        $sucursal = DeEmpresa::consulta(SiatSucursal::class, $empresa->id)->findOrFail($punto->siat_sucursal_id);
        $principal = $this->principal($sucursal);
        $cuis = $this->codigos->cuis($principal);
        [$contribuyente] = $this->codigos->preparar($principal);
        $respuesta = $this->cliente->operaciones($contribuyente)->cierrePuntoVenta(new PuntoEmision($sucursal->codigo, $punto->codigo), $cuis->codigo);

        if (! $respuesta->transaccion) {
            throw ErrorFacturacion::delSin(__('El SIN no cerró el punto de venta.'), $respuesta->mensajes);
        }

        return DB::transaction(function () use ($actor, $empresa, $punto): SiatPuntoVenta {
            $bloqueado = DeEmpresa::consulta(SiatPuntoVenta::class, $empresa->id)->whereKey($punto->id)->lockForUpdate()->firstOrFail();
            $bloqueado->activo = false;
            $bloqueado->save();
            $this->auditor->registrar('siat.punto_venta_cerrado', $bloqueado, $actor, $empresa->id, ['activo' => true], ['activo' => false]);

            return $bloqueado;
        });
    }

    private function principal(SiatSucursal $sucursal): SiatPuntoVenta
    {
        return DeEmpresa::consulta(SiatPuntoVenta::class, $sucursal->empresa_id)
            ->where('siat_sucursal_id', $sucursal->id)->where('codigo', 0)->firstOrFail();
    }

    private function guardarLocal(SiatSucursal $sucursal, int $codigo, string $nombre, ?string $tipo): SiatPuntoVenta
    {
        $punto = DeEmpresa::consulta(SiatPuntoVenta::class, $sucursal->empresa_id)
            ->where('siat_sucursal_id', $sucursal->id)->where('codigo', $codigo)->lockForUpdate()->first();

        if ($punto === null) {
            $punto = new SiatPuntoVenta;
            $punto->empresa_id = $sucursal->empresa_id;
            $punto->siat_sucursal_id = $sucursal->id;
            $punto->codigo = $codigo;
        }

        $punto->nombre = mb_substr($nombre, 0, 120);
        $punto->tipo = $tipo === null || $tipo === '' ? null : mb_substr($tipo, 0, 100);
        $punto->activo = true;
        $punto->save();

        return $punto;
    }
}
