<?php
/**
 * This file is part of FirmarAhora plugin for FacturaScripts.
 * Copyright (C) 2026 Antonio Jesús González Domingo <antonio.gonzalez.domingo@proton.me>
 */

namespace FacturaScripts\Plugins\FirmarAhora\Lib\FirmarAhora;

/**
 * Dirección ip de quien hace la petición, para las evidencias de la firma.
 *
 * Las cabeceras X-Forwarded-For y CF-Connecting-IP las puede escribir el propio cliente,
 * así que por defecto se usa la dirección de la conexión (REMOTE_ADDR). Sólo se confía en
 * una cabecera cuando el administrador indica en los ajustes que hay un proxy que la pone.
 */
class Conexion
{
    /**
     * @return string Ip del firmante, o vacío si no se puede saber.
     */
    public static function ip(): string
    {
        $candidata = '';
        switch (Ajustes::cabeceraIp()) {
            case 'cf-connecting-ip':
                $candidata = trim((string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''));
                break;

            case 'x-forwarded-for':
                // el proxy añade al final la dirección que él ve; lo anterior lo escribe el cliente
                $partes = explode(',', (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
                $candidata = trim((string)end($partes));
                break;
        }

        foreach ([$candidata, (string)($_SERVER['REMOTE_ADDR'] ?? '')] as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }

        return '';
    }

    /**
     * Lo que dicen las cabeceras de proxy cuando no se confía en ellas. Se guarda junto a la
     * ip como dato sin verificar.
     *
     * @return string
     */
    public static function proxy(): string
    {
        if (Ajustes::cabeceraIp() !== '') {
            return '';
        }

        $partes = [];
        foreach (['HTTP_CF_CONNECTING_IP' => 'CF-Connecting-IP', 'HTTP_X_FORWARDED_FOR' => 'X-Forwarded-For'] as $clave => $nombre) {
            $valor = preg_replace('/[^0-9a-fA-F:., ]/', '', (string)($_SERVER[$clave] ?? ''));
            if ($valor !== '') {
                $partes[] = $nombre . ': ' . trim($valor);
            }
        }

        return mb_substr(implode('; ', $partes), 0, 100);
    }
}
