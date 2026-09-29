<?php
/**
 * This file is part of FirmarAhora plugin for FacturaScripts.
 * Copyright (C) 2026 Antonio Jesús González Domingo <antonio.gonzalez.domingo@proton.me>
 */

namespace FacturaScripts\Plugins\FirmarAhora\Controller;

use FacturaScripts\Core\Html;
use FacturaScripts\Core\Template\Controller;
use FacturaScripts\Core\Tools;
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

    /** @var ?bool Resultado de comparar el pdf subido: null si no se ha subido ninguno. */
    public $coincide = null;

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
            } else {
                Tools::log()->warning('fa-code-not-found');
            }
        }

        if ($this->solicitud && $this->request()->isMethod('POST') && $this->validateFormToken()) {
            $this->comprobarArchivo();
        }

        \FacturaScripts\Core\Lib\AssetManager::addCss(Tools::config('route') . '/Dinamic/Assets/CSS/FirmarAhora.css');
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
     * Compara la huella del pdf subido con las copias selladas y los documentos vistos
     * por los firmantes.
     */
    private function comprobarArchivo(): void
    {
        $archivo = $this->request()->file('pdf');
        if (null === $archivo || false === $archivo->isValid() || $archivo->getSize() > self::MAX_BYTES) {
            Tools::log()->warning('fa-upload-invalid');
            return;
        }

        $this->huellaSubida = hash_file('sha256', $archivo->getPathname());
        $this->coincide = false;
        foreach ($this->firmantes as $firmante) {
            if (in_array($this->huellaSubida, [$firmante->hash_sellado, $firmante->hash_original], true)) {
                $this->coincide = true;
                break;
            }
        }
    }
}
