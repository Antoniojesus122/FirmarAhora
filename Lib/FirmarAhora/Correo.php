<?php
/**
 * This file is part of FirmarAhora plugin for FacturaScripts.
 * Copyright (C) 2026 Antonio Jesús González Domingo <antonio.gonzalez.domingo@proton.me>
 */

namespace FacturaScripts\Plugins\FirmarAhora\Lib\FirmarAhora;

use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Lib\Email\ButtonBlock;
use FacturaScripts\Dinamic\Lib\Email\HtmlBlock;
use FacturaScripts\Dinamic\Lib\Email\NewMail;
use FacturaScripts\Dinamic\Model\SolicitudFirma;
use FacturaScripts\Dinamic\Model\User;

/**
 * Emails del plugin: invitación a firmar, código OTP, recordatorio, copia firmada para
 * el firmante y aviso al usuario que envió la solicitud.
 */
class Correo
{
    /**
     * Aviso al usuario que creó la solicitud: se ha firmado o rechazado.
     *
     * @param SolicitudFirma $solicitud
     *
     * @return bool
     */
    public static function avisoEmisor(SolicitudFirma $solicitud): bool
    {
        $user = new User();
        if (empty($solicitud->nick) || false === $user->load($solicitud->nick) || empty($user->email)) {
            return false;
        }

        // el aviso va en el idioma del usuario, no en el de la página que ve el firmante
        return Idioma::con($user->langcode, function () use ($solicitud, $user) {
            return self::enviarAviso($solicitud, $user);
        });
    }

    /**
     * Código OTP para el firmante.
     *
     * @param SolicitudFirma $solicitud
     * @param string $codigo
     *
     * @return bool
     */
    public static function codigo(SolicitudFirma $solicitud, string $codigo): bool
    {
        $mail = new NewMail();
        $mail->to($solicitud->email, Tools::fixHtml((string)$solicitud->nombre))
            ->subject(Tools::trans('fa-mail-otp-subject', ['%code%' => $codigo]))
            ->body(Tools::trans('fa-mail-otp-text', ['%doc%' => Tools::fixHtml($solicitud->doc_titulo), '%minutes%' => Otp::MINUTOS]))
            ->addMainBlock(new HtmlBlock('<p style="font-size:28px;letter-spacing:6px;font-weight:bold;text-align:center;">' . $codigo . '</p>'));

        return $mail->send();
    }

    /**
     * Copia sellada para el firmante, adjunta al email.
     *
     * @param SolicitudFirma $solicitud
     *
     * @return bool
     */
    public static function copiaFirmada(SolicitudFirma $solicitud): bool
    {
        $ruta = FS_FOLDER . '/' . $solicitud->sellado_path;
        if (empty($solicitud->sellado_path) || false === file_exists($ruta)) {
            return false;
        }

        $mail = new NewMail();
        $mail->to($solicitud->email, Tools::fixHtml((string)$solicitud->firmante_nombre))
            ->subject(Tools::trans('fa-mail-copy-subject', ['%doc%' => Tools::fixHtml($solicitud->doc_titulo)]))
            ->body(Tools::trans('fa-mail-copy-text', ['%code%' => $solicitud->codigo]))
            ->addMainBlock(new ButtonBlock(Tools::trans('fa-verify'), $solicitud->urlVerificacion()))
            ->addAttachment($ruta, 'firmado_' . $solicitud->codigo . '.pdf');

        return $mail->send();
    }

    /**
     * Invitación a firmar, con el botón que lleva al enlace.
     *
     * @param SolicitudFirma $solicitud
     * @param string $asunto Vacío: el asunto por defecto.
     * @param string $mensaje Vacío: el texto por defecto.
     * @param ?User $user Usuario que envía, para usar su buzón si lo tiene configurado.
     * @param bool $recordatorio
     *
     * @return bool
     */
    public static function invitacion(SolicitudFirma $solicitud, string $asunto = '', string $mensaje = '', ?User $user = null, bool $recordatorio = false): bool
    {
        if (empty($solicitud->email)) {
            Tools::log()->warning('fa-no-email');
            return false;
        }

        if (false === $solicitud->estaAbierta()) {
            Tools::log()->warning('fa-request-closed');
            return false;
        }

        // sin dirección pública el enlace saldría como localhost (pasa en el cron)
        if (Ajustes::urlBase() === '') {
            Tools::log()->warning('fa-no-public-url');
            return false;
        }

        $doc = Tools::fixHtml((string)$solicitud->doc_titulo);
        $asunto = trim($asunto) !== '' ? trim($asunto) :
            Tools::trans($recordatorio ? 'fa-mail-reminder-subject' : 'fa-mail-invite-subject', ['%doc%' => $doc]);
        $mensaje = trim($mensaje) !== '' ? trim($mensaje) :
            Tools::trans($recordatorio ? 'fa-mail-reminder-text' : 'fa-mail-invite-text', ['%doc%' => $doc]);
        if (false === empty($solicitud->caduca)) {
            $mensaje .= "\n\n" . Tools::trans('fa-mail-expires', ['%date%' => Tools::date($solicitud->caduca)]);
        }

        $mail = new NewMail();
        if ($user) {
            $mail->setUser($user);
        }
        $mail->to($solicitud->email, Tools::fixHtml((string)$solicitud->nombre))
            ->subject(Tools::noHtml($asunto))
            ->body(Tools::noHtml($mensaje))
            ->addMainBlock(new ButtonBlock(Tools::trans('fa-sign-now'), $solicitud->urlFirma()));

        if (false === $mail->send()) {
            return false;
        }

        $solicitud->enviado = Tools::dateTime();
        if ($recordatorio) {
            $solicitud->recordatorios++;
        } else {
            Otp::reiniciar($solicitud);
        }
        $solicitud->save();
        $solicitud->registrar($recordatorio ? 'recordatorio' : 'enviada', $solicitud->email, '', '', $user->nick ?? null);
        return true;
    }

    /**
     * @param SolicitudFirma $solicitud
     * @param User $user
     *
     * @return bool
     */
    private static function enviarAviso(SolicitudFirma $solicitud, User $user): bool
    {
        $firmada = $solicitud->estado === SolicitudFirma::ESTADO_FIRMADA;
        $clave = $firmada ? 'fa-mail-notice-signed' : 'fa-mail-notice-rejected';
        $texto = Tools::trans($clave, [
            '%signer%' => Tools::fixHtml($solicitud->firmante_nombre ?: $solicitud->nombre),
            '%doc%' => Tools::fixHtml($solicitud->doc_titulo),
            '%reason%' => Tools::fixHtml((string)$solicitud->motivo_rechazo),
        ]);

        $mail = new NewMail();
        $mail->to($user->email)
            ->subject(Tools::trans($firmada ? 'fa-mail-notice-signed-subject' : 'fa-mail-notice-rejected-subject', ['%doc%' => Tools::fixHtml($solicitud->doc_titulo)]))
            ->body($texto)
            ->addMainBlock(new ButtonBlock(Tools::trans('fa-view-request'), Ajustes::urlBase() . '/' . $solicitud->url()));

        return $mail->send();
    }
}
