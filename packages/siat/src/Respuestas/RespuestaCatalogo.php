<?php

namespace MoziStudios\Siat\Respuestas;

use MoziStudios\Siat\Sincronizacion\Catalogo;

/** Resultado de sincronizar un catálogo, ya normalizado a ItemCatalogo. */
final class RespuestaCatalogo
{
    /**
     * @param  list<Mensaje>  $mensajes
     * @param  list<ItemCatalogo>  $items
     */
    public function __construct(
        public readonly Catalogo $catalogo,
        public readonly bool $transaccion,
        public readonly array $mensajes,
        public readonly array $items,
    ) {}

    public static function desde(Catalogo $catalogo, object $respuesta): self
    {
        return new self(
            $catalogo,
            Lector::booleano($respuesta, 'transaccion'),
            Lector::mensajes($respuesta),
            array_map($catalogo->item(...), Lector::lista($respuesta, $catalogo->propiedadLista())),
        );
    }
}
