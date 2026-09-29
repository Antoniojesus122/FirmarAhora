<?php
/**
 * This file is part of FirmarAhora plugin for FacturaScripts.
 * Copyright (C) 2026 Antonio Jesús González Domingo <antonio.gonzalez.domingo@proton.me>
 */

namespace FacturaScripts\Plugins\FirmarAhora\Lib\FirmarAhora;

use Closure;

/**
 * Base de las extensiones que añaden la pestaña "Firmas" a un controlador de documentos.
 * Cada método devuelve un Closure que FacturaScripts liga al controlador, así que dentro
 * $this es el controlador.
 */
abstract class ExtensionPanelFirmas
{
    public function createViews(): Closure
    {
        return function () {
            $this->addHtmlView(PanelFirmas::VISTA, PanelFirmas::PLANTILLA, 'SolicitudFirma', 'fa-signatures', 'fa-solid fa-file-signature');
            PanelFirmas::recursos();
        };
    }

    public function execPreviousAction(): Closure
    {
        return function ($action) {
            if (false === PanelFirmas::esAccion((string)$action)) {
                return null;
            }

            PanelFirmas::ejecutar(
                $action,
                $this->getModel(),
                $this->request->queryOrInput('code'),
                $this->request,
                $this->user,
                $this->permissions,
                $this->validateFormToken()
            );

            return true;
        };
    }

    public function loadData(): Closure
    {
        return function ($viewName, $view) {
            if ($viewName === PanelFirmas::VISTA && false === PanelFirmas::cargar($view, $this->getModel())) {
                unset($this->views[$viewName]);
            }
        };
    }
}
