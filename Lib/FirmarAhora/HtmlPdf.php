<?php
/**
 * This file is part of FirmarAhora plugin for FacturaScripts.
 * Copyright (C) 2026 Antonio Jesús González Domingo <antonio.gonzalez.domingo@proton.me>
 */

namespace FacturaScripts\Plugins\FirmarAhora\Lib\FirmarAhora;

use Cezpdf;
use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Imprime html sencillo con ezpdf, el motor pdf del núcleo, que no entiende html:
 * títulos, párrafos con su alineación, negritas, cursivas, listas, tablas, líneas e
 * imágenes. La marca de {{firmas}} se entrega a una función externa, que dibuja los
 * bloques de firma en ese punto del texto.
 */
class HtmlPdf
{
    const TAMANO = 10;

    /** @var callable */
    private $alEncontrarFirmas;

    /** @var string Párrafo en construcción, con las marcas <b> e <i> de ezpdf. */
    private $parrafo = '';

    /** @var Cezpdf */
    private $pdf;

    /** @var bool */
    private $firmasDibujadas = false;

    /**
     * @param Cezpdf $pdf
     * @param callable $alEncontrarFirmas Función sin argumentos que dibuja las firmas.
     */
    public function __construct(Cezpdf $pdf, callable $alEncontrarFirmas)
    {
        $this->pdf = $pdf;
        $this->alEncontrarFirmas = $alEncontrarFirmas;
    }

    /**
     * @return bool True si el html tenía la marca de firmas y ya se han dibujado.
     */
    public function firmasDibujadas(): bool
    {
        return $this->firmasDibujadas;
    }

    /**
     * @param string $html Html limpio.
     */
    public function imprimir(string $html): void
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->loadHTML('<?xml encoding="UTF-8"><div id="fa-pdf">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR | LIBXML_NOWARNING);
        $raiz = $dom->getElementById('fa-pdf');
        if ($raiz) {
            $this->recorrer($raiz, 'left');
            $this->cerrarParrafo('left');
        }
    }

    /**
     * Alineación de un bloque, por su atributo align o su estilo text-align.
     *
     * @param DOMElement $elemento
     * @param string $heredada
     *
     * @return string Justificación de ezpdf: left, center, right o full.
     */
    private function alineacion(DOMElement $elemento, string $heredada): string
    {
        $valor = strtolower($elemento->getAttribute('align'));
        if (preg_match('/text-align\s*:\s*(left|center|right|justify)/i', $elemento->getAttribute('style'), $m)) {
            $valor = strtolower($m[1]);
        }

        $mapa = ['left' => 'left', 'center' => 'center', 'right' => 'right', 'justify' => 'full'];
        return $mapa[$valor] ?? $heredada;
    }

    private function cerrarParrafo(string $justificacion, int $tamano = self::TAMANO, int $sangria = 0): void
    {
        $texto = trim(preg_replace('/[ \t]+/', ' ', $this->parrafo));
        $this->parrafo = '';
        if ($texto === '') {
            return;
        }

        $this->pdf->ezText($texto, $tamano, ['justification' => $justificacion, 'left' => $sangria]);
        $this->pdf->ezText('', 4);
    }

    private function imagen(DOMElement $img): void
    {
        // sólo las imágenes que ha puesto el plugin, y sólo de la carpeta de archivos: el
        // texto del contrato lo escribe un usuario y no debe poder leer rutas del servidor
        $src = realpath($img->getAttribute('src'));
        $raiz = realpath(FS_FOLDER . '/MyFiles');
        if ($img->getAttribute('data-fa-img') !== '1' || false === $src || false === $raiz
            || 0 !== strpos($src, $raiz . DIRECTORY_SEPARATOR)) {
            return;
        }

        $ext = strtolower(pathinfo($src, PATHINFO_EXTENSION));
        if (false === in_array($ext, ['png', 'jpg', 'jpeg'], true)) {
            return;
        }

        $size = @getimagesize($src);
        if (false === $size) {
            return;
        }

        // ancho natural a 96 ppp, sin pasar del ancho de la página
        $ancho = $this->pdf->ez['pageWidth'] - $this->pdf->ez['leftMargin'] - $this->pdf->ez['rightMargin'];
        $puntos = min($ancho, $size[0] * 0.75);
        if (preg_match('/max-height\s*:\s*(\d+)px/i', $img->getAttribute('style'), $m)) {
            $puntos = min($puntos, (int)$m[1] * 0.75 * $size[0] / $size[1]);
        }

        $this->pdf->ezImage($src, 0, $puntos, 'none', 'left');
    }

