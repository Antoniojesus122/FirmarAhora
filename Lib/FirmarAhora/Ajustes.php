<?php
/**
 * This file is part of FirmarAhora plugin for FacturaScripts.
 * Copyright (C) 2026 Antonio Jesús González Domingo <antonio.gonzalez.domingo@proton.me>
 */

namespace FacturaScripts\Plugins\FirmarAhora\Lib\FirmarAhora;

use FacturaScripts\Core\Tools;

/**
 * Ajustes del plugin (Administrador > Panel de control > FirmarAhora), con sus valores
 * por defecto.
 */
class Ajustes
{
    const GRUPO = 'firmarahora';

    /** @var array Valores por defecto, que se guardan al instalar para que el formulario los muestre. */
    const DEFECTO = [
        'avisar_emisor' => true,
        'cabecera_ip' => 'directa',
        'copia_firmante' => true,
        'copiar_al_convertir' => true,
        'dias_validez' => 30,
        'firmas_en_impresion' => true,
        'idioma_navegador' => true,
        'otp_defecto' => false,
        'pedir_ubicacion' => false,
        'presupuesto_firmado' => 'no',
        'recordatorio_dias' => 3,
        'recordatorios_max' => 2,
        'url_publica' => '',
    ];

    /** @return bool Avisar por email al usuario que envió la solicitud cuando se firma o rechaza. */
    public static function avisarEmisor(): bool
    {
        return (bool)self::valor('avisar_emisor');
    }

    /** @return string Cabecera de proxy de confianza para la ip del firmante: x-forwarded-for, cf-connecting-ip o vacío. */
    public static function cabeceraIp(): string
    {
        $valor = (string)self::valor('cabecera_ip');
        return in_array($valor, ['x-forwarded-for', 'cf-connecting-ip'], true) ? $valor : '';
    }

    /** @return bool Enviar al firmante la copia sellada en pdf al firmar. */
    public static function copiaFirmante(): bool
    {
        return (bool)self::valor('copia_firmante');
    }

    /** @return bool Copiar las firmas al documento que se genera al convertir otro. */
    public static function copiarAlConvertir(): bool
    {
        return (bool)self::valor('copiar_al_convertir');
    }

    /** @return int Días que admite la firma un enlace. 0: sin caducidad. */
    public static function diasValidez(): int
    {
        return max(0, (int)self::valor('dias_validez'));
    }

    /** @return bool Imprimir las firmas en los pdf normales de los documentos. */
    public static function firmasEnImpresion(): bool
    {
        return (bool)self::valor('firmas_en_impresion');
    }

    /** @return bool Mostrar las páginas públicas en el idioma del navegador del firmante. */
    public static function idiomaNavegador(): bool
    {
        return (bool)self::valor('idioma_navegador');
    }

    /** @return bool Pedir código por email en las solicitudes nuevas. */
    public static function otpDefecto(): bool
    {
        return (bool)self::valor('otp_defecto');
    }

    /** @return bool Pedir la ubicación al firmante en la página pública. */
    public static function pedirUbicacion(): bool
    {
        return (bool)self::valor('pedir_ubicacion');
    }

    /** @return string Documento que se genera al firmar todos un presupuesto: pedido, factura o vacío. */
    public static function presupuestoFirmado(): string
    {
        $valor = (string)self::valor('presupuesto_firmado');
        return in_array($valor, ['pedido', 'factura'], true) ? $valor : '';
    }

    /** @return int Días entre recordatorios. 0: no enviar recordatorios. */
    public static function recordatorioDias(): int
    {
        return max(0, (int)self::valor('recordatorio_dias'));
    }

    /** @return int Recordatorios como máximo por solicitud. */
    public static function recordatoriosMax(): int
    {
        return max(0, (int)self::valor('recordatorios_max'));
    }

    /**
     * Guarda los valores por defecto que aún no estén guardados.
     */
    public static function guardarPorDefecto(): void
    {
        $cambios = false;
        foreach (self::DEFECTO as $clave => $valor) {
            // los desplegables no admiten el valor vacío que guardaban las versiones anteriores
            $guardado = Tools::settings(self::GRUPO, $clave);
            if (null === $guardado || ($guardado === '' && $valor !== '')) {
                Tools::settingsSet(self::GRUPO, $clave, $valor);
                $cambios = true;
            }
        }

        if ($cambios) {
            Tools::settingsSave();
        }
    }

    /** @return string Texto que el firmante acepta antes de firmar. */
    public static function textoLegal(): string
    {
        $text = trim((string)self::valor('texto_legal'));
        return empty($text) ? Tools::trans('fa-legal-default') : Tools::fixHtml($text);
    }

    /**
     * Dirección pública de la instalación, para los enlaces de firma y de verificación.
     * Se toma de los ajustes y no de la petición, para que un enlace no dependa de la
     * cabecera Host ni salga como localhost desde el cron.
     *
     * @return string Sin barra final. Vacío si no está configurada y no hay petición web.
     */
    public static function urlBase(): string
    {
        $url = trim((string)self::valor('url_publica'));
        if ($url === '') {
            $url = trim((string)Tools::settings('default', 'site_url', ''));
        }
        if ($url === '' && PHP_SAPI !== 'cli') {
            $url = Tools::siteUrl();
        }

        return rtrim($url, '/');
    }

    /**
     * Guarda la dirección con la que un usuario del ERP usa la instalación, si todavía no
     * hay ninguna configurada.
     */
    public static function recordarUrl(): void
    {
        if (PHP_SAPI === 'cli' || trim((string)self::valor('url_publica')) !== ''
            || trim((string)Tools::settings('default', 'site_url', '')) !== '') {
            return;
        }

        Tools::settingsSet(self::GRUPO, 'url_publica', rtrim(Tools::siteUrl(), '/'));
        Tools::settingsSave();
    }

    /**
     * @param string $clave
     *
     * @return mixed Valor guardado o, si no lo hay, el de DEFECTO.
     */
    private static function valor(string $clave)
    {
        return Tools::settings(self::GRUPO, $clave, self::DEFECTO[$clave] ?? null);
    }
}
