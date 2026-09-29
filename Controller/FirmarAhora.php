<?php
/**
 * This file is part of FirmarAhora plugin for FacturaScripts.
 * Copyright (C) 2026 Antonio Jesús González Domingo <antonio.gonzalez.domingo@proton.me>
 */

namespace FacturaScripts\Plugins\FirmarAhora\Controller;

use FacturaScripts\Core\DataSrc\Empresas;
use FacturaScripts\Core\Html;
use FacturaScripts\Core\Lib\AssetManager;
use FacturaScripts\Core\Template\Controller;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Lib\FirmarAhora\Ajustes;
use FacturaScripts\Dinamic\Lib\FirmarAhora\Firmador;
use FacturaScripts\Dinamic\Lib\FirmarAhora\Idioma;
use FacturaScripts\Dinamic\Lib\FirmarAhora\Otp;
use FacturaScripts\Dinamic\Lib\FirmarAhora\PanelFirmas;
use FacturaScripts\Dinamic\Lib\FirmarAhora\Sellador;
use FacturaScripts\Dinamic\Model\ContratoFirma;
use FacturaScripts\Dinamic\Model\SolicitudFirma;

/**
 * Página pública de firma: /FirmarAhora?t=TOKEN
 *
 * El firmante no necesita cuenta. Ve el documento, se identifica (con código por email
 * si la solicitud lo pide), acepta las condiciones y firma, o rechaza indicando el motivo.
 * Después puede descargar la copia sellada y consultar el código de verificación.
 */
class FirmarAhora extends Controller
{
    /** @var ?object Documento que se firma. */
    public $documento;

    /** @var bool True si el enlace no es válido. */
    public $error = false;

    /** @var string Html del documento, en los contratos. */
    public $html = '';

    /** @var SolicitudFirma */
    public $solicitud;

    public function __construct(string $className, string $url = '')
    {
        parent::__construct($className, $url);
        $this->requiresAuth = false;
    }

    /**
     * Página pública: fuera del menú y de la lista de permisos.
     *
     * @return array
     */
    public function getPageData(): array
    {
        return [];
    }

    /**
     * @return bool La solicitud pide código y aún no se ha verificado.
     */
    public function necesitaCodigo(): bool
    {
        return $this->solicitud->requiere_otp && false === $this->solicitud->otp_verificado;
    }

    /**
     * Otras solicitudes del mismo documento, para mostrar quién más firma.
     *
     * @return SolicitudFirma[]
     */
    public function otrosFirmantes(): array
    {
        return array_values(array_filter(
            SolicitudFirma::delDocumento($this->solicitud->doc_model, $this->solicitud->doc_code),
            function ($otra) {
                return $otra->id !== $this->solicitud->id && $otra->estado !== SolicitudFirma::ESTADO_ANULADA;
            }
        ));
    }

    /**
     * @return bool Se pide la ubicación al firmar.
     */
    public function pedirUbicacion(): bool
    {
        return Ajustes::pedirUbicacion();
    }

    public function textoLegal(): string
    {
        return Ajustes::textoLegal();
    }

    /**
     * @param string $action
     *
     * @return string Url de esta página, con una acción opcional.
     */
    public function urlPropia(string $action = ''): string
    {
        $url = Tools::config('route') . '/FirmarAhora?t=' . $this->solicitud->token;
        return $action === '' ? $url : $url . '&action=' . $action;
    }