    private function recorrer(DOMNode $nodo, string $justificacion, int $sangria = 0): void
    {
        foreach ($nodo->childNodes as $hijo) {
            if ($hijo->nodeType === XML_TEXT_NODE) {
                // < y > son marcas de formato para ezpdf
                $this->parrafo .= str_replace(['<', '>'], ['‹', '›'], $hijo->textContent);
                continue;
            }
            if (false === $hijo instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($hijo->nodeName);
            if ($hijo->hasAttribute('data-fa-firmas')) {
                $this->cerrarParrafo($justificacion, self::TAMANO, $sangria);
                call_user_func($this->alEncontrarFirmas);
                $this->firmasDibujadas = true;
                continue;
            }

            switch ($tag) {
                case 'b':
                case 'strong':
                    $this->parrafo .= '<b>';
                    $this->recorrer($hijo, $justificacion, $sangria);
                    $this->parrafo .= '</b>';
                    break;

                case 'i':
                case 'em':
                    $this->parrafo .= '<i>';
                    $this->recorrer($hijo, $justificacion, $sangria);
                    $this->parrafo .= '</i>';
                    break;

                case 'br':
                    $this->parrafo .= "\n";
                    break;

                case 'hr':
                    $this->cerrarParrafo($justificacion, self::TAMANO, $sangria);
                    $y = $this->pdf->y - 4;
                    $this->pdf->setLineStyle(0.5);
                    $this->pdf->line($this->pdf->ez['leftMargin'], $y, $this->pdf->ez['pageWidth'] - $this->pdf->ez['rightMargin'], $y);
                    $this->pdf->ezText('', 10);
                    break;

                case 'img':
                    $this->cerrarParrafo($justificacion, self::TAMANO, $sangria);
                    $this->imagen($hijo);
                    break;

                case 'h1':
                case 'h2':
                case 'h3':
                case 'h4':
                case 'h5':
                case 'h6':
                    $this->cerrarParrafo($justificacion, self::TAMANO, $sangria);
                    $this->recorrer($hijo, $justificacion, $sangria);
                    $this->parrafo = '<b>' . trim($this->parrafo) . '</b>';
                    $tamanos = ['h1' => 17, 'h2' => 15, 'h3' => 13, 'h4' => 12];
                    $this->cerrarParrafo($this->alineacion($hijo, $justificacion), $tamanos[$tag] ?? 11, $sangria);
                    break;

                case 'ul':
                case 'ol':
                    $this->cerrarParrafo($justificacion, self::TAMANO, $sangria);
                    $numero = max(1, (int)($hijo->getAttribute('start') ?: 1));
                    foreach ($hijo->childNodes as $li) {
                        if (false === $li instanceof DOMElement || strtolower($li->nodeName) !== 'li') {
                            continue;
                        }
                        $this->parrafo = $tag === 'ol' ? ($numero++) . '. ' : '• ';
                        $this->recorrer($li, $justificacion, $sangria + 12);
                        $this->cerrarParrafo('left', self::TAMANO, $sangria + 12);
                    }
                    break;

                case 'table':
                    $this->cerrarParrafo($justificacion, self::TAMANO, $sangria);
                    $this->tabla($hijo);
                    break;

                case 'p':
                case 'div':
                case 'blockquote':
                case 'center':
                case 'pre':
                    $this->cerrarParrafo($justificacion, self::TAMANO, $sangria);
                    $propia = $tag === 'center' ? 'center' : $this->alineacion($hijo, $justificacion);
                    $this->recorrer($hijo, $propia, $tag === 'blockquote' ? $sangria + 20 : $sangria);
                    $this->cerrarParrafo($propia, self::TAMANO, $tag === 'blockquote' ? $sangria + 20 : $sangria);
                    break;

                default:
                    $this->recorrer($hijo, $justificacion, $sangria);
                    break;
            }
        }
    }

    private function tabla(DOMElement $tabla): void
    {
        $filas = [];
        $cabecera = false;
        $columnas = 0;
        foreach ($tabla->getElementsByTagName('tr') as $indice => $tr) {
            $fila = [];
            foreach ($tr->childNodes as $celda) {
                if (false === $celda instanceof DOMElement || false === in_array(strtolower($celda->nodeName), ['td', 'th'], true)) {
                    continue;
                }
                if ($indice === 0 && strtolower($celda->nodeName) === 'th') {
                    $cabecera = true;
                }

                $anterior = $this->parrafo;
                $this->parrafo = '';
                $this->textoEnLinea($celda);
                $fila[] = trim(preg_replace('/[ \t]+/', ' ', $this->parrafo));
                $this->parrafo = $anterior;
            }
            if ($fila) {
                $filas[] = $fila;
                $columnas = max($columnas, count($fila));
            }
        }

        if (empty($filas)) {
            return;
        }

        $claves = array_fill(0, $columnas, '');
        foreach ($filas as $i => $fila) {
            $filas[$i] = array_replace($claves, $fila);
        }

        $titulos = $claves;
        if ($cabecera) {
            $titulos = array_shift($filas);
        }

        $this->pdf->ezTable($filas, $titulos, '', [
            'showHeadings' => $cabecera ? 1 : 0,
            'shaded' => 0,
            'showLines' => (int)$tabla->getAttribute('border') > 0 ? 2 : 0,
            'fontSize' => self::TAMANO,
            'xPos' => 'left',
            'xOrientation' => 'right',
            'width' => $this->pdf->ez['pageWidth'] - $this->pdf->ez['leftMargin'] - $this->pdf->ez['rightMargin'],
        ]);
        $this->pdf->ezText('', 6);
    }

    /**
     * Texto de una celda, con negritas y cursivas, y saltos de línea en los bloques.
     *
     * @param DOMNode $nodo
     */
    private function textoEnLinea(DOMNode $nodo): void
    {
        foreach ($nodo->childNodes as $hijo) {
            if ($hijo->nodeType === XML_TEXT_NODE) {
                $this->parrafo .= str_replace(['<', '>'], ['‹', '›'], $hijo->textContent);
                continue;
            }
            if (false === $hijo instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($hijo->nodeName);
            $marca = in_array($tag, ['b', 'strong'], true) ? 'b' : (in_array($tag, ['i', 'em'], true) ? 'i' : '');
            $this->parrafo .= $marca ? '<' . $marca . '>' : ($tag === 'br' ? "\n" : '');
            $this->textoEnLinea($hijo);
            $this->parrafo .= $marca ? '</' . $marca . '>' : (in_array($tag, ['p', 'div', 'li'], true) ? "\n" : '');
        }
    }
}
