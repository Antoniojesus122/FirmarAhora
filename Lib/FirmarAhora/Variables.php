<?php
/**
 * This file is part of FirmarAhora plugin for FacturaScripts.
 * Copyright (C) 2026 Antonio Jesús González Domingo <antonio.gonzalez.domingo@proton.me>
 */

namespace FacturaScripts\Plugins\FirmarAhora\Lib\FirmarAhora;

use FacturaScripts\Core\Lib\MyFilesToken;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Model\ContratoFirma;
use FacturaScripts\Dinamic\Model\PlantillaFirma;
use FacturaScripts\Dinamic\Model\SolicitudFirma;

/**
 * Variables de las plantillas de contrato, con la sintaxis {{grupo.campo}}.
 *
 * Las de texto se escapan. {{firmas}} y {{firma_empresa}} generan html (imágenes); en
 * el pdf {{firmas}} se convierte en una marca que el generador sustituye por los
 * bloques de firma dibujados con el motor pdf.
 */
class Variables
{
    /**
     * @var string Atributo de las imágenes que inserta el plugin (logo y firma de la empresa).
     * El html del usuario se limpia antes y no puede traerlo, así que en el pdf sólo se
     * cargan del disco las imágenes que lo llevan.
     */
    const MARCA_IMAGEN = 'data-fa-img="1"';

    /** @var string Marca que deja {{firmas}} en el html del pdf. */
    const MARCA_FIRMAS = '<div data-fa-firmas="1"></div>';

    /**
     * Lista de variables disponibles, para la ayuda del editor.
     *
     * @return string[]
     */
    public static function disponibles(): array
    {
        $names = array_keys(self::textos(new ContratoFirma()));
        $list = ['{{firmas}}', '{{firma_empresa}}'];
        foreach ($names as $name) {
            $list[] = '{{' . $name . '}}';
        }

        return $list;
    }

