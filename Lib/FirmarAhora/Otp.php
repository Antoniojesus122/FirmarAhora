<?php
/**
 * This file is part of FirmarAhora plugin for FacturaScripts.
 * Copyright (C) 2026 Antonio Jesús González Domingo <antonio.gonzalez.domingo@proton.me>
 */

namespace FacturaScripts\Plugins\FirmarAhora\Lib\FirmarAhora;

use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Model\SolicitudFirma;

/**
 * Código de un solo uso que se envía por email al firmante antes de firmar, para
 * comprobar que tiene acceso a ese buzón. El código se guarda cifrado, caduca a los
 * 10 minutos y admite 5 intentos.
 */
class Otp
{
    const INTENTOS = 5;
    const MINUTOS = 10;

    /** @var int Segundos que hay que esperar para pedir otro código. */
    const ESPERA = 60;

    /**
     * Comprueba el código que escribe el firmante.
     *
     * @param SolicitudFirma $solicitud
     * @param string $codigo
     * @param string $ip
     * @param string $userAgent
     *
     * @return bool
     */
    public static function comprobar(SolicitudFirma $solicitud, string $codigo, string $ip, string $userAgent): bool
    {
        if (empty($solicitud->otp_hash) || strtotime((string)$solicitud->otp_expira) < time()) {
            Tools::log()->warning('fa-otp-expired');
            return false;
        }

        if ($solicitud->otp_intentos >= self::INTENTOS) {
            Tools::log()->warning('fa-otp-too-many');
            return false;
        }

        $solicitud->otp_intentos++;
        if (false === password_verify(preg_replace('/\D/', '', $codigo), $solicitud->otp_hash)) {
            $solicitud->save();
            $solicitud->registrar('otp_error', '', Firmador::ip($ip), $userAgent);
            Tools::log()->warning('fa-otp-wrong', ['%left%' => self::INTENTOS - $solicitud->otp_intentos]);
            return false;
        }

        $solicitud->otp_verificado = true;
        $solicitud->otp_hash = null;
        $solicitud->save();
        $solicitud->registrar('otp_ok', '', Firmador::ip($ip), $userAgent);
        return true;
    }

    /**
     * Genera un código nuevo y lo envía por email.
     *
     * @param SolicitudFirma $solicitud
     * @param string $ip
     * @param string $userAgent
     *
     * @return bool
     */
    public static function enviar(SolicitudFirma $solicitud, string $ip, string $userAgent): bool
    {
        if (empty($solicitud->email)) {
            Tools::log()->warning('fa-otp-needs-email');
            return false;
        }

        // el código anterior se generó hace menos de ESPERA segundos
        $generado = strtotime((string)$solicitud->otp_expira) - self::MINUTOS * 60;
        if (false === empty($solicitud->otp_hash) && time() - $generado < self::ESPERA) {
            Tools::log()->warning('fa-otp-wait', ['%seconds%' => self::ESPERA - (time() - $generado)]);
            return false;
        }

        $codigo = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $solicitud->otp_hash = password_hash($codigo, PASSWORD_DEFAULT);
        $solicitud->otp_expira = date('Y-m-d H:i:s', time() + self::MINUTOS * 60);
        $solicitud->otp_intentos = 0;
        if (false === $solicitud->save() || false === Correo::codigo($solicitud, $codigo)) {
            return false;
        }

        $solicitud->registrar('otp_enviado', $solicitud->emailOculto(), Firmador::ip($ip), $userAgent);
        return true;
    }
}
