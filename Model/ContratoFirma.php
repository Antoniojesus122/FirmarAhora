<?php
/**
 * This file is part of FirmarAhora plugin for FacturaScripts.
 * Copyright (C) 2026 Antonio Jesús González Domingo <antonio.gonzalez.domingo@proton.me>
 */

namespace FacturaScripts\Plugins\FirmarAhora\Model;

use FacturaScripts\Core\DataSrc\Empresas;
use FacturaScripts\Core\Session;
use FacturaScripts\Core\Template\ModelClass;
use FacturaScripts\Core\Template\ModelTrait;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Lib\FirmarAhora\HtmlSeguro;
use FacturaScripts\Dinamic\Lib\FirmarAhora\Variables;
use FacturaScripts\Dinamic\Model\Cliente;
use FacturaScripts\Dinamic\Model\Contacto;
use FacturaScripts\Dinamic\Model\Empresa;
use FacturaScripts\Dinamic\Model\FacturaCliente;
use FacturaScripts\Dinamic\Model\PlantillaFirma;
use FacturaScripts\Dinamic\Model\PresupuestoCliente;
use FacturaScripts\Dinamic\Model\Proveedor;
use FacturaScripts\Dinamic\Model\SolicitudFirma;
use FacturaScripts\Dinamic\Model\User;

/**
 * Contrato o documento propio para firmar. Se crea desde una plantilla y guarda una
 * copia de su texto: una vez firmado por alguien, el texto ya no se puede cambiar.
 */
class ContratoFirma extends ModelClass
{
    use ModelTrait;

    const ESTADO_BORRADOR = 'borrador';
    const ESTADO_ENVIADO = 'enviado';
    const ESTADO_FIRMADO = 'firmado';
    const ESTADO_PARCIAL = 'parcial';
    const ESTADO_RECHAZADO = 'rechazado';

    /** @var string */
    public $codcliente;

    /** @var string */
    public $codproveedor;

    /** @var string */
    public $creado;

    /** @var string Html del contrato, escapado con Tools::noHtml(). */
    public $cuerpo;

    /** @var string Email por defecto para las solicitudes de firma. */
    public $email;

    /** @var string Resumen de las solicitudes: borrador, enviado, parcial, firmado o rechazado. */
    public $estado;

    /** @var int */
    public $id;

    /** @var int */
    public $idcontacto;

    /** @var int */
    public $idempresa;

    /** @var int */
    public $idfactura;

    /** @var int */
    public $idplantilla;

    /** @var int */
    public $idpresupuesto;

    /** @var string */
    public $nick;

    /** @var string */
    public $observaciones;

    /** @var string */
    public $titulo;

    /** @var bool True mientras actualizarEstado() guarda, para no bloquear el cambio de estado. */
    private $actualizandoEstado = false;

    /** @var array Caché de modelos relacionados. */
    private $relacionados = [];

    /**
     * Recalcula el estado a partir de sus solicitudes de firma y lo guarda si cambia.
     */
    public function actualizarEstado(): void
    {
        $firmadas = $abiertas = $rechazadas = 0;
        foreach ($this->getSolicitudes() as $solicitud) {
            if ($solicitud->estado === SolicitudFirma::ESTADO_FIRMADA) {
                $firmadas++;
            } elseif ($solicitud->estado === SolicitudFirma::ESTADO_RECHAZADA) {
                $rechazadas++;
            } elseif ($solicitud->estaAbierta()) {
                $abiertas++;
            }
        }

        if ($rechazadas > 0) {
            $estado = self::ESTADO_RECHAZADO;
        } elseif ($firmadas > 0 && $abiertas === 0) {
            $estado = self::ESTADO_FIRMADO;
        } elseif ($firmadas > 0) {
            $estado = self::ESTADO_PARCIAL;
        } elseif ($abiertas > 0) {
            $estado = self::ESTADO_ENVIADO;
        } else {
            $estado = self::ESTADO_BORRADOR;
        }

        if ($estado !== $this->estado) {
            $this->estado = $estado;
            $this->actualizandoEstado = true;
            $this->save();
            $this->actualizandoEstado = false;
        }
    }

    public function clear(): void
    {
        parent::clear();
        $this->creado = Tools::dateTime();
        $this->estado = self::ESTADO_BORRADOR;
        $this->idempresa = Tools::settings('default', 'idempresa');
        $this->nick = Session::user()->nick ?? null;
        $this->relacionados = [];
    }

    /**
     * Borra el contrato y sus solicitudes de firma. Un contrato firmado no se puede borrar:
     * es la prueba de lo que se firmó.
     *
     * @return bool
     */
    public function delete(): bool
    {
        if ($this->tieneFirmas()) {
            Tools::log()->warning('fa-signed-cannot-delete');
            return false;
        }

        $solicitudes = $this->getSolicitudes();
        if (false === parent::delete()) {
            return false;
        }

        foreach ($solicitudes as $solicitud) {
            $solicitud->delete();
        }

        return true;
    }

    public function getCliente(): Cliente
    {
        return $this->relacionado('Cliente', $this->codcliente);
    }

