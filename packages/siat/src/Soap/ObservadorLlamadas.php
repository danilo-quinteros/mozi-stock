<?php

namespace MoziStudios\Siat\Soap;

/** Punto de extensión para registrar cada llamada (log, métricas, alertas de contingencia). */
interface ObservadorLlamadas
{
    public function registrar(LlamadaSiat $llamada): void;
}
