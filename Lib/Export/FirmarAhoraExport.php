<?php
/**
 * This file is part of FirmarAhora plugin for FacturaScripts.
 * Copyright (C) 2026 Antonio Jesús González Domingo <antonio.gonzalez.domingo@proton.me>
 */

namespace FacturaScripts\Plugins\FirmarAhora\Lib\Export;

use FacturaScripts\Core\Lib\Export\PDFExport;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Lib\FirmarAhora\HtmlPdf;
use FacturaScripts\Dinamic\Lib\FirmarAhora\Sellador;
use FacturaScripts\Dinamic\Model\ContratoFirma;
use FacturaScripts\Dinamic\Model\SolicitudFirma;

/**
 * Pdf de documentos con las firmas de FirmarAhora. Es la opción "PDF con firmas" del menú
 * Imprimir y el formato de las copias selladas. No sustituye al PDFExport del núcleo, así
 * que convive con otros plugins que cambien el pdf de los documentos.
 *
 * - En presupuestos, pedidos, albaranes y facturas, las firmas van al pie, abajo a la
 *   derecha: debajo de los totales si caben; en documentos de una página, en el hueco
 *   que deja el núcleo encima de los totales; si no, en la página siguiente.
 * - addContratoPage() imprime un contrato (su html) con las firmas donde esté {{firmas}}.
 * - addCertificadoPage() añade el certificado de evidencias de la copia sellada.
 */
class FirmarAhoraExport extends PDFExport
{
    /** @var int Ancho de cada bloque de firma, en puntos. */
    const FIRMA_ANCHO = 165;

    /** @var int Alto máximo de la imagen de firma, en puntos. */
    const FIRMA_IMAGEN = 55;

    /** @var int Separación alrededor de los bloques de firma. */
    const FIRMA_MARGEN = 12;

    /** @var ?float Altura del cursor antes de los totales, medida mientras se pinta el pie. */
    private $pieY = null;

