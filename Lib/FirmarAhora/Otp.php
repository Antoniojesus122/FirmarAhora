<?php
/**
 * This file is part of FirmarAhora plugin for FacturaScripts.
 * Copyright (C) 2026 Antonio Jesús González Domingo <antonio.gonzalez.domingo@proton.me>
 */

namespace FacturaScripts\Plugins\FirmarAhora\Lib\FirmarAhora;

use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Model\SolicitudFirma;

/**
 * Código de un solo uso que se envía por email al firmante, para comprobar que tiene
 * acceso a ese buzón. Mientras no lo verifica, no puede ver el documento ni firmarlo.
 *
 * - El código se guarda cifrado, caduca a los 10 minutos y admite 5 intentos.
 * - Verificarlo abre una sesión en ese navegador (cookie), que es la única que puede ver
 *   y firmar el documento. Desde otro dispositivo hay que verificar un código nuevo.
 * - Por cada invitación se pueden pedir 5 códigos y fallar 15 veces. Reenviar la
 *   invitación desde el ERP pone los contadores a cero.
 */
class Otp
{
    const INTENTOS = 5;
    const MINUTOS = 10;

    /** @var int Segundos que hay que esperar para pedir otro código. */
    const ESPERA = 60;

    /** @var int Horas que dura la sesión del navegador que verificó el código. */
    const HORAS_SESION = 12;

    /** @var int Códigos que se pueden pedir por invitación. */
    const MAX_ENVIOS = 5;

    /** @var int Códigos incorrectos que se admiten por invitación. */
    const MAX_FALLOS = 15;

    /**
     * Comprueba el código que escribe el firmante y, si es correcto, abre la sesión.
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
        if (false === self::admite($solicitud)) {
            Tools::log()->warning('fa-request-closed');
            return false;
        }

        if ((int)$solicitud->otp_fallos >= self::MAX_FALLOS) {
            Tools::log()->warning('fa-otp-limit');
            return false;
        }

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
            $solicitud->otp_fallos = (int)$solicitud->otp_fallos + 1;
            $solicitud->save();
            $solicitud->registrar('otp_error', '', Firmador::ip($ip), $userAgent);
            Tools::log()->warning('fa-otp-wrong', ['%left%' => self::INTENTOS - $solicitud->otp_intentos]);
            return false;
        }

        $secreto = bin2hex(random_bytes(32));
        $caduca = time() + self::HORAS_SESION * 3600;
        $solicitud->otp_verificado = true;
        $solicitud->otp_hash = null;
        $solicitud->otp_sesion = $caduca . ':' . hash('sha256', $secreto);
        if (false === $solicitud->save()) {
            return false;
        }

        self::guardarCookie($solicitud, $secreto, $caduca);
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
        if (false === self::admite($solicitud)) {
            Tools::log()->warning('fa-request-closed');
            return false;
        }

        if (empty($solicitud->email)) {
            Tools::log()->warning('fa-otp-needs-email');
            return false;
        }

        if ((int)$solicitud->otp_envios >= self::MAX_ENVIOS || (int)$solicitud->otp_fallos >= self::MAX_FALLOS) {
            Tools::log()->warning('fa-otp-limit');
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
        $solicitud->otp_envios = (int)$solicitud->otp_envios + 1;
        if (false === $solicitud->save() || false === Correo::codigo($solicitud, $codigo)) {
            return false;
        }

        $solicitud->registrar('otp_enviado', $solicitud->emailOculto(), Firmador::ip($ip), $userAgent);
        return true;
    }

    /**
     * Pone a cero los contadores de códigos. No guarda la solicitud.
     *
     * @param SolicitudFirma $solicitud
     */
    public static function reiniciar(SolicitudFirma $solicitud): void
    {
        $solicitud->otp_envios = 0;
        $solicitud->otp_fallos = 0;
    }

    /**
     * El navegador de esta petición es el que verificó el código y su sesión sigue vigente.
     *
     * @param SolicitudFirma $solicitud
     *
     * @return bool
     */
    public static function sesionValida(SolicitudFirma $solicitud): bool
    {
        $partes = explode(':', (string)$solicitud->otp_sesion, 2);
        $secreto = (string)($_COOKIE[self::cookie($solicitud)] ?? '');
        if (count($partes) !== 2 || $secreto === '' || (int)$partes[0] < time()) {
            return false;
        }

        return hash_equals($partes[1], hash('sha256', $secreto));
    }

    /**
     * El código se puede usar mientras se puede firmar y, después, para descargar la copia
     * firmada. En una solicitud rechazada, anulada o caducada ya no tiene sentido.
     *
     * @param SolicitudFirma $solicitud
     *
     * @return bool
     */
    private static function admite(SolicitudFirma $solicitud): bool
    {
        return $solicitud->estaAbierta() || $solicitud->estado === SolicitudFirma::ESTADO_FIRMADA;
    }

    /**
     * @param SolicitudFirma $solicitud
     *
     * @return string Nombre de la cookie de sesión de esta solicitud.
     */
    private static function cookie(SolicitudFirma $solicitud): string
    {
        return 'fa_s_' . substr((string)$solicitud->token, 0, 16);
    }

    /**
     * @param SolicitudFirma $solicitud
     * @param string $secreto
     * @param int $caduca
     */
    private static function guardarCookie(SolicitudFirma $solicitud, string $secreto, int $caduca): void
    {
        $https = ($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
        setcookie(self::cookie($solicitud), $secreto, [
            'expires' => $caduca,
            'path' => (Tools::config('route') ?: '') . '/',
            'secure' => $https,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        // la página que se pinta en esta misma petición ya tiene que ver la sesión
        $_COOKIE[self::cookie($solicitud)] = $secreto;
    }
}
