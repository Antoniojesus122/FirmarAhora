<?php
/**
 * This file is part of FirmarAhora plugin for FacturaScripts.
 * Copyright (C) 2026 Antonio Jesús González Domingo <antonio.gonzalez.domingo@proton.me>
 */

namespace FacturaScripts\Plugins\FirmarAhora\Lib\FirmarAhora;

use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Model\SolicitudFirma;

/**
 * Tareas del cron: caducar solicitudes vencidas y recordar las pendientes.
 */
class Recordatorios
{
    /** @var int Solicitudes como máximo por ejecución, para no saturar el servidor de correo. */
    const LOTE = 50;

    /**
     * Pasa a caducadas las solicitudes abiertas cuya fecha de caducidad ya pasó.
     *
     * @return int Número de solicitudes caducadas.
     */
    public static function caducarVencidas(): int
    {
        $where = [
            Where::in('estado', [SolicitudFirma::ESTADO_PENDIENTE, SolicitudFirma::ESTADO_VISTA]),
            Where::isNotNull('caduca'),
            Where::lt('caduca', date('Y-m-d')),
        ];

        $total = 0;
        foreach (SolicitudFirma::all($where, ['id' => 'ASC'], 0, self::LOTE) as $solicitud) {
            $solicitud->estado = SolicitudFirma::ESTADO_CADUCADA;
            if ($solicitud->save()) {
                $solicitud->registrar('caducada');
                $total++;
            }
        }

        return $total;
    }

    /**
     * Envía un recordatorio a las solicitudes abiertas que llevan RECORDATORIO_DIAS sin
     * respuesta desde el último envío, hasta el máximo de recordatorios configurado.
     *
     * @return int Número de recordatorios enviados.
     */
    public static function enviarPendientes(): int
    {
        $dias = Ajustes::recordatorioDias();
        $max = Ajustes::recordatoriosMax();
        if ($dias <= 0 || $max <= 0) {
            return 0;
        }

        $where = [
            Where::in('estado', [SolicitudFirma::ESTADO_PENDIENTE, SolicitudFirma::ESTADO_VISTA]),
            Where::eq('presencial', false),
            Where::isNotNull('email'),
            Where::isNotNull('enviado'),
            Where::lt('recordatorios', $max),
            Where::lt('enviado', date('Y-m-d H:i:s', strtotime('-' . $dias . ' days'))),
        ];

        $total = 0;
        foreach (SolicitudFirma::all($where, ['enviado' => 'ASC'], 0, self::LOTE) as $solicitud) {
            if ($solicitud->estaAbierta() && Correo::invitacion($solicitud, '', '', null, true)) {
                $total++;
            }
        }

        if ($total > 0) {
            Tools::log('firmarahora')->info('fa-reminders-sent', ['%count%' => $total]);
        }

        return $total;
    }
}
