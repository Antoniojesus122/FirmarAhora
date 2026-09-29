<?php
/**
 * This file is part of FirmarAhora plugin for FacturaScripts.
 * Copyright (C) 2026 Antonio Jesús González Domingo <antonio.gonzalez.domingo@proton.me>
 */

namespace FacturaScripts\Plugins\FirmarAhora\Lib\FirmarAhora;

use FacturaScripts\Core\Model\Base\BusinessDocument;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Model\ContratoFirma;
use FacturaScripts\Dinamic\Model\SolicitudFirma;
use FacturaScripts\Plugins\FirmarAhora\Lib\Export\PDFExport;
use Throwable;

/**
 * Genera el pdf de un documento con sus firmas y, al firmar, la copia sellada: ese pdf
 * más la página de certificado de evidencias, guardado en disco con su huella SHA-256.
 *
 * Se usa siempre el PDFExport de este plugin (y no el de Dinamic) para que la copia
 * sellada tenga el mismo formato aunque otro plugin cambie el pdf de los documentos.
 */
class Sellador
{
    const CARPETA_SELLADOS = 'MyFiles/FirmarAhora/sellados/';

    /**
     * Pdf del documento con las firmas hechas hasta ahora.
     *
     * @param object $documento Documento de venta o ContratoFirma.
     * @param bool $certificado Añadir la página de certificado de evidencias.
     *
     * @return string Contenido del pdf.
     */
    public static function pdf($documento, bool $certificado): string
    {
        // el documento firmado queda en el idioma de la empresa, aunque la página se vea en otro
        return Idioma::con(null, function () use ($documento, $certificado) {
            return self::generar($documento, $certificado);
        });
    }

    /**
     * Guarda la copia sellada de una solicitud recién firmada.
     *
     * @param SolicitudFirma $solicitud
     * @param object $documento
     *
     * @return bool
     */
    public static function sellar(SolicitudFirma $solicitud, $documento): bool
    {
        $contenido = self::pdf($documento, true);
        if (empty($contenido) || false === Tools::folderCheckOrCreate(FS_FOLDER . '/' . self::CARPETA_SELLADOS)) {
            Tools::log()->error('fa-file-error');
            return false;
        }

        $ruta = self::CARPETA_SELLADOS . $solicitud->token . '.pdf';
        if (false === file_put_contents(FS_FOLDER . '/' . $ruta, $contenido)) {
            Tools::log()->error('fa-file-error');
            return false;
        }

        $solicitud->sellado_path = $ruta;
        $solicitud->hash_sellado = hash('sha256', $contenido);
        return $solicitud->save();
    }

    /**
     * Título legible del documento: "Factura F2026A12", "Contrato de mantenimiento".
     *
     * @param object $documento
     *
     * @return string
     */
    public static function titulo($documento): string
    {
        if ($documento instanceof ContratoFirma) {
            return Tools::fixHtml((string)$documento->titulo);
        }

        return Tools::trans($documento->modelClassName() . '-min') . ' ' . $documento->primaryDescription();
    }

    /**
     * Nombre de archivo seguro para el pdf del documento.
     *
     * @param object $documento
     * @param string $sufijo
     *
     * @return string
     */
    public static function nombreArchivo($documento, string $sufijo = ''): string
    {
        $nombre = preg_replace('/[^A-Za-z0-9_\-]/', '', str_replace(' ', '_', self::titulo($documento)));
        return (empty($nombre) ? 'documento' : $nombre) . $sufijo . '.pdf';
    }

    /**
     * @param object $documento
     * @param bool $certificado
     *
     * @return string Contenido del pdf.
     */
    private static function generar($documento, bool $certificado): string
    {
        Tools::folderCheckOrCreate(FS_FOLDER . '/MyFiles/Cache');

        // cualquier aviso de PHP que se imprimiera acabaría dentro del pdf
        ob_start();
        try {
            $export = new PDFExport();
            $export->forzarFirmas();
            $export->newDoc(self::titulo($documento), 0, '');

            if ($documento instanceof ContratoFirma) {
                $export->addContratoPage($documento);
            } elseif ($documento instanceof BusinessDocument) {
                $export->addBusinessDocPage($documento);
            }

            if ($certificado) {
                $export->addCertificadoPage($documento);
            }

            return (string)$export->getDoc();
        } catch (Throwable $exc) {
            Tools::log()->error('fa-pdf-error', ['%error%' => $exc->getMessage()]);
            return '';
        } finally {
            ob_end_clean();
        }
    }
}
