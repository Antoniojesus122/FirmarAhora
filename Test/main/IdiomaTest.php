<?php
/**
 * This file is part of FirmarAhora plugin for FacturaScripts.
 * Copyright (C) 2026 Antonio Jesús González Domingo <antonio.gonzalez.domingo@proton.me>
 */

namespace FacturaScripts\Test\Plugins;

use FacturaScripts\Plugins\FirmarAhora\Lib\FirmarAhora\Idioma;
use PHPUnit\Framework\TestCase;

class IdiomaTest extends TestCase
{
    public function testEligeEnPorOrdenDePreferencia(): void
    {
        $this->assertSame('en_EN', Idioma::elegir('en-GB,en;q=0.9,es;q=0.8', 'es_ES'));
        $this->assertSame('es_ES', Idioma::elegir('es-ES,es;q=0.9,en;q=0.8', 'es_ES'));
        $this->assertSame('en_EN', Idioma::elegir('fr-FR,fr;q=0.9,en;q=0.8', 'es_ES'));
    }

    public function testConservaLaVarianteDelSitio(): void
    {
        $this->assertSame('es_MX', Idioma::elegir('es-419,es;q=0.9', 'es_MX'));
        $this->assertSame('es_ES', Idioma::elegir('es', 'en_EN'));
    }

    public function testSinIdiomaConocidoSeQuedaElDelSitio(): void
    {
        $this->assertSame('es_ES', Idioma::elegir('fr-FR,de;q=0.8', 'es_ES'));
        $this->assertSame('es_ES', Idioma::elegir('', 'es_ES'));
    }
}