    /**
     * Certificado de evidencias: datos de cada firmante, huellas y registro de eventos.
     *
     * @param object $documento
     *
     * @return bool
     */
    public function addCertificadoPage($documento): bool
    {
        if (null === $this->pdf) {
            $this->newPage();
        } else {
            $this->pdf->ezNewPage();
        }

        $solicitudes = SolicitudFirma::delDocumento($documento->modelClassName(), (string)$documento->primaryColumnValue());

        $this->pdf->ezText('<b>' . Tools::trans('fa-certificate-title') . '</b>', 15);
        $this->pdf->ezText('', 4);
        $this->pdf->ezText(Tools::trans('fa-certificate-intro', ['%date%' => Tools::dateTime()]), self::FONT_SIZE, ['justification' => 'full']);
        $this->pdf->ezText('', 8);

        $this->tablaDatos([
            Tools::trans('document') => Sellador::titulo($documento),
            Tools::trans('fa-signers') => (string)count($solicitudes),
        ]);

        $eventos = [];
        foreach ($solicitudes as $num => $solicitud) {
            $this->pdf->ezText('', 6);
            $titulo = ($num + 1) . '. ' . Tools::fixHtml($solicitud->rol ?: Tools::trans('fa-signer')) . ' · ' . Tools::trans('fa-status-' . $solicitud->estado);
            $this->pdf->ezText('<b>' . $titulo . '</b>', 11);

            $datos = [
                Tools::trans('fa-invited') => Tools::fixHtml(trim($solicitud->nombre . ' ' . $solicitud->email)),
                Tools::trans('fa-verification-code') => $solicitud->codigo,
            ];
            if ($solicitud->estado === SolicitudFirma::ESTADO_FIRMADA) {
                $datos += [
                    Tools::trans('fa-signed-by') => Tools::fixHtml(trim($solicitud->firmante_nombre . ' · ' . $solicitud->firmante_nif, ' ·')),
                    Tools::trans('fa-signed-at') => $solicitud->firmado,
                    Tools::trans('fa-method') => Tools::trans($solicitud->presencial ? 'fa-method-in-person' : 'fa-method-link'),
                    Tools::trans('fa-otp') => Tools::trans($solicitud->otp_verificado ? 'yes' : 'no'),
                    Tools::trans('fa-signature-type') => Tools::trans('fa-kind-' . $solicitud->firma_tipo),
                    'IP' => $solicitud->ip ?: '-',
                    Tools::trans('fa-browser') => Tools::fixHtml((string)$solicitud->user_agent) ?: '-',
                    Tools::trans('fa-hash-viewed') => (string)$solicitud->hash_original ?: '-',
                ];
                if (false === empty($solicitud->ip_proxy)) {
                    $datos[Tools::trans('fa-ip-proxy')] = Tools::fixHtml((string)$solicitud->ip_proxy);
                }
                if (false === empty($solicitud->origen)) {
                    $datos[Tools::trans('fa-signed-document')] = Tools::fixHtml((string)$solicitud->origen);
                }
                if ($solicitud->ubicacion() !== '') {
                    $datos[Tools::trans('fa-location')] = $solicitud->ubicacion();
                }
            } elseif ($solicitud->estado === SolicitudFirma::ESTADO_RECHAZADA) {
                $datos[Tools::trans('fa-reason')] = Tools::fixHtml((string)$solicitud->motivo_rechazo);
            }
            $this->tablaDatos($datos);

            foreach ($solicitud->getEventos() as $evento) {
                $eventos[] = [
                    'fecha' => $evento->fecha,
                    'firmante' => ($num + 1) . '. ' . Tools::fixHtml((string)$solicitud->rol),
                    'evento' => $evento->descripcion(),
                    'ip' => (string)$evento->ip,
                ];
            }
        }

        if ($eventos) {
            usort($eventos, function ($a, $b) {
                return strtotime($a['fecha']) <=> strtotime($b['fecha']);
            });
            $this->pdf->ezText('', 8);
            $this->pdf->ezText('<b>' . Tools::trans('fa-audit-trail') . '</b>', 11);
            $this->pdf->ezTable($eventos, [
                'fecha' => Tools::trans('date'),
                'firmante' => Tools::trans('fa-signer'),
                'evento' => Tools::trans('fa-event'),
                'ip' => 'IP',
            ], '', [
                'fontSize' => self::FONT_SIZE - 1,
                'shaded' => 1,
                'shadeCol' => [0.96, 0.96, 0.96],
                'width' => $this->tableWidth,
            ]);
        }

        $this->pdf->ezText('', 10);
        $this->pdf->ezText(Tools::trans('fa-certificate-footer'), self::FONT_SIZE - 1, ['justification' => 'full']);
        return true;
    }

    /**
     * Página de un contrato: cabecera de la empresa, título, texto y firmas.
     *
     * @param ContratoFirma $contrato
     *
     * @return bool
     */
    public function addContratoPage(ContratoFirma $contrato): bool
    {
        if (null === $this->pdf) {
            $this->newPage();
        } else {
            $this->pdf->ezNewPage();
            $this->insertedHeader = false;
        }

        $this->insertHeader($contrato->idempresa);
        $this->pdf->ezText('', 10);
        $this->pdf->ezText('<b>' . Tools::fixHtml((string)$contrato->titulo) . '</b>', 15);
        $this->pdf->ezText('', 8);

        $solicitudes = array_filter($contrato->getSolicitudes(), function ($solicitud) {
            return false === in_array($solicitud->estado, [SolicitudFirma::ESTADO_ANULADA, SolicitudFirma::ESTADO_CADUCADA], true);
        });

        $html = new HtmlPdf($this->pdf, function () use ($solicitudes) {
            $this->dibujarFirmas($solicitudes, true);
        });
        $html->imprimir($contrato->renderHtml(true));

        if (false === $html->firmasDibujadas() && $solicitudes) {
            $this->dibujarFirmas($solicitudes, true);
        }

        return true;
    }

    /**
     * Mientras se pinta el pie, apunta la altura del cursor en cada salto: la última es
     * la de justo antes de los totales.
     *
     * @param string $orientation
     * @param bool $forceNewPage
     */
    public function newPage(string $orientation = 'portrait', bool $forceNewPage = false)
    {
        parent::newPage($orientation, $forceNewPage);

        if (null !== $this->pieY) {
            $this->pieY = $this->pdf->y;
        }
    }

