<?php
/**
 * This file is part of FirmarAhora plugin for FacturaScripts.
 * Copyright (C) 2026 Antonio Jesús González Domingo <antonio.gonzalez.domingo@proton.me>
 */

namespace FacturaScripts\Plugins\FirmarAhora\Controller;

use FacturaScripts\Core\Lib\ExtendedController\EditController;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Lib\FirmarAhora\Correo;
use FacturaScripts\Dinamic\Lib\FirmarAhora\Firmador;
use FacturaScripts\Dinamic\Model\SolicitudFirma;

/**
 * Ficha de una solicitud de firma: datos, evidencias y registro de auditoría. Los datos
 * de la firma son de sólo lectura; desde aquí se puede reenviar, anular o descargar la
 * copia sellada.
 */
class EditSolicitudFirma extends EditController
{
    public function getModelClassName(): string
    {
        return 'SolicitudFirma';
    }

    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'sales';
        $data['title'] = 'fa-request';
        $data['icon'] = 'fa-solid fa-signature';
        return $data;
    }

    protected function createViews()
    {
        parent::createViews();
        $mvn = $this->getMainViewName();
        $this->setSettings($mvn, 'btnPrint', false);
        $this->setSettings($mvn, 'btnNew', false);
        $this->setTabsPosition('top');

        $this->addHtmlView('EvidenciasSolicitud', 'Tab/EvidenciasSolicitud', 'SolicitudFirma', 'fa-evidence', 'fa-solid fa-fingerprint');
        $this->addListView('ListEventoFirma', 'EventoFirma', 'fa-audit-trail', 'fa-solid fa-list-check')
            ->addOrderBy(['fecha', 'id'], 'date', 1);
        $this->setSettings('ListEventoFirma', 'btnNew', false);
        $this->setSettings('ListEventoFirma', 'btnDelete', false);
        $this->setSettings('ListEventoFirma', 'checkBoxes', false);
        $this->setSettings('ListEventoFirma', 'clickable', false);
    }

    protected function execPreviousAction($action)
    {
        switch ($action) {
            case 'fa-sellado':
                $this->descargarSellado();
                return false;

            case 'fa-reenviar':
            case 'fa-anular':
                $this->accion($action);
                return true;
        }

        return parent::execPreviousAction($action);
    }

    protected function loadData($viewName, $view)
    {
        $mvn = $this->getMainViewName();
        switch ($viewName) {
            case $mvn:
                parent::loadData($viewName, $view);
                if ($view->model->exists()) {
                    $this->botones($viewName, $view->model);
                }
                break;

            case 'EvidenciasSolicitud':
                $view->loadData($this->getViewModelValue($mvn, 'id'));
                break;

            case 'ListEventoFirma':
                $view->loadData('', [Where::eq('idsolicitud', $this->getViewModelValue($mvn, 'id'))]);
                break;
        }
    }

    private function accion(string $action): void
    {
        if (false === $this->permissions->allowUpdate) {
            Tools::log()->warning('not-allowed-modify');
            return;
        } elseif (false === $this->validateFormToken()) {
            return;
        }

        $solicitud = new SolicitudFirma();
        if (false === $solicitud->load($this->request->query('code', ''))) {
            Tools::log()->warning('record-not-found');
            return;
        }

        if ($action === 'fa-reenviar' && Correo::invitacion($solicitud, '', '', $this->user)) {
            Tools::log()->notice('fa-invite-sent', ['%email%' => $solicitud->email]);
        } elseif ($action === 'fa-anular' && Firmador::anular($solicitud, $this->user->nick)) {
            Tools::log()->notice('fa-request-cancelled');
        }
    }

    private function botones(string $viewName, SolicitudFirma $solicitud): void
    {
        $documento = $solicitud->getDocumento();
        if ($documento) {
            $this->addButton($viewName, [
                'action' => $documento->url() . (str_contains($documento->url(), '?') ? '&' : '?') . 'activetab=firmarahora',
                'color' => 'info',
                'icon' => 'fa-solid fa-file-lines',
                'label' => 'document',
                'type' => 'link',
            ]);
        }

        if ($solicitud->estado === SolicitudFirma::ESTADO_FIRMADA && false === empty($solicitud->sellado_path)) {
            $this->addButton($viewName, [
                'action' => $solicitud->url() . '&action=fa-sellado',
                'color' => 'success',
                'icon' => 'fa-solid fa-file-shield',
                'label' => 'fa-sealed-copy',
                'type' => 'link',
                'target' => '_blank',
            ]);
        }

        if ($solicitud->estaAbierta()) {
            if (false === empty($solicitud->email)) {
                $this->addButton($viewName, [
                    'action' => 'fa-reenviar',
                    'color' => 'primary',
                    'icon' => 'fa-solid fa-paper-plane',
                    'label' => 'fa-resend',
                    'confirm' => true,
                ]);
            }
            $this->addButton($viewName, [
                'action' => 'fa-anular',
                'color' => 'warning',
                'icon' => 'fa-solid fa-ban',
                'label' => 'fa-cancel-request',
                'confirm' => true,
            ]);
        }
    }

    private function descargarSellado(): void
    {
        $this->setTemplate(false);
        $solicitud = new SolicitudFirma();
        if (false === $solicitud->load($this->request->query('code', '')) || empty($solicitud->sellado_path)
            || false === is_file(FS_FOLDER . '/' . $solicitud->sellado_path)) {
            $this->response->setHttpCode(404)->setContent('')->send();
            return;
        }

        $this->response->pdf((string)file_get_contents(FS_FOLDER . '/' . $solicitud->sellado_path), 'firmado_' . $solicitud->codigo . '.pdf');
    }
}