    public function run(): void
    {
        parent::run();
        Idioma::delFirmante();

        $this->solicitud = new SolicitudFirma();
        $token = (string)$this->request()->query('t', '');
        if (strlen($token) < 32 || false === $this->solicitud->loadWhereEq('token', $token)
            || null === ($this->documento = $this->solicitud->getDocumento())) {
            $this->error = true;
            $this->title = Tools::trans('fa-signature');
            $this->mostrar();
            return;
        }

        $this->title = Tools::fixHtml((string)$this->solicitud->doc_titulo);
        if (false === empty($this->documento->idempresa)) {
            $this->empresa = Empresas::get($this->documento->idempresa);
        }

        $ip = $this->request()->ip();
        $agente = $this->request()->userAgent();
        $this->marcarVista($ip, $agente);

        $action = (string)$this->request()->inputOrQuery('action', '');
        switch ($action) {
            case 'pdf':
                $this->response()->pdf(Sellador::pdf($this->documento, false), Sellador::nombreArchivo($this->documento));
                return;

            case 'sellado':
                $this->descargarSellado();
                return;

            case 'enviar-codigo':
                if ($this->validateFormToken() && Otp::enviar($this->solicitud, $ip, $agente)) {
                    Tools::log()->notice('fa-otp-sent', ['%email%' => $this->solicitud->emailOculto()]);
                }
                break;

            case 'comprobar-codigo':
                if ($this->validateFormToken() && Otp::comprobar($this->solicitud, (string)$this->request()->input('codigo', ''), $ip, $agente)) {
                    Tools::log()->notice('fa-otp-ok');
                }
                break;

            case 'firmar':
                $this->firmar($ip, $agente);
                break;

            case 'rechazar':
                if ($this->validateFormToken()
                    && Firmador::rechazar($this->solicitud, (string)$this->request()->input('motivo', ''), $ip, $agente)) {
                    Tools::log()->notice('fa-rejected-ok');
                }
                break;
        }

        if ($this->documento instanceof ContratoFirma) {
            $this->documento->reload();
            $this->html = $this->documento->renderHtml(false);
        }

        if ($this->solicitud->estaAbierta()) {
            PanelFirmas::recursos();
            if ($this->pedirUbicacion()) {
                AssetManager::addJs(Tools::config('route') . '/Dinamic/Assets/JS/FirmarAhoraUbicacion.js');
            }
        } else {
            AssetManager::addCss(Tools::config('route') . '/Dinamic/Assets/CSS/FirmarAhora.css');
        }

        $this->mostrar();
    }

    /**
     * La página es pública y no usa el usuario: sin esto, quien la abriera con una sesión
     * caducada del ERP vería el aviso de cookie no válida.
     *
     * @return bool
     */
    protected function auth(): bool
    {
        return false;
    }

    private function descargarSellado(): void
    {
        $ruta = FS_FOLDER . '/' . $this->solicitud->sellado_path;
        if ($this->solicitud->estado !== SolicitudFirma::ESTADO_FIRMADA || empty($this->solicitud->sellado_path) || false === is_file($ruta)) {
            $this->response()->setHttpCode(404)->setContent('')->send();
            return;
        }

        $this->response()
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'attachment; filename="firmado_' . $this->solicitud->codigo . '.pdf"')
            ->setContent((string)file_get_contents($ruta))
            ->send();
    }

    private function firmar(string $ip, string $agente): void
    {
        if (false === $this->validateFormToken()) {
            return;
        }

        $nombre = trim((string)$this->request()->input('fa_nombre', ''));
        $nif = trim((string)$this->request()->input('fa_nif', ''));
        if ($nombre === '' || $nif === '') {
            Tools::log()->warning('fa-identity-required');
            return;
        }

        if ('1' !== $this->request()->input('fa_acepto', '')) {
            Tools::log()->warning('fa-accept-required');
            return;
        }

        $ubicacion = [];
        if ($this->pedirUbicacion()) {
            $ubicacion = [
                'estado' => (string)$this->request()->input('fa_geo_estado', ''),
                'lat' => $this->request()->input('fa_geo_lat', ''),
                'lon' => $this->request()->input('fa_geo_lon', ''),
                'precision' => $this->request()->input('fa_geo_precision', ''),
            ];
        }

        $ok = Firmador::firmar(
            $this->solicitud,
            (string)$this->request()->input('fa_firma', ''),
            (string)$this->request()->input('fa_tipo', ''),
            $nombre,
            $nif,
            $ip,
            $agente,
            null,
            $ubicacion
        );
        if ($ok) {
            Tools::log()->notice('fa-signed-ok');
        }
    }

    /**
     * La primera vez que se abre el enlace, la solicitud pasa a "vista".
     *
     * @param string $ip
     * @param string $agente
     */
    private function marcarVista(string $ip, string $agente): void
    {
        if ($this->solicitud->estado === SolicitudFirma::ESTADO_PENDIENTE && $this->solicitud->estaAbierta()) {
            $this->solicitud->estado = SolicitudFirma::ESTADO_VISTA;
            $this->solicitud->save();
            $this->solicitud->registrar('abierta', '', Firmador::ip($ip), $agente);
        }
    }

    private function mostrar(): void
    {
        echo Html::render('FirmarAhora.html.twig', [
            'controllerName' => 'FirmarAhora',
            'debugBarRender' => false,
            'fsc' => $this,
            'template' => 'FirmarAhora.html.twig',
        ]);
    }
}
