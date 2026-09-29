<?php
/**
 * This file is part of FirmarAhora plugin for FacturaScripts.
 * Copyright (C) 2026 Antonio Jesús González Domingo <antonio.gonzalez.domingo@proton.me>
 */

namespace FacturaScripts\Plugins\FirmarAhora\Controller;

use FacturaScripts\Core\Lib\ExtendedController\EditController;
use FacturaScripts\Dinamic\Lib\FirmarAhora\PanelFirmas;
use FacturaScripts\Dinamic\Lib\FirmarAhora\Sellador;
use FacturaScripts\Dinamic\Model\ContratoFirma;

/**
 * Ficha de un contrato: datos, texto, vista previa, pestaña de firmas y pdf.
 */
class EditContratoFirma extends EditController
{
    public function getModelClassName(): string
    {
        return 'ContratoFirma';
    }

    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'sales';
        $data['title'] = 'fa-contract';
        $data['icon'] = 'fa-solid fa-file-contract';
        return $data;
    }

    protected function createViews()
    {
        parent::createViews();
        $this->setTabsPosition('top');
        $this->setSettings($this->getMainViewName(), 'btnPrint', false);

        $this->addHtmlView('VistaContrato', 'Tab/VistaContrato', 'ContratoFirma', 'preview', 'fa-solid fa-eye');
        $this->addHtmlView(PanelFirmas::VISTA, PanelFirmas::PLANTILLA, 'SolicitudFirma', 'fa-signatures', 'fa-solid fa-file-signature');
        PanelFirmas::recursos();
    }

    protected function execPreviousAction($action)
    {
        if (PanelFirmas::esAccion((string)$action)) {
            PanelFirmas::ejecutar($action, $this->getModel(), $this->request->queryOrInput('code'),
                $this->request, $this->user, $this->permissions, $this->validateFormToken());
            return true;
        }

        if ($action === 'fa-pdf') {
            $this->setTemplate(false);
            $contrato = new ContratoFirma();
            if ($contrato->load($this->request->query('code', ''))) {
                $this->response->pdf(Sellador::pdf($contrato, false), Sellador::nombreArchivo($contrato));
            }
            return false;
        }

        return parent::execPreviousAction($action);
    }

    protected function loadData($viewName, $view)
    {
        $mvn = $this->getMainViewName();
        switch ($viewName) {
            case $mvn:
                parent::loadData($viewName, $view);
                if (false === $view->model->exists()) {
                    break;
                }

                $this->addButton($viewName, [
                    'action' => $view->model->url() . '&action=fa-pdf',
                    'color' => 'info',
                    'icon' => 'fa-solid fa-file-pdf',
                    'label' => 'print',
                    'type' => 'link',
                    'target' => '_blank',
                ]);

                // con alguna firma el texto ya no se puede tocar
                if ($view->model->tieneFirmas()) {
                    $view->disableColumn('cuerpo', false, 'true');
                    $view->disableColumn('template', false, 'true');
                }
                break;

            case 'VistaContrato':
                $view->loadData($this->getViewModelValue($mvn, 'id'));
                if (false === $view->model->exists()) {
                    unset($this->views[$viewName]);
                }
                break;

            case PanelFirmas::VISTA:
                if (false === PanelFirmas::cargar($view, $this->getModel())) {
                    unset($this->views[$viewName]);
                }
                break;
        }
    }
}
