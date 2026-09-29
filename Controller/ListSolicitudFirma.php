<?php
/**
 * This file is part of FirmarAhora plugin for FacturaScripts.
 * Copyright (C) 2026 Antonio Jesús González Domingo <antonio.gonzalez.domingo@proton.me>
 */

namespace FacturaScripts\Plugins\FirmarAhora\Controller;

use FacturaScripts\Core\Lib\ExtendedController\ListController;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Model\SolicitudFirma;

/**
 * Menú Ventas > Firmas: solicitudes de firma, contratos y plantillas.
 */
class ListSolicitudFirma extends ListController
{
    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'sales';
        $data['title'] = 'fa-signatures';
        $data['icon'] = 'fa-solid fa-file-signature';
        return $data;
    }

    protected function createViews()
    {
        $this->createViewSolicitudes();
        $this->createViewContratos();
        $this->createViewPlantillas();
    }

    protected function createViewContratos(string $viewName = 'ListContratoFirma'): void
    {
        $this->addView($viewName, 'ContratoFirma', 'fa-contracts', 'fa-solid fa-file-contract')
            ->addSearchFields(['titulo', 'email', 'observaciones'])
            ->addOrderBy(['creado'], 'date', 2)
            ->addOrderBy(['titulo'], 'title')
            ->addFilterPeriod('creado', 'date', 'creado', true)
            ->addFilterSelect('estado', 'status', 'estado', $this->opciones('fa-contract-status-', ['borrador', 'enviado', 'parcial', 'firmado', 'rechazado']))
            ->addFilterAutocomplete('idplantilla', 'fa-template', 'idplantilla', 'fa_plantillas', 'id', 'nombre')
            ->addFilterAutocomplete('codcliente', 'customer', 'codcliente', 'clientes', 'codcliente', 'nombre');
    }

    protected function createViewPlantillas(string $viewName = 'ListPlantillaFirma'): void
    {
        $this->addView($viewName, 'PlantillaFirma', 'fa-templates', 'fa-solid fa-file-code')
            ->addSearchFields(['nombre', 'asunto'])
            ->addOrderBy(['nombre'], 'name', 1)
            ->addOrderBy(['creado'], 'date');
    }

    protected function createViewSolicitudes(string $viewName = 'ListSolicitudFirma'): void
    {
        $estados = [SolicitudFirma::ESTADO_PENDIENTE, SolicitudFirma::ESTADO_VISTA, SolicitudFirma::ESTADO_FIRMADA,
            SolicitudFirma::ESTADO_RECHAZADA, SolicitudFirma::ESTADO_CADUCADA, SolicitudFirma::ESTADO_ANULADA];
        $documentos = [['code' => '', 'description' => '------']];
        $nombres = ['PresupuestoCliente' => 'estimation', 'PedidoCliente' => 'order', 'AlbaranCliente' => 'delivery-note', 'FacturaCliente' => 'invoice'];
        foreach ($nombres as $model => $clave) {
            $documentos[] = ['code' => $model, 'description' => Tools::trans($clave)];
        }
        $documentos[] = ['code' => 'ContratoFirma', 'description' => Tools::trans('fa-contract')];

        $this->addView($viewName, 'SolicitudFirma', 'fa-requests', 'fa-solid fa-signature')
            ->addSearchFields(['codigo', 'doc_titulo', 'nombre', 'email', 'firmante_nombre', 'firmante_nif'])
            ->addOrderBy(['creado'], 'date', 2)
            ->addOrderBy(['firmado'], 'fa-signed-at')
            ->addOrderBy(['caduca'], 'fa-expires')
            ->addFilterPeriod('creado', 'date', 'creado', true)
            ->addFilterSelect('estado', 'status', 'estado', $this->opciones('fa-status-', $estados))
            ->addFilterSelect('doc_model', 'document', 'doc_model', $documentos)
            ->addFilterCheckbox('presencial', 'fa-method-in-person', 'presencial')
            ->addFilterAutocomplete('nick', 'user', 'nick', 'users', 'nick', 'nick');

        $this->setSettings($viewName, 'btnNew', false);
    }

    /**
     * Opciones de un filtro select con claves de traducción.
     *
     * @param string $prefijo
     * @param string[] $valores
     *
     * @return array
     */
    private function opciones(string $prefijo, array $valores): array
    {
        $lista = [['code' => '', 'description' => '------']];
        foreach ($valores as $valor) {
            $lista[] = ['code' => $valor, 'description' => Tools::trans($prefijo . $valor)];
        }

        return $lista;
    }
}