    public function getContacto(): Contacto
    {
        return $this->relacionado('Contacto', $this->idcontacto);
    }

    public function getEmpresa(): Empresa
    {
        return empty($this->idempresa) ? Empresas::default() : Empresas::get($this->idempresa);
    }

    public function getFactura(): FacturaCliente
    {
        return $this->relacionado('FacturaCliente', $this->idfactura);
    }

    public function getPlantilla(): PlantillaFirma
    {
        return $this->relacionado('PlantillaFirma', $this->idplantilla);
    }

    public function getPresupuesto(): PresupuestoCliente
    {
        return $this->relacionado('PresupuestoCliente', $this->idpresupuesto);
    }

    public function getProveedor(): Proveedor
    {
        return $this->relacionado('Proveedor', $this->codproveedor);
    }

    /**
     * @return SolicitudFirma[]
     */
    public function getSolicitudes(): array
    {
        return empty($this->id) ? [] : SolicitudFirma::delDocumento($this->modelClassName(), (string)$this->id);
    }

    /**
     * Alguien ya lo ha firmado: el texto queda bloqueado.
     *
     * @return bool
     */
    public function tieneFirmas(): bool
    {
        foreach ($this->getSolicitudes() as $solicitud) {
            if ($solicitud->estado === SolicitudFirma::ESTADO_FIRMADA) {
                return true;
            }
        }

        return false;
    }

    public function install(): string
    {
        new PlantillaFirma();
        new Cliente();
        new Proveedor();
        new Contacto();
        new PresupuestoCliente();
        new FacturaCliente();
        new User();
        return parent::install();
    }

    public function primaryDescriptionColumn(): string
    {
        return 'titulo';
    }

    public static function primaryColumn(): string
    {
        return 'id';
    }

    /**
     * Html del contrato listo para mostrar: limpio, con las variables sustituidas y con
     * el logo de la plantilla encima.
     *
     * @param bool $paraPdf Las imágenes con ruta en disco en lugar de url.
     *
     * @return string
     */
    public function renderHtml(bool $paraPdf = false): string
    {
        $html = HtmlSeguro::limpiar(Tools::fixHtml((string)$this->cuerpo));
        $html = Variables::sustituir($html, $this, $paraPdf);

        $logo = PlantillaFirma::imagen($this->getPlantilla()->idlogo);
        if ($logo) {
            $src = Variables::srcImagen($logo->path, $paraPdf);
            $html = '<p><img ' . Variables::MARCA_IMAGEN . ' src="' . $src . '" alt="" style="max-height:70px;max-width:240px;"></p>' . $html;
        }

        return $html;
    }

    public static function tableName(): string
    {
        return 'fa_contratos';
    }

    public function test(): bool
    {
        $this->relacionados = [];
        if (false === $this->actualizandoEstado) {
            // el estado sólo lo cambia actualizarEstado()
            $this->estado = $this->exists() ? ($this->getOriginal('estado') ?: self::ESTADO_BORRADOR) : self::ESTADO_BORRADOR;
        }

        $this->titulo = Tools::noHtml(trim((string)$this->titulo));
        $this->observaciones = Tools::noHtml((string)$this->observaciones);
        $this->cuerpo = Tools::noHtml((string)$this->cuerpo);
        $this->email = strtolower(trim((string)$this->email));

        foreach (['codcliente', 'codproveedor', 'idcontacto', 'idplantilla', 'idpresupuesto', 'idfactura'] as $field) {
            if (empty($this->{$field})) {
                $this->{$field} = null;
            }
        }

        if (empty($this->titulo) && false === empty($this->idplantilla)) {
            $this->titulo = $this->getPlantilla()->nombre;
        }
        if (empty($this->titulo)) {
            Tools::log()->warning('field-can-not-be-null', ['%fieldName%' => 'titulo', '%tableName%' => static::tableName()]);
            return false;
        }

        if (false === empty($this->email) && false === filter_var($this->email, FILTER_VALIDATE_EMAIL)) {
            Tools::log()->warning('not-valid-email', ['%email%' => $this->email]);
            return false;
        }

        // lo firmado no se toca
        if ($this->exists() && false === $this->actualizandoEstado && $this->hasChanged('cuerpo') && $this->tieneFirmas()) {
            Tools::log()->warning('fa-signed-text-locked');
            return false;
        }

        return parent::test();
    }

    public function url(string $type = 'auto', string $list = 'List'): string
    {
        return $type === 'list' ? 'ListSolicitudFirma?activetab=ListContratoFirma' : parent::url($type, $list);
    }

    /**
     * Carga con caché un modelo relacionado.
     *
     * @param string $modelName
     * @param mixed $code
     *
     * @return object
     */
    private function relacionado(string $modelName, $code)
    {
        $key = $modelName . '|' . $code;
        if (false === isset($this->relacionados[$key])) {
            $className = '\\FacturaScripts\\Dinamic\\Model\\' . $modelName;
            $model = new $className();
            if (false === empty($code)) {
                $model->load($code);
            }
            $this->relacionados[$key] = $model;
        }

        return $this->relacionados[$key];
    }
}
