<?php
/**
 * This file is part of FirmarAhora plugin for FacturaScripts.
 * Copyright (C) 2026 Antonio Jesús González Domingo <antonio.gonzalez.domingo@proton.me>
 */

namespace FacturaScripts\Plugins\FirmarAhora\Extension\Model\Base;

use Closure;
use FacturaScripts\Dinamic\Model\SolicitudFirma;

/**
 * Al borrar un presupuesto, pedido, albarán o factura se borran sus solicitudes de
 * firma, con sus imágenes y copias selladas.
 */
class SalesDocument
{
    public function onDelete(): Closure
    {
        return function () {
            foreach (SolicitudFirma::delDocumento($this->modelClassName(), (string)$this->primaryColumnValue()) as $solicitud) {
                $solicitud->delete();
            }
        };
    }
}