    /**
     * Valor del atributo src para una imagen de MyFiles.
     *
     * @param string $path Ruta relativa a la raíz de la instalación.
     * @param bool $paraPdf
     *
     * @return string
     */
    public static function srcImagen(string $path, bool $paraPdf): string
    {
        $src = $paraPdf ?
            FS_FOLDER . '/' . $path :
            Tools::config('route') . '/' . MyFilesToken::getUrl($path, true);

        return htmlspecialchars($src, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Sustituye las variables de un html ya limpio.
     *
     * @param string $html
     * @param ContratoFirma $contrato
     * @param bool $paraPdf
     *
     * @return string
     */
    public static function sustituir(string $html, ContratoFirma $contrato, bool $paraPdf): string
    {
        $pares = [];
        foreach (self::textos($contrato) as $name => $value) {
            $pares['{{' . $name . '}}'] = htmlspecialchars(Tools::fixHtml((string)$value), ENT_QUOTES, 'UTF-8');
        }

        $firmaEmpresa = PlantillaFirma::imagen($contrato->getPlantilla()->idfirmaempresa);
        $pares['{{firma_empresa}}'] = $firmaEmpresa ?
            '<img ' . self::MARCA_IMAGEN . ' src="' . self::srcImagen($firmaEmpresa->path, $paraPdf) . '" alt="" style="max-height:90px;max-width:220px;">' :
            '';

        $pares['{{firmas}}'] = $paraPdf ? self::MARCA_FIRMAS : self::bloqueFirmasHtml($contrato->getSolicitudes());

        // las llaves pueden venir con espacios: {{ cliente.nombre }}
        $html = preg_replace('/\{\{\s*([a-z_.]+)\s*\}\}/i', '{{$1}}', $html);
        return strtr($html, $pares);
    }

    /**
     * Bloques de firma en html, para la vista previa y la página pública.
     *
     * @param SolicitudFirma[] $solicitudes
     *
     * @return string
     */
    public static function bloqueFirmasHtml(array $solicitudes): string
    {
        $celdas = '';
        foreach ($solicitudes as $solicitud) {
            if (in_array($solicitud->estado, [SolicitudFirma::ESTADO_ANULADA, SolicitudFirma::ESTADO_CADUCADA], true)) {
                continue;
            }

            $e = function (?string $txt): string {
                return htmlspecialchars(Tools::fixHtml((string)$txt), ENT_QUOTES, 'UTF-8');
            };

            $firmada = $solicitud->estado === SolicitudFirma::ESTADO_FIRMADA && file_exists(FS_FOLDER . '/' . $solicitud->firma_path);
            $imagen = $firmada ?
                '<img src="' . self::srcImagen($solicitud->firma_path, false) . '" alt="" style="max-height:80px;max-width:200px;">' :
                '<span style="color:#999;">' . $e(Tools::trans('fa-pending-signature')) . '</span>';
            $pie = $firmada ?
                $e($solicitud->firmante_nombre) . ($solicitud->firmante_nif ? ' · ' . $e($solicitud->firmante_nif) : '') . '<br>' . $e($solicitud->firmado) :
                $e($solicitud->nombre);

            $celdas .= '<td style="width:33%;vertical-align:bottom;text-align:center;padding:8px;">'
                . '<div style="font-weight:bold;font-size:90%;">' . $e($solicitud->rol ?: Tools::trans('fa-signer')) . '</div>'
                . '<div style="min-height:80px;display:flex;align-items:flex-end;justify-content:center;">' . $imagen . '</div>'
                . '<div style="border-top:1px solid #999;font-size:85%;padding-top:4px;">' . $pie . '</div>'
                . '</td>';
        }

        return empty($celdas) ? '' : '<table style="width:100%;margin-top:16px;"><tr>' . $celdas . '</tr></table>';
    }

    /**
     * Variables de texto con sus valores.
     *
     * @param ContratoFirma $contrato
     *
     * @return array
     */
    private static function textos(ContratoFirma $contrato): array
    {
        $empresa = $contrato->getEmpresa();
        $cliente = $contrato->getCliente();
        $contacto = $contrato->getContacto();
        $proveedor = $contrato->getProveedor();

        $vars = [
            'hoy' => Tools::date(),
            'contrato.titulo' => $contrato->titulo,
            'contrato.numero' => $contrato->id,
            'contrato.fecha' => empty($contrato->creado) ? '' : Tools::date($contrato->creado),
            'empresa.nombre' => $empresa->nombre,
            'empresa.cifnif' => $empresa->cifnif,
            'empresa.direccion' => self::direccion($empresa),
            'empresa.email' => $empresa->email,
            'empresa.telefono' => $empresa->telefono1,
            'cliente.nombre' => $cliente->nombre,
            'cliente.razonsocial' => $cliente->razonsocial,
            'cliente.cifnif' => $cliente->cifnif,
            'cliente.direccion' => $cliente->exists() ? self::direccion($cliente->getDefaultAddress()) : '',
            'cliente.email' => $cliente->email,
            'cliente.telefono' => $cliente->telefono1,
            'contacto.nombre' => trim($contacto->nombre . ' ' . $contacto->apellidos),
            'contacto.cifnif' => $contacto->cifnif,
            'contacto.direccion' => $contacto->exists() ? self::direccion($contacto) : '',
            'contacto.email' => $contacto->email,
            'contacto.telefono' => $contacto->telefono1,
            'proveedor.nombre' => $proveedor->nombre,
            'proveedor.razonsocial' => $proveedor->razonsocial,
            'proveedor.cifnif' => $proveedor->cifnif,
            'proveedor.direccion' => $proveedor->exists() ? self::direccion($proveedor->getDefaultAddress()) : '',
        ];

        foreach (['presupuesto' => $contrato->getPresupuesto(), 'factura' => $contrato->getFactura()] as $prefix => $doc) {
            $vars[$prefix . '.codigo'] = $doc->exists() ? $doc->codigo : '';
            $vars[$prefix . '.fecha'] = $doc->exists() ? $doc->fecha : '';
            $vars[$prefix . '.total'] = $doc->exists() ? Tools::money($doc->total, $doc->coddivisa) : '';
        }

        return $vars;
    }

    /**
     * Dirección en una línea.
     *
     * @param object $origen Modelo con direccion, codpostal, ciudad y provincia.
     *
     * @return string
     */
    private static function direccion($origen): string
    {
        $partes = [$origen->direccion ?? '', trim(($origen->codpostal ?? '') . ' ' . ($origen->ciudad ?? '')), $origen->provincia ?? ''];
        return implode(', ', array_filter($partes));
    }
}