    /**
     * Pie del documento del núcleo y, a continuación, las firmas.
     *
     * @param mixed $model
     */
    protected function insertBusinessDocFooter($model)
    {
        $firmadas = array_values(array_filter(
            SolicitudFirma::delDocumento($model->modelClassName(), (string)$model->primaryColumnValue()),
            function ($solicitud) {
                return $solicitud->estado === SolicitudFirma::ESTADO_FIRMADA;
            }
        ));

        if (empty($firmadas)) {
            parent::insertBusinessDocFooter($model);
            return;
        }

        $this->pieY = 0.0;
        parent::insertBusinessDocFooter($model);
        $hueco = $this->huecoSobreTotales($model, $this->altoFirmas(count($firmadas)));
        $this->pieY = null;

        $minimo = $this->pdf->ez['bottomMargin'] + self::FIRMA_MARGEN;
        if ($this->pdf->y - self::FIRMA_MARGEN - $this->altoFirmas(count($firmadas)) >= $minimo) {
            $this->dibujarFirmas($firmadas, false);
            return;
        }

        if (null !== $hueco) {
            $abajo = $this->pdf->y;
            $this->pdf->y = $hueco;
            $this->dibujarFirmas($firmadas, false);
            $this->pdf->y = $abajo;
            return;
        }

        $this->pdf->ezNewPage();
        $this->dibujarFirmas($firmadas, false);
    }

    /**
     * Alto de las filas de bloques necesarias para un número de firmas.
     *
     * @param int $num
     *
     * @return float
     */
    private function altoFirmas(int $num): float
    {
        $filas = (int)ceil($num / $this->firmasPorFila());
        return $filas * $this->altoFila() + self::FIRMA_MARGEN;
    }

    private function altoFila(): float
    {
        return self::FONT_SIZE + 6 + self::FIRMA_IMAGEN + 4 + 2 * (self::FONT_SIZE + 2) + 10;
    }

    /**
     * Dibuja un bloque de firma con su borde superior en $top.
     *
     * @param SolicitudFirma $solicitud
     * @param float $x
     * @param float $top
     */
    private function bloque(SolicitudFirma $solicitud, float $x, float $top): void
    {
        $centro = $x + self::FIRMA_ANCHO / 2;
        $y = $top - self::FONT_SIZE;
        $rol = Tools::fixHtml((string)$solicitud->rol) ?: Tools::trans('fa-signer');
        $this->pdf->addText($centro, $y, self::FONT_SIZE, '<b>' . $this->recortar($rol, self::FONT_SIZE) . '</b>', 0, 'center');

        $y -= 6 + self::FIRMA_IMAGEN;
        $ruta = FS_FOLDER . '/' . $solicitud->firma_path;
        $firmada = $solicitud->estado === SolicitudFirma::ESTADO_FIRMADA && is_file($ruta);
        $size = $firmada ? @getimagesize($ruta) : false;
        if ($size && $size[0] > 0 && $size[1] > 0) {
            $escala = min((self::FIRMA_ANCHO - 10) / $size[0], self::FIRMA_IMAGEN / $size[1]);
            $ancho = $size[0] * $escala;
            $alto = $size[1] * $escala;
            $this->pdf->addPngFromFile($ruta, $centro - $ancho / 2, $y, $ancho, $alto);
        }

        $y -= 4;
        $this->pdf->setLineStyle(0.5);
        $this->pdf->line($x + 5, $y, $x + self::FIRMA_ANCHO - 5, $y);

        $y -= self::FONT_SIZE + 2;
        $nombre = $firmada ?
            trim(Tools::fixHtml((string)$solicitud->firmante_nombre) . ' · ' . $solicitud->firmante_nif, ' ·') :
            Tools::fixHtml((string)$solicitud->nombre);
        $this->pdf->addText($centro, $y, self::FONT_SIZE - 1, $this->recortar($nombre, self::FONT_SIZE - 1), 0, 'center');

        $y -= self::FONT_SIZE + 2;
        // una firma copiada al convertir indica el documento sobre el que se hizo
        $linea = Tools::trans('fa-pending-signature');
        if ($firmada) {
            $linea = empty($solicitud->origen) ?
                $solicitud->firmado . ' · ' . $solicitud->codigo :
                Tools::trans('fa-signed-on-origin', ['%doc%' => Tools::fixHtml((string)$solicitud->origen)]);
        }
        $this->pdf->addText($centro, $y, self::FONT_SIZE - 2, $this->recortar($linea, self::FONT_SIZE - 2), 0, 'center');
    }

