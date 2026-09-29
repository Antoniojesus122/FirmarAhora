<?php
/**
 * This file is part of FirmarAhora plugin for FacturaScripts.
 * Copyright (C) 2026 Antonio Jesús González Domingo <antonio.gonzalez.domingo@proton.me>
 */

namespace FacturaScripts\Plugins\FirmarAhora;

use FacturaScripts\Core\Template\CronClass;
use FacturaScripts\Dinamic\Lib\FirmarAhora\Recordatorios;

/**
 * Tareas periódicas: caducar las solicitudes vencidas y enviar recordatorios a los
 * firmantes que todavía no han firmado.
 */
class Cron extends CronClass
{
    public function run(): void
    {
        $this->job('firmarahora-recordatorios')
            ->every('1 hour')
            ->run(function () {
                Recordatorios::caducarVencidas();
                Recordatorios::enviarPendientes();
            });
    }
}
