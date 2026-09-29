<?php
/**
 * This file is part of FirmarAhora plugin for FacturaScripts.
 * Copyright (C) 2026 Antonio Jesús González Domingo <antonio.gonzalez.domingo@proton.me>
 */

namespace FacturaScripts\Test\Plugins;

use FacturaScripts\Plugins\FirmarAhora\Lib\FirmarAhora\HtmlSeguro;
use PHPUnit\Framework\TestCase;

class HtmlSeguroTest extends TestCase
{
    public function testQuitaScriptsYEventos(): void
    {
        $html = HtmlSeguro::limpiar('<p onclick="alert(1)">Hola <script>alert(2)</script><b>mundo</b></p>'
            . '<a href="javascript:alert(3)">malo</a><a href="https://ejemplo.com">bueno</a><iframe src="x"></iframe>');

        $this->assertStringNotContainsString('onclick', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringNotContainsString('<iframe', $html);
        $this->assertStringContainsString('<b>mundo</b>', $html);
        $this->assertStringContainsString('href="https://ejemplo.com"', $html);
    }

    public function testConservaFormato(): void
    {
        $html = HtmlSeguro::limpiar('<h2 style="text-align:center">Título</h2><table border="1"><tr><td>a</td></tr></table>');

        $this->assertStringContainsString('<h2 style="text-align:center">', $html);
        $this->assertStringContainsString('<table border="1">', $html);
    }

    public function testQuitaEstilosPeligrosos(): void
    {
        $html = HtmlSeguro::limpiar('<p style="background:url(http://x)">texto</p>');
        $this->assertStringNotContainsString('url(', $html);
        $this->assertStringContainsString('texto', $html);
    }

    public function testVacio(): void
    {
        $this->assertSame('', HtmlSeguro::limpiar(''));
        $this->assertSame('', HtmlSeguro::limpiar(null));
    }
}