    /**
     * Dibuja los bloques de firma desde el cursor actual, en filas alineadas a la derecha,
     * y deja el cursor debajo. Cada fila salta de página si no cabe.
     *
     * @param SolicitudFirma[] $solicitudes
     * @param bool $conPendientes Dibujar también las abiertas, con el hueco vacío.
     */
    private function dibujarFirmas(array $solicitudes, bool $conPendientes): void
    {
        $lista = array_values(array_filter($solicitudes, function ($solicitud) use ($conPendientes) {
            return $solicitud->estado === SolicitudFirma::ESTADO_FIRMADA || ($conPendientes && $solicitud->estaAbierta());
        }));
        if (empty($lista)) {
            return;
        }

        $porFila = $this->firmasPorFila();
        $derecha = $this->pdf->ez['pageWidth'] - $this->pdf->ez['rightMargin'];
        foreach (array_chunk($lista, $porFila) as $fila) {
            if ($this->pdf->y - self::FIRMA_MARGEN - $this->altoFila() < $this->pdf->ez['bottomMargin']) {
                $this->pdf->ezNewPage();
            }

            $top = $this->pdf->y - self::FIRMA_MARGEN;
            $x = $derecha - count($fila) * self::FIRMA_ANCHO;
            foreach ($fila as $solicitud) {
                $this->bloque($solicitud, $x, $top);
                $x += self::FIRMA_ANCHO;
            }

            $this->pdf->y = $top - $this->altoFila();
        }
    }

    private function firmasPorFila(): int
    {
        $ancho = $this->pdf->ez['pageWidth'] - $this->pdf->ez['leftMargin'] - $this->pdf->ez['rightMargin'];
        return max(1, (int)floor($ancho / self::FIRMA_ANCHO));
    }

    /**
     * Altura donde empezarían las firmas en el hueco que deja el núcleo encima de los
     * totales en los documentos de una página, si caben. Repite la condición con la
     * que PDFDocument::insertBusinessDocFooter() baja los totales a INVOICE_TOTALS_Y.
     *
     * @param mixed $model
     * @param float $alto
     *
     * @return ?float
     */
    private function huecoSobreTotales($model, float $alto): ?float
    {
        if (count($this->getTaxesRows($model)) > 1 || $this->pdf->ezPageCount >= 2
            || strlen($this->format->texto ?? '') >= 400 || $this->pieY <= static::INVOICE_TOTALS_Y) {
            return null;
        }

        $top = static::INVOICE_TOTALS_Y + $alto + self::FIRMA_MARGEN;
        return $top <= $this->pieY - self::FIRMA_MARGEN ? $top : null;
    }

    /**
     * Recorta un texto para que quepa en el ancho de un bloque.
     *
     * @param string $texto
     * @param int $tamano
     *
     * @return string
     */
    private function recortar(string $texto, int $tamano): string
    {
        while (mb_strlen($texto) > 4 && $this->pdf->getTextWidth($tamano, $texto) > self::FIRMA_ANCHO - 8) {
            $texto = mb_substr(rtrim($texto, '…'), 0, -1) . '…';
        }

        return $texto;
    }

    /**
     * Tabla de dos columnas etiqueta/valor, sin bordes.
     *
     * @param array $datos
     */
    private function tablaDatos(array $datos): void
    {
        $filas = [];
        foreach ($datos as $clave => $valor) {
            $filas[] = ['k' => '<b>' . $clave . '</b>', 'v' => (string)$valor];
        }

        $this->pdf->ezTable($filas, ['k' => '', 'v' => ''], '', [
            'showHeadings' => 0,
            'shaded' => 0,
            'showLines' => 0,
            'fontSize' => self::FONT_SIZE - 1,
            'width' => $this->tableWidth,
            'cols' => ['k' => ['width' => 150]],
        ]);
    }
}
