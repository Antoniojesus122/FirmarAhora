<?php
/**
 * This file is part of FirmarAhora plugin for FacturaScripts.
 * Copyright (C) 2026 Antonio Jesús González Domingo <antonio.gonzalez.domingo@proton.me>
 */

namespace FacturaScripts\Plugins\FirmarAhora\Lib\FirmarAhora;

use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Translator;

/**
 * Idioma de las páginas públicas. El firmante no tiene usuario en el ERP, así que se
 * usa el de su navegador cuando el plugin tiene los textos en ese idioma (español o
 * inglés). Los emails a los usuarios del ERP se envían en el idioma de cada usuario.
 */
class Idioma
{
    /** @var ?string Idioma del sitio antes de cambiar al del firmante. */
    private static $sitio = null;

    /**
     * Ejecuta una función con otro idioma y después restaura el actual.
     *
     * @param ?string $lang Vacío: el idioma del sitio.
     * @param callable $funcion
     *
     * @return mixed Lo que devuelva la función.
     */
    public static function con(?string $lang, callable $funcion)
    {
        $actual = Tools::lang()->getLang();
        self::usar(empty($lang) ? (self::$sitio ?? $actual) : $lang);

        try {
            return $funcion();
        } finally {
            self::usar($actual);
        }
    }

    /**
     * Cambia al idioma del navegador del firmante, si está activado en los ajustes y la
     * persona no ha elegido ya un idioma en el ERP.
     */
    public static function delFirmante(): void
    {
        if (false === Ajustes::idiomaNavegador() || isset($_COOKIE['fsLang'])) {
            return;
        }

        $actual = Tools::lang()->getLang();
        $elegido = self::elegir((string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''), $actual);
        if ($elegido !== $actual) {
            self::$sitio = $actual;
            self::usar($elegido);
        }
    }

    /**
     * Idioma para una cabecera Accept-Language, en su orden de preferencia.
     *
     * @param string $cabecera Por ejemplo "fr-FR,fr;q=0.9,en;q=0.8".
     * @param string $actual Idioma del sitio.
     *
     * @return string
     */
    public static function elegir(string $cabecera, string $actual): string
    {
        foreach (explode(',', $cabecera) as $parte) {
            $codigo = strtolower(substr(trim(explode(';', $parte)[0]), 0, 2));
            if ($codigo === 'es') {
                return str_starts_with($actual, 'es_') ? $actual : 'es_ES';
            }
            if ($codigo === 'en') {
                return 'en_EN';
            }
        }

        return $actual;
    }

    /**
     * Cambia el idioma por defecto. Tools::trans() guarda su traductor, así que hay que
     * descartarlo para que use el nuevo.
     *
     * @param string $lang
     */
    private static function usar(string $lang): void
    {
        Translator::setDefaultLang($lang);
        Tools::translatorClear();
    }
}
