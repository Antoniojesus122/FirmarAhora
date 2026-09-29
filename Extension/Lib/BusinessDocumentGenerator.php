<?php
/**
 * This file is part of FirmarAhora plugin for FacturaScripts.
 * Copyright (C) 2026 Antonio Jesús González Domingo <antonio.gonzalez.domingo@proton.me>
 */

namespace FacturaScripts\Plugins\FirmarAhora\Extension\Lib;

use Closure;
use FacturaScripts\Dinamic\Lib\FirmarAhora\Firmador;

/**
 * Al convertir un documento (presupuesto, pedido, albarán, factura) el nuevo
 * documento recibe una copia de las firmas del original.
 */
class BusinessDocumentGenerator
{
    public function generateTrue(): Closure
    {
        return function ($prototype, $lines, $quantity, $properties, $newDoc) {
            Firmador::copiarFirmas($prototype, $newDoc);
        };
    }
}
