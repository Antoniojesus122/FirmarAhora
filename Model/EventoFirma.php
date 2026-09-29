<?php
/**
 * This file is part of FirmarAhora plugin for FacturaScripts.
 * Copyright (C) 2026 Antonio Jesús González Domingo <antonio.gonzalez.domingo@proton.me>
 */

namespace FacturaScripts\Plugins\FirmarAhora\Model;

use FacturaScripts\Core\Template\ModelClass;
use FacturaScripts\Core\Template\ModelTrait;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Model\SolicitudFirma;
use FacturaScripts\Dinamic\Model\User;

/**
 * Evento del registro de auditoría de una solicitud de firma: creada, enviada, abierta,
 * código enviado, código correcto o incorrecto, firmada, rechazada, anulada...
 */
class EventoFirma extends ModelClass
{
    use ModelTrait;

    /** @var string */
    public $detalle;

    /** @var string */
    public $fecha;

    /** @var int */
    public $id;

    /** @var int */
    public $idsolicitud;

    /** @var string */
    public $ip;

    /** @var string */
    public $nick;

    /** @var string */
    public $tipo;

    /** @var string */
    public $user_agent;

    public function clear(): void
    {
        parent::clear();
        $this->fecha = Tools::dateTime();
    }

    /**
     * Nombre del evento, traducido.
     *
     * @return string
     */
    public function descripcion(): string
    {
        return Tools::trans('fa-event-' . $this->tipo);
    }

    public function install(): string
    {
        new SolicitudFirma();
        new User();
        return parent::install();
    }

    public static function primaryColumn(): string
    {
        return 'id';
    }

    public static function tableName(): string
    {
        return 'fa_eventos';
    }

    public function test(): bool
    {
        $this->detalle = Tools::noHtml((string)$this->detalle);
        $this->user_agent = mb_substr((string)$this->user_agent, 0, 255);
        return parent::test();
    }

    public function url(string $type = 'auto', string $list = 'List'): string
    {
        return 'EditSolicitudFirma?code=' . $this->idsolicitud;
    }
}
