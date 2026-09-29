<?php
/**
 * This file is part of FirmarAhora plugin for FacturaScripts.
 * Copyright (C) 2026 Antonio Jesús González Domingo <antonio.gonzalez.domingo@proton.me>
 */

namespace FacturaScripts\Plugins\FirmarAhora\Lib\FirmarAhora;

use FacturaScripts\Core\Lib\AssetManager;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Model\SolicitudFirma;

/**
 * Pestaña "Firmas" de un documento: lista de firmantes con su estado, formulario para
 * pedir una firma a distancia y formulario para firmar en el momento.
 *
 * La usan las extensiones de los documentos de venta (ExtensionPanelFirmas) y la ficha
 * de los contratos. Las acciones llegan por el campo "action" del formulario.
 */
class PanelFirmas
{
    const ACCIONES = ['fa-solicitar', 'fa-firmar', 'fa-reenviar', 'fa-anular', 'fa-borrar'];
    const PLANTILLA = 'Tab/PanelFirmas';
    const VISTA = 'firmarahora';

    /**
     * Prepara la pestaña. Si el documento aún no se ha guardado devuelve false, para que
     * el controlador la quite.
     *
     * @param object $view HtmlView de la pestaña.
     * @param object $documento
     *
     * @return bool
     */
    public static function cargar($view, $documento): bool
    {
        $codigo = (string)$documento->primaryColumnValue();
        if (empty($codigo) || false === $documento->exists()) {
            return false;
        }

        $sujeto = self::sujeto($documento);
        $view->settings['fa'] = [
            'solicitudes' => SolicitudFirma::delDocumento($documento->modelClassName(), $codigo),
            'nombre' => $sujeto['nombre'],
            'email' => $sujeto['email'],
            'nif' => $sujeto['nif'],
            'otp' => Ajustes::otpDefecto(),
            'dias' => Ajustes::diasValidez(),
            'legal' => Ajustes::textoLegal(),
            'url' => $documento->url(),
        ];

        return true;
    }

    /**
     * @param string $action
     *
     * @return bool True si la acción es de esta pestaña.
     */
    public static function esAccion(string $action): bool
    {
        return in_array($action, self::ACCIONES, true);
    }

    /**
     * Ejecuta una acción de la pestaña. Los errores y avisos quedan en Tools::log().
     *
     * @param string $action
     * @param object $documento Modelo del documento; se carga con $codigo si no lo está.
     * @param ?string $codigo
     * @param object $request
     * @param object $user
     * @param object $permissions
     * @param bool $tokenValido Resultado de validateFormToken() del controlador.
     */
    public static function ejecutar(string $action, $documento, ?string $codigo, $request, $user, $permissions, bool $tokenValido): void
    {
        if (false === $tokenValido) {
            return;
        }

        $borrar = $action === 'fa-borrar';
        if (($borrar && false === $permissions->allowDelete) || (false === $borrar && false === $permissions->allowUpdate)) {
            Tools::log()->warning($borrar ? 'not-allowed-delete' : 'not-allowed-modify');
            return;
        }

        if (false === $documento->exists() && false === empty($codigo)) {
            $documento->load($codigo);
        }
        if (false === $documento->exists()) {
            Tools::log()->warning('record-not-found');
            return;
        }

        switch ($action) {
            case 'fa-solicitar':
                self::solicitar($documento, $request, $user);
                return;

            case 'fa-firmar':
                self::firmarAqui($documento, $request, $user);
                return;
        }

        // el resto de acciones son sobre una solicitud de este documento
        $solicitud = new SolicitudFirma();
        if (false === $solicitud->load($request->input('fa_id', ''))
            || $solicitud->doc_model !== $documento->modelClassName()
            || $solicitud->doc_code !== (string)$documento->primaryColumnValue()) {
            Tools::log()->warning('record-not-found');
            return;
        }

        switch ($action) {
            case 'fa-reenviar':
                if (Correo::invitacion($solicitud, '', '', $user)) {
                    Tools::log()->notice('fa-invite-sent', ['%email%' => $solicitud->email]);
                }
                return;

            case 'fa-anular':
                if (Firmador::anular($solicitud, $user->nick)) {
                    Tools::log()->notice('fa-request-cancelled');
                }
                return;

            case 'fa-borrar':
                if ($solicitud->estado === SolicitudFirma::ESTADO_FIRMADA) {
                    Tools::log()->warning('fa-signed-cannot-delete');
                    return;
                }
                if ($solicitud->delete()) {
                    Tools::log()->notice('record-deleted-correctly');
                }
                return;
        }
    }

