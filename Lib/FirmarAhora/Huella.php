<?php
/**
 * This file is part of FirmarAhora plugin for FacturaScripts.
 * Copyright (C) 2026 Antonio Jesús González Domingo <antonio.gonzalez.domingo@proton.me>
 */

namespace FacturaScripts\Plugins\FirmarAhora\Lib\FirmarAhora;

use FacturaScripts\Dinamic\Model\ContratoFirma;
use FacturaScripts\Dinamic\Model\SolicitudFirma;

/**
 * Huella de los datos de un documento: cliente, importes y líneas. Se guarda al firmar
 * para poder avisar si el documento se modifica después. La copia sellada no cambia; el
 * aviso indica que lo que hay ahora en el ERP ya no es lo que se firmó.
 */
class Huella
{
    const CABECERA = ['codigo', 'fecha', 'cifnif', 'nombrecliente', 'direccion', 'coddivisa', 'observaciones'];
    const IMPORTES = ['neto', 'totaliva', 'totalirpf', 'totalrecargo', 'total'];
    const LINEA = ['referencia', 'descripcion'];
    const LINEA_IMPORTES = ['cantidad', 'pvpunitario', 'dtopor', 'dtopor2', 'iva', 'irpf', 'recargo', 'pvptotal'];

    /**
     * El documento ya no tiene los datos que tenía cuando se firmó.
     *
     * @param SolicitudFirma $solicitud
     * @param object $documento
     *
     * @return bool
     */
    public static function cambiado(SolicitudFirma $solicitud, $documento): bool
    {
        return false === empty($solicitud->hash_contenido) && $solicitud->hash_contenido !== self::contenido($documento);
    }

    /**
     * @param object $documento Documento de venta o ContratoFirma.
     *
     * @return string SHA-256 de sus datos.
     */
    public static function contenido($documento): string
    {
        if ($documento instanceof ContratoFirma) {
            return hash('sha256', json_encode([(string)$documento->titulo, (string)$documento->cuerpo]));
        }

        $datos = ['cabecera' => [], 'lineas' => []];
        foreach (self::CABECERA as $campo) {
            $datos['cabecera'][$campo] = trim((string)($documento->{$campo} ?? ''));
        }
        foreach (self::IMPORTES as $campo) {
            $datos['cabecera'][$campo] = self::numero($documento->{$campo} ?? 0);
        }

        $lineas = method_exists($documento, 'getLines') ? $documento->getLines() : [];
        foreach ($lineas as $linea) {
            $fila = [];
            foreach (self::LINEA as $campo) {
                $fila[] = trim((string)($linea->{$campo} ?? ''));
            }
            foreach (self::LINEA_IMPORTES as $campo) {
                $fila[] = self::numero($linea->{$campo} ?? 0);
            }
            $datos['lineas'][] = $fila;
        }

        return hash('sha256', json_encode($datos));
    }

    /**
     * @param mixed $valor
     *
     * @return string El número con un formato fijo, para que 10 y 10.0 den la misma huella.
     */
    private static function numero($valor): string
    {
        return number_format((float)$valor, 5, '.', '');
    }
}
