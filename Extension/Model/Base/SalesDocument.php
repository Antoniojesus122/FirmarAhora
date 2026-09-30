<?php
/**
 * This file is part of FirmarAhora plugin for FacturaScripts.
 * Copyright (C) 2026 Antonio Jesús González Domingo <antonio.gonzalez.domingo@proton.me>
 */

namespace FacturaScripts\Plugins\FirmarAhora\Extension\Model\Base;

use Closure;
use FacturaScripts\Dinamic\Model\SolicitudFirma;

/**
 * Al borrar un presupuesto, pedido, albarán o factura se borran sus solicitudes sin
 * firmar. Las firmadas se conservan, con su copia sellada: son la prueba de lo que se
 * firmó y no deben desaparecer con el documento.
 */
class SalesDocument
{
    public function onDelete(): Closure
    {
        return function () {
            foreach (SolicitudFirma::delDocumento($this->modelClassName(), (string)$this->primaryColumnValue()) as $solicitud) {
                if ($solicitud->estado === SolicitudFirma::ESTADO_FIRMADA && empty($solicitud->origen)) {
                    $solicitud->archivar();
                } else {
                    $solicitud->delete();
                }
            }
        };
    }
}