    /**
     * Añade el javascript y los estilos del panel de firma.
     */
    public static function recursos(): void
    {
        $route = Tools::config('route');
        AssetManager::addCss($route . '/Dinamic/Assets/CSS/FirmarAhora.css');
        AssetManager::addJs($route . '/Dinamic/Assets/JS/FirmarAhoraPad.js');
    }

    /**
     * Firma en el momento, delante del usuario: crea la solicitud y la firma a la vez.
     *
     * @param object $documento
     * @param object $request
     * @param object $user
     */
    private static function firmarAqui($documento, $request, $user): void
    {
        $solicitud = Firmador::crear($documento, [
            'nombre' => $request->input('fa_nombre', ''),
            'email' => $request->input('fa_email', ''),
            'nif' => $request->input('fa_nif', ''),
            'rol' => $request->input('fa_rol', ''),
            'dias' => 0,
        ], $user->nick);
        if (null === $solicitud) {
            return;
        }

        $solicitud->presencial = true;
        $solicitud->save();

        $ok = Firmador::firmar(
            $solicitud,
            $request->input('fa_firma', '') ?? '',
            $request->input('fa_tipo', '') ?? '',
            $request->input('fa_nombre', '') ?? '',
            $request->input('fa_nif', '') ?? '',
            $request->ip(),
            $request->userAgent(),
            $user->nick
        );

        if (false === $ok) {
            // no dejamos solicitudes a medias
            $solicitud->delete();
            return;
        }

        Tools::log()->notice('fa-signed-ok');
    }

    /**
     * Crea una solicitud de firma a distancia y, si se pide, envía la invitación.
     *
     * @param object $documento
     * @param object $request
     * @param object $user
     */
    private static function solicitar($documento, $request, $user): void
    {
        $solicitud = Firmador::crear($documento, [
            'nombre' => $request->input('fa_nombre', ''),
            'email' => $request->input('fa_email', ''),
            'nif' => $request->input('fa_nif', ''),
            'rol' => $request->input('fa_rol', ''),
            'requiere_otp' => (bool)$request->input('fa_otp', false),
            'dias' => $request->input('fa_dias', ''),
        ], $user->nick);
        if (null === $solicitud) {
            return;
        }

        Tools::log()->notice('fa-request-created');
        if ($request->input('fa_enviar', '') && false === empty($solicitud->email)) {
            if (Correo::invitacion($solicitud, $request->input('fa_asunto', '') ?? '', $request->input('fa_mensaje', '') ?? '', $user)) {
                Tools::log()->notice('fa-invite-sent', ['%email%' => $solicitud->email]);
            }
        }
    }

    /**
     * Datos por defecto del firmante: los del cliente del documento o del contrato.
     *
     * @param object $documento
     *
     * @return array nombre, email y nif.
     */
    private static function sujeto($documento): array
    {
        $datos = ['nombre' => '', 'email' => '', 'nif' => ''];
        if (method_exists($documento, 'getContacto') && $documento->getContacto()->exists()) {
            $contacto = $documento->getContacto();
            $datos = ['nombre' => trim($contacto->nombre . ' ' . $contacto->apellidos), 'email' => $contacto->email, 'nif' => $contacto->cifnif];
        }
        if (method_exists($documento, 'getCliente') && $documento->getCliente()->exists()) {
            $cliente = $documento->getCliente();
            $datos = ['nombre' => $datos['nombre'] ?: $cliente->nombre, 'email' => $datos['email'] ?: $cliente->email, 'nif' => $datos['nif'] ?: $cliente->cifnif];
        }
        if (isset($documento->nombrecliente)) {
            $datos['nombre'] = $datos['nombre'] ?: $documento->nombrecliente;
            $datos['nif'] = $datos['nif'] ?: $documento->cifnif;
            if (empty($datos['email']) && method_exists($documento, 'getSubject')) {
                $datos['email'] = (string)$documento->getSubject()->email;
            }
        }
        if (false === empty($documento->email)) {
            $datos['email'] = $documento->email;
        }

        foreach ($datos as $key => $value) {
            $datos[$key] = Tools::fixHtml((string)$value);
        }

        return $datos;
    }
}
