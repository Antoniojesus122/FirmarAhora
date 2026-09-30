<?php
/**
 * This file is part of FirmarAhora plugin for FacturaScripts.
 * Copyright (C) 2026 Antonio Jesús González Domingo <antonio.gonzalez.domingo@proton.me>
 */

namespace FacturaScripts\Plugins\FirmarAhora\Controller;

use FacturaScripts\Core\Html;
use FacturaScripts\Core\Template\Controller;
use FacturaScripts\Core\Lib\AssetManager;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Lib\FirmarAhora\Huella;
use FacturaScripts\Dinamic\Lib\FirmarAhora\Idioma;
use FacturaScripts\Dinamic\Model\SolicitudFirma;

/**
 * Página pública de verificación: /VerificarFirma?c=CODIGO
 *
 * Con el código que aparece en el pdf firmado, cualquiera puede comprobar quién firmó y
 * cuándo, y subir el pdf para saber si es idéntico a la copia sellada (compara su huella
 * SHA-256; el archivo no se guarda).
 */
class VerificarFirma extends Controller
{
    /** @var int Tamaño máximo del pdf que se puede subir para comprobarlo. */
    const MAX_BYTES = 20971520;

    /** @var string */
    public $codigo = '';

    /** @var string Papel del firmante que vio el pdf subido, cuando el resultado es "vista". */
    public $coincideCon = '';

    /** @var bool El documento ya no tiene en el ERP los datos que tenía al firmarse. */
    public $modificado = false;

    /** @var string Resultado de comparar el pdf subido: sellada, vista, distinto o vacío si no se ha subido. */
    public $resultado = '';

    /** @var string Huella del pdf subido. */
    public $huellaSubida = '';

    /** @var ?SolicitudFirma */
    public $solicitud = null;

    /** @var SolicitudFirma[] Todos los firmantes del documento. */
    public $firmantes = [];

    public function __construct(string $className, string $url = '')
    {
        parent::__construct($className, $url);
        $this->requiresAuth = false;
    }

    public function getPageData(): array
    {
        return [];
    }

    public function run(): void
    {
        parent::run();
        Idioma::delFirmante();
        $this->title = Tools::trans('fa-verify');

        $this->codigo = strtoupper(trim((string)$this->request()->inputOrQuery('c', '')));
        if ($this->codigo !== '') {
            $solicitud = new SolicitudFirma();
            if ($solicitud->loadWhereEq('codigo', $this->codigo)) {
                $this->solicitud = $solicitud;
                $this->firmantes = array_values(array_filter(
                    SolicitudFirma::delDocumento($solicitud->doc_model, $solicitud->doc_code),
                    function ($firmante) {
                        return $firmante->estado !== SolicitudFirma::ESTADO_ANULADA;
                    }
                ));
                $this->comprobarCambios();
            } else {
                Tools::log()->warning('fa-code-not-found');
            }
        }

        if ($this->solicitud && $this->request()->isMethod('POST') && $this->validateFormToken()) {
            $this->comprobarArchivo();
        }

        AssetManager::addCss(Tools::config('route') . '/Dinamic/Assets/CSS/FirmarAhora.css');
        echo Html::render('VerificarFirma.html.twig', [
            'controllerName' => 'VerificarFirma',
            'debugBarRender' => false,
            'fsc' => $this,
            'template' => 'VerificarFirma.html.twig',
        ]);
    }

    protected function auth(): bool
    {
        return false;
    }

    /**
     * Compara la huella del pdf subido con las copias selladas y, si no es ninguna, con el
     * documento que vio cada firmante antes de firmar. Son cosas distintas: el segundo no
     * lleva las firmas.
     */
    private function comprobarArchivo(): void
    {
        $archivo = $this->request()->file('pdf');
        if (null === $archivo || false === $archivo->isValid() || $archivo->getSize() > self::MAX_BYTES) {
            Tools::log()->warning('fa-upload-invalid');
            return;
        }

        $this->huellaSubida = hash_file('sha256', $archivo->getPathname());
        $this->resultado = 'distinto';
        foreach ($this->firmantes as $firmante) {
            if (false === empty($firmante->hash_sellado) && hash_equals($firmante->hash_sellado, $this->huellaSubida)) {
                $this->resultado = 'sellada';
                return;
            }
        }

        foreach ($this->firmantes as $firmante) {
            if (false === empty($firmante->hash_original) && hash_equals($firmante->hash_original, $this->huellaSubida)) {
                $this->resultado = 'vista';
                $this->coincideCon = (string)$firmante->rol;
                return;
            }
        }
    }

    /**
     * Mira si el documento sigue teniendo los datos que tenía cuando firmó cada firmante.
     */
    private function comprobarCambios(): void
    {
        $documento = $this->solicitud->getDocumento();
        if (null === $documento) {
            return;
        }

        foreach ($this->firmantes as $firmante) {
            if ($firmante->estado === SolicitudFirma::ESTADO_FIRMADA && Huella::cambiado($firmante, $documento)) {
                $this->modificado = true;
                return;
            }
        }
    }
}
