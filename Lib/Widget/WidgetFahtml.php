<?php
/**
 * This file is part of FirmarAhora plugin for FacturaScripts.
 * Copyright (C) 2026 Antonio Jesús González Domingo <antonio.gonzalez.domingo@proton.me>
 */

namespace FacturaScripts\Plugins\FirmarAhora\Lib\Widget;

use FacturaScripts\Core\Lib\AssetManager;
use FacturaScripts\Core\Lib\Widget\WidgetTextarea;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Lib\FirmarAhora\Variables;

/**
 * Editor de texto enriquecido para contratos y plantillas: type="fahtml".
 *
 * El campo del formulario sigue siendo un textarea con el html; Assets/JS/FirmarAhoraEditor.js
 * le pone encima una barra de formato, un selector de variables y un modo código.
 */
class WidgetFahtml extends WidgetTextarea
{
    protected function assets()
    {
        $route = Tools::config('route');
        AssetManager::addCss($route . '/Dinamic/Assets/CSS/FirmarAhora.css');
        AssetManager::addJs($route . '/Dinamic/Assets/JS/FirmarAhoraEditor.js');
    }

    protected function inputHtml($type = 'text', $extraClass = '')
    {
        $datos = htmlspecialchars((string)json_encode([
            'variables' => Variables::disponibles(),
            'textos' => [
                'codigo' => Tools::trans('fa-editor-code'),
                'variable' => Tools::trans('fa-editor-variable'),
                'enlace' => Tools::trans('fa-editor-link'),
                'tabla' => Tools::trans('fa-editor-table'),
            ],
        ]), ENT_QUOTES, 'UTF-8');

        $class = $this->combineClasses($this->css('form-control'), $this->class, 'fa-editor-fuente', $extraClass);
        return '<textarea rows="' . $this->rows . '" name="' . $this->fieldname . '" class="' . $class . '"'
            . ' data-fa-editor="' . $datos . '"' . $this->inputHtmlExtraParams() . '>' . $this->value . '</textarea>';
    }
}
