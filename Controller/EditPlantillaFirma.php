<?php
/**
 * This file is part of FirmarAhora plugin for FacturaScripts.
 * Copyright (C) 2026 Antonio Jesús González Domingo <antonio.gonzalez.domingo@proton.me>
 */

namespace FacturaScripts\Plugins\FirmarAhora\Controller;

use FacturaScripts\Core\Lib\ExtendedController\BaseView;
use FacturaScripts\Core\Lib\ExtendedController\EditController;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Lib\FirmarAhora\Correo;
use FacturaScripts\Dinamic\Lib\FirmarAhora\Firmador;
use FacturaScripts\Dinamic\Lib\FirmarAhora\Variables;
use FacturaScripts\Dinamic\Model\Cliente;
use FacturaScripts\Dinamic\Model\Contacto;
use FacturaScripts\Dinamic\Model\PlantillaFirma;
use FacturaScripts\Dinamic\Model\Proveedor;

/**
 * Ficha de una plantilla: texto con variables, contratos creados con ella, crear un
 * contrato nuevo y envío masivo a una lista de emails.
 */
class EditPlantillaFirma extends EditController
{
    public function getModelClassName(): string
    {
        return 'PlantillaFirma';
    }

    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'sales';
        $data['title'] = 'fa-template';
        $data['icon'] = 'fa-solid fa-file-code';
        return $data;
    }

    /**
     * @return string[] Variables disponibles, para la pestaña de ayuda.
     */
    public function variables(): array
    {
        return Variables::disponibles();
    }

    protected function createViews()
    {
        parent::createViews();
        $this->setTabsPosition('bottom');
        $this->setSettings($this->getMainViewName(), 'btnPrint', false);

        $this->addHtmlView('VariablesPlantilla', 'Tab/VariablesPlantilla', 'PlantillaFirma', 'fa-variables', 'fa-solid fa-code');
        $this->addListView('ListContratoFirma', 'ContratoFirma', 'fa-contracts', 'fa-solid fa-file-contract')
            ->addSearchFields(['titulo', 'email'])
            ->addOrderBy(['creado'], 'date', 2);
        $this->setSettings('ListContratoFirma', 'btnNew', false);
    }

    protected function execPreviousAction($action)
    {
        switch ($action) {
            case 'fa-nuevo-contrato':
                $this->nuevoContrato();
                return true;

            case 'fa-envio-masivo':
                $this->envioMasivo();
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
                $this->imagenes($view, 'idlogo');
                $this->imagenes($view, 'idfirmaempresa');
                if (false === $view->model->exists()) {
                    break;
                }

                if (false === strpos((string)$view->model->cuerpo, '{{firmas}}')) {
                    Tools::log()->info('fa-template-no-signatures');
                }

                $this->addButton($viewName, [
                    'action' => 'fa-nuevo-contrato',
                    'color' => 'success',
                    'icon' => 'fa-solid fa-file-circle-plus',
                    'label' => 'fa-new-contract',
                ]);
                $this->addButton($viewName, [
                    'action' => 'fa-envio-masivo',
                    'color' => 'warning',
                    'icon' => 'fa-solid fa-paper-plane',
                    'label' => 'fa-bulk-send',
                    'type' => 'modal',
                ]);
                break;

            case 'ListContratoFirma':
                $view->loadData('', [Where::eq('idplantilla', $this->getViewModelValue($mvn, 'id'))]);
                break;

            default:
                parent::loadData($viewName, $view);
                break;
        }
    }

    /**
     * Crea un contrato para cada email de la lista y le envía la invitación a firmar.
     * Cada email se busca entre clientes, proveedores y contactos; si no aparece, se
     * crea un contacto cuando así se indica.
     */
    private function envioMasivo(): void
    {
        $plantilla = $this->plantillaDeLaPeticion();
        if (null === $plantilla) {
            return;
        }

        $crear = (bool)$this->request->input('fa_crear_contactos', false);
        $otp = (bool)$this->request->input('fa_otp', $plantilla->requiere_otp);
        $asunto = (string)($this->request->input('fa_asunto', '') ?: Tools::fixHtml((string)$plantilla->asunto));
        $mensaje = (string)($this->request->input('fa_mensaje', '') ?: Tools::fixHtml((string)$plantilla->mensaje));

        $emails = preg_split('/[\s,;]+/', strtolower((string)$this->request->input('fa_emails', '')), -1, PREG_SPLIT_NO_EMPTY);
        $enviados = 0;
        foreach (array_unique($emails) as $email) {
            if (false === filter_var($email, FILTER_VALIDATE_EMAIL)) {
                Tools::log()->warning('not-valid-email', ['%email%' => htmlspecialchars($email)]);
                continue;
            }

            $datos = $this->destinatario($email, $crear);
            if (null === $datos) {
                Tools::log()->warning('fa-email-unknown', ['%email%' => $email]);
                continue;
            }

            $contrato = $plantilla->crearContrato(array_diff_key($datos, ['nombre' => 0]) + ['email' => $email]);
            if (null === $contrato) {
                continue;
            }

            $solicitud = Firmador::crear($contrato, [
                'nombre' => $datos['nombre'] ?? '',
                'email' => $email,
                'rol' => Tools::trans('customer'),
                'requiere_otp' => $otp,
                'dias' => $plantilla->dias_validez ?? '',
            ], $this->user->nick);

            if ($solicitud && Correo::invitacion($solicitud, $asunto, $mensaje, $this->user)) {
                $enviados++;
            }
        }

        Tools::log()->notice('fa-bulk-result', ['%count%' => $enviados]);
    }

    /**
     * Cliente, proveedor o contacto al que pertenece un email.
     *
     * @param string $email
     * @param bool $crear
     *
     * @return ?array Campos para el contrato, más "nombre" para la solicitud.
     */
    private function destinatario(string $email, bool $crear): ?array
    {
        $where = [Where::eq('email', $email)];

        $cliente = new Cliente();
        if ($cliente->loadWhere($where)) {
            return ['codcliente' => $cliente->codcliente, 'idcontacto' => $cliente->idcontactofact, 'nombre' => Tools::fixHtml($cliente->nombre)];
        }

        $proveedor = new Proveedor();
        if ($proveedor->loadWhere($where)) {
            return ['codproveedor' => $proveedor->codproveedor, 'idcontacto' => $proveedor->idcontacto, 'nombre' => Tools::fixHtml($proveedor->nombre)];
        }

        $contacto = new Contacto();
        if ($contacto->loadWhere($where)) {
            return ['idcontacto' => $contacto->idcontacto, 'codcliente' => $contacto->codcliente, 'nombre' => Tools::fixHtml(trim($contacto->nombre . ' ' . $contacto->apellidos))];
        }

        if (false === $crear) {
            return null;
        }

        $contacto->email = $email;
        $contacto->nombre = strstr($email, '@', true);
        return $contacto->save() ? ['idcontacto' => $contacto->idcontacto, 'nombre' => $contacto->nombre] : null;
    }

    /**
     * Rellena un selector con los archivos adjuntos que son imágenes.
     *
     * @param BaseView $view
     * @param string $campo
     */
    private function imagenes(BaseView $view, string $campo): void
    {
        $columna = $view->columnForName($campo);
        if ($columna && $columna->widget->getType() === 'select') {
            $imagenes = $this->codeModel->all('attached_files', 'idfile', 'filename', true, [
                Where::in('mimetype', ['image/png', 'image/jpeg', 'image/gif', 'image/webp']),
            ]);
            $columna->widget->setValuesFromCodeModel($imagenes);
        }
    }

    private function nuevoContrato(): void
    {
        $plantilla = $this->plantillaDeLaPeticion();
        $contrato = $plantilla ? $plantilla->crearContrato() : null;
        if ($contrato) {
            $this->redirect($contrato->url());
        }
    }

    /**
     * Plantilla del código de la url, comprobando permisos y el token del formulario.
     *
     * @return ?PlantillaFirma
     */
    private function plantillaDeLaPeticion(): ?PlantillaFirma
    {
        if (false === $this->permissions->allowUpdate) {
            Tools::log()->warning('not-allowed-modify');
            return null;
        } elseif (false === $this->validateFormToken()) {
            return null;
        }

        $plantilla = new PlantillaFirma();
        if (false === $plantilla->load($this->request->query('code', ''))) {
            Tools::log()->warning('record-not-found');
            return null;
        }

        return $plantilla;
    }
}
