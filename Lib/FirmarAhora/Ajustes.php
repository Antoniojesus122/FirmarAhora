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
        'copia_firmante' => true,
        'dias_validez' => 30,
        'firmas_en_impresion' => true,
        'idioma_navegador' => true,
        'otp_defecto' => false,
        'pedir_ubicacion' => false,
        'presupuesto_firmado' => '',
        'recordatorio_dias' => 3,
        'recordatorios_max' => 2,
    ];

    /** @return bool Avisar por email al usuario que envió la solicitud cuando se firma o rechaza. */
    public static function avisarEmisor(): bool
    {
        return (bool)self::valor('avisar_emisor');
    }

    /** @return bool Enviar al firmante la copia sellada en pdf al firmar. */
    public static function copiaFirmante(): bool
    {
        return (bool)self::valor('copia_firmante');
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
            if (null === Tools::settings(self::GRUPO, $clave)) {
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
     * @param string $clave
     *
     * @return mixed Valor guardado o, si no lo hay, el de DEFECTO.
     */
    private static function valor(string $clave)
    {
        return Tools::settings(self::GRUPO, $clave, self::DEFECTO[$clave] ?? null);
    }
}
