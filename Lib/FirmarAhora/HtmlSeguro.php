<?php
/**
 * This file is part of FirmarAhora plugin for FacturaScripts.
 * Copyright (C) 2026 Antonio Jesús González Domingo <antonio.gonzalez.domingo@proton.me>
 */

namespace FacturaScripts\Plugins\FirmarAhora\Lib\FirmarAhora;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Deja en un html sólo etiquetas y atributos de formato. Los contratos se muestran en
 * una página pública, así que su texto no puede llevar scripts, eventos, formularios,
 * iframes ni enlaces javascript:.
 */
class HtmlSeguro
{
    /** @var array Etiqueta permitida => atributos permitidos además de los comunes. */
    const ETIQUETAS = [
        'a' => ['href', 'target'], 'b' => [], 'blockquote' => [], 'br' => [], 'caption' => [], 'center' => [],
        'code' => [], 'col' => ['span', 'width'], 'colgroup' => ['span'], 'dd' => [], 'div' => [], 'dl' => [],
        'dt' => [], 'em' => [], 'font' => ['color', 'face', 'size'], 'h1' => [], 'h2' => [], 'h3' => [],
        'h4' => [], 'h5' => [], 'h6' => [], 'hr' => [], 'i' => [], 'img' => ['src', 'alt', 'width', 'height'],
        'li' => [], 'ol' => ['start', 'type'], 'p' => [], 'pre' => [], 's' => [], 'small' => [], 'span' => [],
        'strike' => [], 'strong' => [], 'sub' => [], 'sup' => [],
        'table' => ['border', 'cellpadding', 'cellspacing', 'width'], 'tbody' => [],
        'td' => ['colspan', 'rowspan', 'width', 'valign'], 'tfoot' => [],
        'th' => ['colspan', 'rowspan', 'width', 'valign'], 'thead' => [], 'tr' => [], 'u' => [], 'ul' => [],
    ];

    /** @var string[] Atributos permitidos en cualquier etiqueta. */
    const ATRIBUTOS_COMUNES = ['align', 'class', 'style', 'title'];

    /** @var string[] Etiquetas que se eliminan con todo lo que contienen. */
    const PROHIBIDAS = [
        'applet', 'audio', 'base', 'button', 'canvas', 'embed', 'form', 'frame', 'frameset', 'head', 'iframe',
        'input', 'link', 'math', 'meta', 'noscript', 'object', 'option', 'script', 'select', 'style', 'svg',
        'template', 'textarea', 'title', 'video',
    ];

    /**
     * @param ?string $html Html sin escapar.
     *
     * @return string Html limpio.
     */
    public static function limpiar(?string $html): string
    {
        if (null === $html || trim($html) === '') {
            return '';
        }

        $dom = new DOMDocument('1.0', 'UTF-8');
        $ok = $dom->loadHTML('<?xml encoding="UTF-8"><div id="fa-raiz">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR | LIBXML_NOWARNING);
        $raiz = $ok ? $dom->getElementById('fa-raiz') : null;
        if (null === $raiz) {
            return htmlspecialchars(strip_tags($html), ENT_QUOTES, 'UTF-8');
        }

        self::limpiarNodo($raiz);

        $out = '';
        foreach ($raiz->childNodes as $hijo) {
            $out .= $dom->saveHTML($hijo);
        }

        return $out;
    }

    private static function limpiarNodo(DOMNode $nodo): void
    {
        $hijos = [];
        foreach ($nodo->childNodes as $hijo) {
            $hijos[] = $hijo;
        }

        foreach ($hijos as $hijo) {
            if (in_array($hijo->nodeType, [XML_COMMENT_NODE, XML_PI_NODE, XML_CDATA_SECTION_NODE], true)) {
                $nodo->removeChild($hijo);
                continue;
            }
            if (false === $hijo instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($hijo->nodeName);
            if (in_array($tag, self::PROHIBIDAS, true)) {
                $nodo->removeChild($hijo);
                continue;
            }

            self::limpiarNodo($hijo);

            if (false === isset(self::ETIQUETAS[$tag])) {
                // etiqueta desconocida: se quita pero se conserva su contenido
                while ($hijo->firstChild) {
                    $nodo->insertBefore($hijo->firstChild, $hijo);
                }
                $nodo->removeChild($hijo);
                continue;
            }

            self::limpiarAtributos($hijo, $tag);
        }
    }

    private static function limpiarAtributos(DOMElement $elemento, string $tag): void
    {
        $permitidos = array_merge(self::ATRIBUTOS_COMUNES, self::ETIQUETAS[$tag]);

        $nombres = [];
        foreach ($elemento->attributes as $atributo) {
            $nombres[] = $atributo->nodeName;
        }

        foreach ($nombres as $nombre) {
            $clave = strtolower($nombre);
            $valor = $elemento->getAttribute($nombre);
            $valido = in_array($clave, $permitidos, true)
                && ($clave !== 'href' || self::urlValida($valor, false))
                && ($clave !== 'src' || self::urlValida($valor, true))
                && ($clave !== 'style' || self::estiloValido($valor));
            if (false === $valido) {
                $elemento->removeAttribute($nombre);
            }
        }

        if ($tag === 'a' && $elemento->hasAttribute('target')) {
            $elemento->setAttribute('rel', 'noopener noreferrer');
        }
    }

    private static function estiloValido(string $estilo): bool
    {
        return 0 === preg_match('/expression|javascript|vbscript|behaviou?r|url\s*\(|@import|-moz-binding/i', $estilo);
    }

    private static function urlValida(string $url, bool $imagen): bool
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES, 'UTF-8'));
        if ($url === '') {
            return false;
        }
        if ($imagen) {
            return 1 === preg_match('#^(https?://|/|data:image/(png|jpe?g|gif|webp);base64,)#i', $url)
                || 1 === preg_match('#^[a-z0-9_\-./]+(\?[a-z0-9=&_\-.%]*)?$#i', $url);
        }

        return 1 === preg_match('#^(https?://|mailto:|tel:|/|\#)#i', $url);
    }
}
