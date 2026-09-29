<?php
/**
 * This file is part of FirmarAhora plugin for FacturaScripts.
 * Copyright (C) 2026 Antonio Jesús González Domingo <antonio.gonzalez.domingo@proton.me>
 */

namespace FacturaScripts\Test\Plugins;

use FacturaScripts\Dinamic\Model\ContratoFirma;
use FacturaScripts\Dinamic\Model\SolicitudFirma;
use FacturaScripts\Plugins\FirmarAhora\Lib\FirmarAhora\Firmador;
use PHPUnit\Framework\TestCase;

class SolicitudFirmaTest extends TestCase
{
    public function testCrearGeneraTokenYCodigo(): void
    {
        $contrato = $this->contrato();
        $solicitud = Firmador::crear($contrato, ['nombre' => 'Ana', 'email' => 'ana@ejemplo.com', 'dias' => 5], null);

        $this->assertNotNull($solicitud);
        $this->assertSame(48, strlen($solicitud->token));
        $this->assertMatchesRegularExpression('/^FA-[2-9A-HJ-NP-Z]{4}-[2-9A-HJ-NP-Z]{4}$/', $solicitud->codigo);
        $this->assertSame(SolicitudFirma::ESTADO_PENDIENTE, $solicitud->estado);
        $this->assertTrue($solicitud->estaAbierta());
        $this->assertCount(1, $solicitud->getEventos());

        $this->assertTrue($contrato->delete());
    }

    public function testEmailInvalido(): void
    {
        $contrato = $this->contrato();
        $this->assertNull(Firmador::crear($contrato, ['nombre' => 'Ana', 'email' => 'no-es-un-email'], null));
        $this->assertTrue($contrato->delete());
    }

    public function testOtpNecesitaEmail(): void
    {
        $contrato = $this->contrato();
        $this->assertNull(Firmador::crear($contrato, ['nombre' => 'Ana', 'requiere_otp' => true], null));
        $this->assertTrue($contrato->delete());
    }

    public function testFirmaRechazaDatosQueNoSonPng(): void
    {
        $contrato = $this->contrato();
        $solicitud = Firmador::crear($contrato, ['nombre' => 'Ana'], null);

        $ok = Firmador::firmar($solicitud, 'data:image/png;base64,' . base64_encode('<?php echo 1; ?>'), 'dibujada', 'Ana', '12345678Z', '127.0.0.1', 'test');
        $this->assertFalse($ok);
        $this->assertSame(SolicitudFirma::ESTADO_PENDIENTE, $solicitud->estado);

        $this->assertTrue($contrato->delete());
    }

    public function testAnularCierraLaSolicitud(): void
    {
        $contrato = $this->contrato();
        $solicitud = Firmador::crear($contrato, ['nombre' => 'Ana'], null);

        $this->assertTrue(Firmador::anular($solicitud, null));
        $this->assertFalse($solicitud->estaAbierta());
        $this->assertFalse(Firmador::anular($solicitud, null));

        $this->assertTrue($contrato->delete());
    }

    private function contrato(): ContratoFirma
    {
        $contrato = new ContratoFirma();
        $contrato->titulo = 'Contrato de prueba';
        $contrato->cuerpo = '<p>Texto {{firmas}}</p>';
        $this->assertTrue($contrato->save());
        return $contrato;
    }
}
