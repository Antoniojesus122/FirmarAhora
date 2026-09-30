<?php
/**
 * This file is part of FirmarAhora plugin for FacturaScripts.
 * Copyright (C) 2026 Antonio Jesús González Domingo <antonio.gonzalez.domingo@proton.me>
 */

namespace FacturaScripts\Test\Plugins;

use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Model\ContratoFirma;
use FacturaScripts\Dinamic\Model\SolicitudFirma;
use FacturaScripts\Plugins\FirmarAhora\Lib\FirmarAhora\Conexion;
use FacturaScripts\Plugins\FirmarAhora\Lib\FirmarAhora\Firmador;
use FacturaScripts\Plugins\FirmarAhora\Lib\FirmarAhora\Huella;
use FacturaScripts\Plugins\FirmarAhora\Lib\FirmarAhora\Otp;
use PHPUnit\Framework\TestCase;

class SeguridadTest extends TestCase
{
    public function testLaIpNoSaleDeCabecerasQueEscribeElCliente(): void
    {
        $_SERVER['REMOTE_ADDR'] = '10.0.0.5';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4';
        Tools::settingsSet('firmarahora', 'cabecera_ip', '');

        $this->assertSame('10.0.0.5', Conexion::ip());
        $this->assertStringContainsString('1.2.3.4', Conexion::proxy());
    }

    public function testConProxyDeConfianzaSeUsaLoQueAnadeElProxy(): void
    {
        $_SERVER['REMOTE_ADDR'] = '10.0.0.5';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '9.9.9.9, 1.2.3.4';
        Tools::settingsSet('firmarahora', 'cabecera_ip', 'x-forwarded-for');

        $this->assertSame('1.2.3.4', Conexion::ip());
        $this->assertSame('', Conexion::proxy());

        Tools::settingsSet('firmarahora', 'cabecera_ip', '');
    }

    public function testLaHuellaCambiaSiCambiaUnaLinea(): void
    {
        $documento = $this->documento(100);
        $huella = Huella::contenido($documento);

        $this->assertSame($huella, Huella::contenido($this->documento(100.0)));
        $this->assertNotSame($huella, Huella::contenido($this->documento(101)));
    }

    public function testElNifTieneQueSerElEsperado(): void
    {
        $contrato = $this->contrato();
        $solicitud = Firmador::crear($contrato, ['nombre' => 'Ana', 'nif' => '12345678-Z'], null);

        $this->assertFalse(Firmador::firmar($solicitud, $this->png(200, 80), 'dibujada', 'Ana', '00000000T', '127.0.0.1', 'test'));
        $this->assertTrue($solicitud->estaAbierta());
        $this->assertSame('nif_error', $solicitud->getEventos()[1]->tipo);

        $this->assertTrue($contrato->delete());
    }

    public function testUnCodigoVerificadoEnOtroNavegadorNoSirve(): void
    {
        $contrato = $this->contrato();
        $solicitud = Firmador::crear($contrato, ['nombre' => 'Ana', 'email' => 'ana@ejemplo.com', 'requiere_otp' => true], null);
        $solicitud->otp_verificado = true;
        $solicitud->otp_sesion = (time() + 3600) . ':' . hash('sha256', 'secreto-de-otro-navegador');
        $this->assertTrue($solicitud->save());

        $this->assertFalse(Otp::sesionValida($solicitud));
        $this->assertFalse(Firmador::firmar($solicitud, $this->png(200, 80), 'dibujada', 'Ana', '12345678Z', '127.0.0.1', 'test'));
        $this->assertTrue($solicitud->estaAbierta());

        $this->assertTrue($contrato->delete());
    }

    public function testLimiteDeCodigosYSolicitudCerrada(): void
    {
        $contrato = $this->contrato();
        $solicitud = Firmador::crear($contrato, ['nombre' => 'Ana', 'email' => 'ana@ejemplo.com', 'requiere_otp' => true], null);
        $solicitud->otp_envios = Otp::MAX_ENVIOS;
        $this->assertTrue($solicitud->save());
        $this->assertFalse(Otp::enviar($solicitud, '127.0.0.1', 'test'));

        $solicitud->otp_envios = 0;
        $this->assertTrue($solicitud->save());
        $this->assertTrue(Firmador::anular($solicitud, null));
        $this->assertFalse(Otp::enviar($solicitud, '127.0.0.1', 'test'));
        $this->assertFalse(Otp::comprobar($solicitud, '123456', '127.0.0.1', 'test'));

        $this->assertTrue($contrato->delete());
    }

    public function testUnaImagenEnormeSeRechaza(): void
    {
        $contrato = $this->contrato();
        $solicitud = Firmador::crear($contrato, ['nombre' => 'Ana'], null);

        $grande = $this->png(Firmador::MAX_ANCHO + 1, 50);
        $this->assertFalse(Firmador::firmar($solicitud, $grande, 'dibujada', 'Ana', '12345678Z', '127.0.0.1', 'test'));
        $this->assertTrue($solicitud->estaAbierta());

        $this->assertTrue($contrato->delete());
    }

    private function contrato(): ContratoFirma
    {
        $contrato = new ContratoFirma();
        $contrato->titulo = 'Contrato de prueba';
        $contrato->cuerpo = '<p>Texto</p>';
        $this->assertTrue($contrato->save());
        return $contrato;
    }

    /**
     * @param mixed $precio
     *
     * @return object Documento de venta mínimo, con una línea.
     */
    private function documento($precio): object
    {
        return new class($precio) {
            public $codigo = 'FAC1';
            public $fecha = '01-01-2026';
            public $cifnif = '12345678Z';
            public $nombrecliente = 'Ana';
            public $neto = 100;
            public $total = 121;
            private $precio;

            public function __construct($precio)
            {
                $this->precio = $precio;
            }

            public function getLines(): array
            {
                return [(object)['referencia' => 'A1', 'descripcion' => 'Servicio', 'cantidad' => 1, 'pvpunitario' => $this->precio]];
            }
        };
    }

    private function png(int $ancho, int $alto): string
    {
        $imagen = imagecreatetruecolor($ancho, $alto);
        imageline($imagen, 5, 5, $ancho - 5, $alto - 5, imagecolorallocate($imagen, 255, 255, 255));
        ob_start();
        imagepng($imagen);
        return 'data:image/png;base64,' . base64_encode((string)ob_get_clean());
    }
}
