<?php
/**
 * This file is part of FirmarAhora plugin for FacturaScripts.
 * Copyright (C) 2026 Antonio Jesús González Domingo <antonio.gonzalez.domingo@proton.me>
 */

namespace FacturaScripts\Plugins\FirmarAhora\Model;

use FacturaScripts\Core\Session;
use FacturaScripts\Core\Template\ModelClass;
use FacturaScripts\Core\Template\ModelTrait;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Model\AttachedFile;
use FacturaScripts\Dinamic\Model\ContratoFirma;
use FacturaScripts\Dinamic\Model\User;

/**
 * Plantilla de contrato: texto con variables ({{cliente.nombre}}, {{firmas}}...),
 * logo, imagen de la firma de la empresa y valores por defecto para los envíos.
 */
class PlantillaFirma extends ModelClass
{
    use ModelTrait;

    /** @var string Asunto por defecto del email de invitación. */
    public $asunto;

    /** @var string */
    public $creado;

    /** @var string Html de la plantilla, escapado con Tools::noHtml(). */
    public $cuerpo;

    /** @var int Días que el enlace admite la firma. Vacío: el de los ajustes. */
    public $dias_validez;

    /** @var int */
    public $id;

    /** @var int */
    public $idfirmaempresa;

    /** @var int */
    public $idlogo;

    /** @var string Texto por defecto del email de invitación. */
    public $mensaje;

    /** @var string */
    public $nick;

    /** @var string */
    public $nombre;

    /** @var bool */
    public $requiere_otp;

    public function clear(): void
    {
        parent::clear();
        $this->creado = Tools::dateTime();
        $this->nick = Session::user()->nick ?? null;
        $this->requiere_otp = (bool)Tools::settings('firmarahora', 'otp_defecto', false);
    }

    /**
     * Crea un contrato con una copia del texto de la plantilla.
     *
     * @param array $datos Campos del contrato (codcliente, idcontacto, email...).
     *
     * @return ?ContratoFirma El contrato guardado, o null si no se pudo guardar.
     */
    public function crearContrato(array $datos = []): ?ContratoFirma
    {
        $contrato = new ContratoFirma();
        foreach ($datos as $key => $value) {
            $contrato->{$key} = $value;
        }

        $contrato->idplantilla = $this->id;
        $contrato->titulo = $contrato->titulo ?: $this->nombre;
        $contrato->cuerpo = $this->cuerpo;

        return $contrato->save() ? $contrato : null;
    }

    /**
     * Imagen de un archivo adjunto, si existe y es una imagen.
     *
     * @param mixed $idfile
     *
     * @return ?AttachedFile
     */
    public static function imagen($idfile): ?AttachedFile
    {
        if (empty($idfile)) {
            return null;
        }

        $file = new AttachedFile();
        if (false === $file->load($idfile) || false === $file->isImage() || false === file_exists($file->getFullPath())) {
            return null;
        }

        return $file;
    }

    public function install(): string
    {
        new AttachedFile();
        new User();
        return parent::install();
    }

    public function primaryDescriptionColumn(): string
    {
        return 'nombre';
    }

    public static function primaryColumn(): string
    {
        return 'id';
    }

    public static function tableName(): string
    {
        return 'fa_plantillas';
    }

    public function test(): bool
    {
        $this->nombre = Tools::noHtml(trim((string)$this->nombre));
        $this->asunto = Tools::noHtml(trim((string)$this->asunto));
        $this->mensaje = Tools::noHtml((string)$this->mensaje);
        $this->cuerpo = Tools::noHtml((string)$this->cuerpo);

        foreach (['idlogo', 'idfirmaempresa', 'dias_validez'] as $field) {
            if (empty($this->{$field})) {
                $this->{$field} = null;
            }
        }

        if (empty($this->nombre)) {
            Tools::log()->warning('field-can-not-be-null', ['%fieldName%' => 'nombre', '%tableName%' => static::tableName()]);
            return false;
        }

        return parent::test();
    }

    public function url(string $type = 'auto', string $list = 'List'): string
    {
        return $type === 'list' ? 'ListSolicitudFirma?activetab=ListPlantillaFirma' : parent::url($type, $list);
    }
}
