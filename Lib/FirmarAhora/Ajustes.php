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

    /** @return bool Avisar por email al usuario que envió la solicitud cuando se firma o rechaza. */
    public static function avisarEmisor(): bool
    {
        return (bool)Tools::settings(self::GRUPO, 'avisar_emisor', true);
    }

    /** @return bool Enviar al firmante la copia sellada en pdf al firmar. */
    public static function copiaFirmante(): bool
    {
        return (bool)Tools::settings(self::GRUPO, 'copia_firmante', true);
    }

    /** @return int Días que admite la firma un enlace. 0: sin caducidad. */
    public static function diasValidez(): int
    {
        return max(0, (int)Tools::settings(self::GRUPO, 'dias_validez', 30));
    }

    /** @return bool Imprimir las firmas en los pdf normales de los documentos. */
    public static function firmasEnImpresion(): bool
    {
        return (bool)Tools::settings(self::GRUPO, 'firmas_en_impresion', true);
    }

    /** @return bool Pedir código por email en las solicitudes nuevas. */
    public static function otpDefecto(): bool
    {
        return (bool)Tools::settings(self::GRUPO, 'otp_defecto', false);
    }

    /** @return int Días entre recordatorios. 0: no enviar recordatorios. */
    public static function recordatorioDias(): int
    {
        return max(0, (int)Tools::settings(self::GRUPO, 'recordatorio_dias', 3));
    }

    /** @return int Recordatorios como máximo por solicitud. */
    public static function recordatoriosMax(): int
    {
        return max(0, (int)Tools::settings(self::GRUPO, 'recordatorios_max', 2));
    }

    /** @return string Texto que el firmante acepta antes de firmar. */
    public static function textoLegal(): string
    {
        $text = trim((string)Tools::settings(self::GRUPO, 'texto_legal', ''));
        return empty($text) ? Tools::trans('fa-legal-default') : Tools::fixHtml($text);
    }
}
