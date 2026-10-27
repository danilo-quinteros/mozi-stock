<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use MoziStudios\Siat\ClienteSiat;
use MoziStudios\Siat\Configuracion\Contribuyente;
use MoziStudios\Siat\Configuracion\PuntoEmision;
use MoziStudios\Siat\Configuracion\TokenDelegado;
use MoziStudios\Siat\Constantes\Ambiente;
use MoziStudios\Siat\Excepciones\SiatException;
use MoziStudios\Siat\Respuestas\Mensaje;

/**
 * Comprueba la conexión con el piloto del SIN usando el NIT y el token de prueba de .env. Solo corre
 * en el ambiente de pruebas, no guarda nada en la base de datos y nunca muestra el token.
 */
class DiagnosticoSiat extends Command
{
    protected $signature = 'mozistock:siat-diagnostico
        {--cuis : Solicita también el CUIS del punto de emisión}
        {--cufd : Solicita también el CUIS y el CUFD del punto de emisión}
        {--sucursal=0 : Código de sucursal en el SIN (0 = casa matriz)}
        {--punto-venta=0 : Código de punto de venta en el SIN}';

    protected $description = 'Verifica la comunicación con el piloto SIAT del SIN (sin guardar datos)';

    public function handle(): int
    {
        if ((int) config('siat.ambiente') !== Ambiente::Pruebas->value) {
            $this->error('El diagnóstico solo se ejecuta con SIAT_AMBIENTE=2 (piloto del SIN).');

            return self::FAILURE;
        }

        $faltantes = array_keys(array_filter([
            'SIAT_CODIGO_SISTEMA' => blank(config('siat.codigo_sistema')),
            'SIAT_PRUEBAS_NIT' => blank(config('siat.pruebas.nit')),
            'SIAT_PRUEBAS_TOKEN' => blank(config('siat.pruebas.token')),
        ]));

        if ($faltantes !== []) {
            $this->error('Faltan variables en .env: '.implode(', ', $faltantes).'.');
            $this->line('El token delegado se genera en el portal SIAT del contribuyente para este código de sistema y se pega solo en .env.');

            return self::FAILURE;
        }

        try {
            $cliente = app(ClienteSiat::class);
            $contribuyente = new Contribuyente((int) config('siat.pruebas.nit'), new TokenDelegado((string) config('siat.pruebas.token')));
            $punto = new PuntoEmision((int) $this->option('sucursal'), (int) $this->option('punto-venta'));
        } catch (SiatException $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }

        $this->info("Piloto SIN · sistema {$cliente->configuracion()->codigoSistema} · NIT {$contribuyente->nit} · token {$contribuyente->token->sufijo()}");

        $correcto = $this->comunicacion($cliente, $contribuyente);

        if ($correcto && ($this->option('cuis') || $this->option('cufd'))) {
            $correcto = $this->codigos($cliente, $contribuyente, $punto);
        }

        return $correcto ? self::SUCCESS : self::FAILURE;
    }

    private function comunicacion(ClienteSiat $cliente, Contribuyente $contribuyente): bool
    {
        $pruebas = [
            'FacturacionCodigos' => fn () => $cliente->codigos($contribuyente)->verificarComunicacion(),
            'FacturacionSincronizacion' => fn () => $cliente->sincronizacion($contribuyente)->verificarComunicacion(),
            'ServicioFacturacionCompraVenta' => fn () => $cliente->compraVenta($contribuyente)->verificarComunicacion(),
        ];
        $filas = [];
        $correcto = true;

        foreach ($pruebas as $servicio => $prueba) {
            try {
                $respuesta = $prueba();
                $filas[] = [$servicio, $respuesta->transaccion ? 'OK' : 'RECHAZADO', $this->mensajes($respuesta->mensajes)];
                $correcto = $correcto && $respuesta->transaccion;
            } catch (SiatException $error) {
                $filas[] = [$servicio, 'ERROR', $error->getMessage()];
                $correcto = false;
            }
        }

        $this->table(['Servicio', 'Resultado', 'Mensajes del SIN'], $filas);

        return $correcto;
    }

    private function codigos(ClienteSiat $cliente, Contribuyente $contribuyente, PuntoEmision $punto): bool
    {
        try {
            $cuis = $cliente->codigos($contribuyente)->cuis($punto);
            $this->line("CUIS sucursal {$punto->sucursal} / PV {$punto->puntoVenta}: ".($cuis->codigo ?? '—')
                .' · vence '.($cuis->fechaVigencia?->format('d/m/Y H:i') ?? '—').' · '.$this->mensajes($cuis->mensajes));

            if ($cuis->codigo === null) {
                return false;
            }

            // El CUF usa la hora del servidor: un desfase grande hace que el SIN rechace facturas.
            $hora = $cliente->sincronizacion($contribuyente)->fechaHora($punto, $cuis->codigo);

            if ($hora->fechaHora !== null) {
                $local = now((string) config('mozistock.business_timezone'));
                $this->line('Hora SIN '.$hora->fechaHora->format('d/m/Y H:i:s').' · desfase del servidor: '.($local->getTimestamp() - $hora->fechaHora->getTimestamp()).' s');
            }

            if (! $this->option('cufd')) {
                return true;
            }

            $cufd = $cliente->codigos($contribuyente)->cufd($punto, $cuis->codigo);
            $this->line('CUFD: '.($cufd->cufd === null
                ? 'no entregado'
                : 'código interno '.($cufd->cufd->codigoInterno ?? '—').' · vence '.($cufd->cufd->fechaVigencia?->format('d/m/Y H:i') ?? '—'))
                .' · '.$this->mensajes($cufd->mensajes));

            return $cufd->cufd !== null;
        } catch (SiatException $error) {
            $this->error($error->getMessage());

            return false;
        }
    }

    /**
     * @param  list<Mensaje>  $mensajes
     */
    private function mensajes(array $mensajes): string
    {
        return $mensajes === [] ? '—' : implode('; ', array_map(strval(...), $mensajes));
    }
}
