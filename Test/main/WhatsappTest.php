<?php
/**
 * This file is part of FirmarAhora plugin for FacturaScripts.
 * Copyright (C) 2026 Antonio Jesús González Domingo <antonio.gonzalez.domingo@proton.me>
 */

namespace FacturaScripts\Test\Plugins;

use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\FirmarAhora\Lib\FirmarAhora\PanelFirmas;
use PHPUnit\Framework\TestCase;

class WhatsappTest extends TestCase
{
    public function testTelefonosConPrefijo(): void
    {
        $this->assertSame('34600123456', PanelFirmas::telefonoWhatsapp('+34 600 12 34 56'));
        $this->assertSame('525512345678', PanelFirmas::telefonoWhatsapp('0052 55 1234 5678'));
    }

    public function testMovilEspanolSinPrefijo(): void
    {
        Tools::settingsSet('default', 'codpais', 'ESP');
        $this->assertSame('34600123456', PanelFirmas::telefonoWhatsapp('600 123 456'));

        // un fijo o un número de otro país no se puede completar
        $this->assertSame('', PanelFirmas::telefonoWhatsapp('959 000 111'));
        $this->assertSame('', PanelFirmas::telefonoWhatsapp('5512345678'));
        $this->assertSame('', PanelFirmas::telefonoWhatsapp(''));
    }
}
