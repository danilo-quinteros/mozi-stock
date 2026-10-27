<?php

namespace App\Domain\Facturacion;

use App\Models\Empresa;
use App\Models\SiatCatalogo;
use App\Models\SiatPuntoVenta;
use App\Models\SiatSincronizacion;
use App\Models\SiatSucursal;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use MoziStudios\Siat\ClienteSiat;
use MoziStudios\Siat\Configuracion\PuntoEmision;
use MoziStudios\Siat\Excepciones\SiatException;
use MoziStudios\Siat\Respuestas\ItemCatalogo;
use MoziStudios\Siat\Sincronizacion\Catalogo;

/**
 * Descarga los catálogos del SIN para una empresa con el CUIS de su sucursal principal y reemplaza
 * cada catálogo completo en una transacción (las llamadas al SIN van antes, fuera de ella). Si un
 * catálogo falla se conserva su versión anterior y se registra el error; los demás continúan.
 */
final class SincronizarCatalogos
{
    private const LOTE = 500;

    public function __construct(
        private readonly ClienteSiat $cliente,
        private readonly ContribuyenteEmpresa $contribuyentes,
        private readonly CodigosSiat $codigos,
    ) {}

    /**
     * @param  list<Catalogo>|null  $catalogos  null = todos
     * @return array{correctos: int, fallidos: list<string>}
     *
     * @throws ErrorFacturacion|SiatException si la empresa no puede consultar al SIN
     */
    public function ejecutar(Empresa $empresa, ?array $catalogos = null): array
    {
        // Un doble clic o el job diario simultáneo no descargan dos veces: el segundo se rechaza.
        $bloqueo = Cache::lock("siat:catalogos:{$empresa->id}", 600);

        if (! $bloqueo->get()) {
            throw new ErrorFacturacion(__('Ya hay una sincronización de catálogos en curso. Espera a que termine.'));
        }

        try {
            return $this->descargar($empresa, $catalogos);
        } finally {
            $bloqueo->release();
        }
    }

    /**
     * @param  list<Catalogo>|null  $catalogos
     * @return array{correctos: int, fallidos: list<string>}
     *
     * @throws ErrorFacturacion|SiatException
     */
    private function descargar(Empresa $empresa, ?array $catalogos): array
    {
        $contribuyente = $this->contribuyentes->para($empresa);
        [$sucursal, $principal] = $this->puntoPrincipal($empresa);
        $cuis = $this->codigos->cuis($principal);
        $servicio = $this->cliente->sincronizacion($contribuyente);
        $emision = new PuntoEmision($sucursal->codigo, $principal->codigo);
        $correctos = 0;
        $fallidos = [];

        foreach ($catalogos ?? Catalogo::cases() as $catalogo) {
            try {
                $respuesta = $servicio->catalogo($catalogo, $emision, $cuis->codigo);

                if (! $respuesta->transaccion && $respuesta->items === []) {
                    throw ErrorFacturacion::delSin(__('El SIN rechazó la sincronización.'), $respuesta->mensajes);
                }

                $this->guardar($empresa->id, $catalogo, $respuesta->items);
                $correctos++;
            } catch (ErrorFacturacion|SiatException $error) {
                $this->estado($empresa->id, $catalogo)->forceFill([
                    'fallo_en' => now(),
                    'error' => mb_substr($error->getMessage(), 0, 500),
                ])->save();
                $fallidos[] = __($catalogo->etiqueta());
            }
        }

        return ['correctos' => $correctos, 'fallidos' => $fallidos];
    }

    /**
     * Sucursal SIN de menor código (0 = casa matriz) y su punto de venta 0.
     *
     * @return array{0: SiatSucursal, 1: SiatPuntoVenta}
     */
    public function puntoPrincipal(Empresa $empresa): array
    {
        $sucursal = DeEmpresa::consulta(SiatSucursal::class, $empresa->id)->orderBy('codigo')->first()
            ?? throw new ErrorFacturacion(__('Registra primero una sucursal en el SIN (Facturación → Sucursales y puntos de venta).'));

        $punto = DeEmpresa::consulta(SiatPuntoVenta::class, $empresa->id)
            ->where('siat_sucursal_id', $sucursal->id)->where('codigo', 0)->firstOrFail();

        return [$sucursal, $punto];
    }

    /**
     * @param  list<ItemCatalogo>  $items
     */
    private function guardar(int $empresaId, Catalogo $catalogo, array $items): void
    {
        DB::transaction(function () use ($empresaId, $catalogo, $items): void {
            DeEmpresa::consulta(SiatCatalogo::class, $empresaId)->where('catalogo', $catalogo->name)->delete();

            foreach (array_chunk($items, self::LOTE) as $lote) {
                DB::table('siat_catalogos')->insert(array_map(fn (ItemCatalogo $item): array => [
                    'empresa_id' => $empresaId,
                    'catalogo' => $catalogo->name,
                    'codigo' => mb_substr($item->codigo, 0, 50),
                    'descripcion' => $item->descripcion,
                    'atributos' => $item->atributos === [] ? null : json_encode($item->atributos, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                ], $lote));
            }

            $this->estado($empresaId, $catalogo)->forceFill([
                'cantidad' => count($items),
                'sincronizado_en' => now(),
                'fallo_en' => null,
                'error' => null,
            ])->save();
        });
    }

    private function estado(int $empresaId, Catalogo $catalogo): SiatSincronizacion
    {
        $estado = DeEmpresa::consulta(SiatSincronizacion::class, $empresaId)->where('catalogo', $catalogo->name)->first();

        if ($estado === null) {
            $estado = new SiatSincronizacion;
            $estado->empresa_id = $empresaId;
            $estado->catalogo = $catalogo->name;
        }

        return $estado;
    }
}
