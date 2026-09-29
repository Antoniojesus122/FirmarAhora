<?php
/**
 * This file is part of FirmarAhora plugin for FacturaScripts.
 * Copyright (C) 2026 Antonio Jesús González Domingo <antonio.gonzalez.domingo@proton.me>
 */

namespace FacturaScripts\Plugins\FirmarAhora;

use FacturaScripts\Core\Template\InitClass;
use FacturaScripts\Dinamic\Model\ContratoFirma;
use FacturaScripts\Dinamic\Model\EventoFirma;
use FacturaScripts\Dinamic\Model\PlantillaFirma;
use FacturaScripts\Dinamic\Model\SolicitudFirma;

/**
 * Registra las extensiones del plugin y crea sus tablas al instalar o actualizar.
 */
class Init extends InitClass
{
    /** @var string[] Documentos de venta que se pueden firmar. */
    const DOCUMENTOS = ['PresupuestoCliente', 'PedidoCliente', 'AlbaranCliente', 'FacturaCliente'];

    public function init(): void
    {
        // pestaña "Firmas" en las fichas de los documentos de venta
        $this->loadExtension(new Extension\Controller\EditPresupuestoCliente());
        $this->loadExtension(new Extension\Controller\EditPedidoCliente());
        $this->loadExtension(new Extension\Controller\EditAlbaranCliente());
        $this->loadExtension(new Extension\Controller\EditFacturaCliente());

        // al borrar un documento de venta se borran sus solicitudes de firma
        $this->loadExtension(new Extension\Model\Base\SalesDocument());

        // al convertir un documento (albarán -> factura...) se copian sus firmas
        $this->loadExtension(new Extension\Lib\BusinessDocumentGenerator());
    }

    public function uninstall(): void
    {
    }

    public function update(): void
    {
        new PlantillaFirma();
        new ContratoFirma();
        new SolicitudFirma();
        new EventoFirma();
    }
}
