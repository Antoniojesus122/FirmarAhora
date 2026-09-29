<?php
/**
 * This file is part of FirmarAhora plugin for FacturaScripts.
 * Copyright (C) 2026 Antonio Jesús González Domingo <antonio.gonzalez.domingo@proton.me>
 */

namespace FacturaScripts\Plugins\FirmarAhora\Lib\FirmarAhora;

use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Model\EstadoDocumento;
use FacturaScripts\Dinamic\Model\PresupuestoCliente;
use FacturaScripts\Dinamic\Model\SolicitudFirma;

/**
 * Operaciones sobre las solicitudes de firma: crearlas, firmarlas, rechazarlas,
 * anularlas y copiarlas a otro documento. Todas dejan rastro en el registro de
 * auditoría y avisan de los errores con Tools::log().
 */
class Firmador
{
    const CARPETA_FIRMAS = 'MyFiles/FirmarAhora/firmas/';

    /** @var int Tamaño máximo de la imagen de firma. */
    const MAX_BYTES = 2097152;

    /** @var string[] Formas de firmar admitidas. */
    const TIPOS = ['dibujada', 'escrita', 'imagen'];

    /**
     * Anula una solicitud abierta: el enlace deja de admitir la firma.
     *
     * @param SolicitudFirma $solicitud
     * @param ?string $nick
     *
     * @return bool
     */
    public static function anular(SolicitudFirma $solicitud, ?string $nick): bool
    {
        if (false === in_array($solicitud->estado, [SolicitudFirma::ESTADO_PENDIENTE, SolicitudFirma::ESTADO_VISTA], true)) {
            Tools::log()->warning('fa-request-closed');
            return false;
        }

        $solicitud->estado = SolicitudFirma::ESTADO_ANULADA;
        if (false === $solicitud->save()) {
            return false;
        }

        $solicitud->registrar('anulada', '', '', '', $nick);
        return true;
    }

    /**
     * Copia las firmas de un documento a otro, al convertirlo (albarán a factura, etc.).
     * Se copian como firmas ya hechas, con una imagen propia y un evento que indica de
     * qué documento vienen. La copia sellada no se copia: pertenece al documento original.
     *
     * @param object $origen
     * @param object $destino
     */
    public static function copiarFirmas($origen, $destino): void
    {
        $codigoDestino = (string)$destino->primaryColumnValue();
        if (empty($codigoDestino) || false === in_array($destino->modelClassName(), \FacturaScripts\Plugins\FirmarAhora\Init::DOCUMENTOS, true) || false === empty(SolicitudFirma::delDocumento($destino->modelClassName(), $codigoDestino))) {
            return;
        }

        $firmadas = array_filter(
            SolicitudFirma::delDocumento($origen->modelClassName(), (string)$origen->primaryColumnValue()),
            function ($solicitud) {
                return $solicitud->estado === SolicitudFirma::ESTADO_FIRMADA;
            }
        );

        foreach ($firmadas as $original) {
            $copia = new SolicitudFirma();
            foreach (['rol', 'orden', 'nombre', 'email', 'nif', 'estado', 'presencial', 'requiere_otp', 'otp_verificado',
                         'firma_tipo', 'firmado', 'firmante_nombre', 'firmante_nif', 'ip', 'user_agent', 'hash_original'] as $field) {
                $copia->{$field} = $original->{$field};
            }
            $copia->doc_model = $destino->modelClassName();
            $copia->doc_code = $codigoDestino;
            $copia->doc_titulo = mb_substr(Sellador::titulo($destino), 0, 150);
            $copia->nick = $original->nick;
            if (false === $copia->save()) {
                continue;
            }

            $ruta = self::CARPETA_FIRMAS . $copia->token . '.png';
            if (file_exists(FS_FOLDER . '/' . $original->firma_path) && copy(FS_FOLDER . '/' . $original->firma_path, FS_FOLDER . '/' . $ruta)) {
                $copia->firma_path = $ruta;
                $copia->save();
            }

            $copia->registrar('copiada', Tools::trans('fa-copied-from', ['%doc%' => $original->doc_titulo, '%code%' => $original->codigo]));
        }
    }

    /**
     * Crea una solicitud de firma para un documento.
     *
     * @param object $documento Documento ya guardado.
     * @param array $datos nombre, email, nif, rol, requiere_otp y dias (validez).
     * @param ?string $nick Usuario que la crea.
     *
     * @return ?SolicitudFirma
     */
    public static function crear($documento, array $datos, ?string $nick): ?SolicitudFirma
    {
        $existentes = SolicitudFirma::delDocumento($documento->modelClassName(), (string)$documento->primaryColumnValue());

        $solicitud = new SolicitudFirma();
        $solicitud->doc_model = $documento->modelClassName();
        $solicitud->doc_code = (string)$documento->primaryColumnValue();
        $solicitud->doc_titulo = mb_substr(Sellador::titulo($documento), 0, 150);
        $solicitud->orden = count($existentes) + 1;
        $solicitud->nombre = $datos['nombre'] ?? '';
        $solicitud->email = $datos['email'] ?? '';
        $solicitud->nif = $datos['nif'] ?? '';
        $solicitud->rol = empty($datos['rol']) ? Tools::trans('customer') : $datos['rol'];
        $solicitud->requiere_otp = (bool)($datos['requiere_otp'] ?? false);
        $solicitud->nick = $nick;

        $dias = isset($datos['dias']) && $datos['dias'] !== '' ? (int)$datos['dias'] : Ajustes::diasValidez();
        $solicitud->caduca = $dias > 0 ? date('Y-m-d', strtotime('+' . $dias . ' days')) : null;

        if ($solicitud->requiere_otp && empty($solicitud->email)) {
            Tools::log()->warning('fa-otp-needs-email');
            return null;
        }

        if (false === $solicitud->save()) {
            return null;
        }

        $solicitud->registrar('creada', $solicitud->rol, '', '', $nick);
        return $solicitud;
    }

    /**
     * Firma una solicitud: guarda la imagen y las evidencias, y genera la copia sellada.
     *
     * @param SolicitudFirma $solicitud
     * @param string $dataUrl Imagen png en data url.
     * @param string $tipo dibujada, escrita o imagen.
     * @param string $nombre Nombre que escribe el firmante.
     * @param string $nif Documento de identidad que escribe el firmante.
     * @param string $ip
     * @param string $userAgent
     * @param ?string $nick Usuario del ERP, en las firmas presenciales.
     * @param array $ubicacion estado, lat, lon y precision, si se pidió la ubicación.
     *
     * @return bool
     */
    public static function firmar(SolicitudFirma $solicitud, string $dataUrl, string $tipo, string $nombre, string $nif,
                                  string $ip, string $userAgent, ?string $nick = null, array $ubicacion = []): bool
    {
        if (false === $solicitud->estaAbierta()) {
            Tools::log()->warning('fa-request-closed');
            return false;
        }

        if ($solicitud->requiere_otp && false === $solicitud->otp_verificado && false === $solicitud->presencial) {
            Tools::log()->warning('fa-otp-required');
            return false;
        }

        if (trim($nombre) === '') {
            Tools::log()->warning('fa-signer-name-required');
            return false;
        }

        $png = self::decodificarPng($dataUrl);
        $documento = $solicitud->getDocumento();
        if (null === $png || null === $documento) {
            return false;
        }

        // huella del documento tal y como lo ve el firmante, antes de añadir su firma
        $solicitud->hash_original = hash('sha256', Sellador::pdf($documento, false));

        if (false === Tools::folderCheckOrCreate(FS_FOLDER . '/' . self::CARPETA_FIRMAS)) {
            Tools::log()->error('fa-file-error');
            return false;
        }
        $ruta = self::CARPETA_FIRMAS . $solicitud->token . '.png';
        if (false === file_put_contents(FS_FOLDER . '/' . $ruta, $png)) {
            Tools::log()->error('fa-file-error');
            return false;
        }

        $solicitud->firma_path = $ruta;
        $solicitud->firma_tipo = in_array($tipo, self::TIPOS, true) ? $tipo : 'dibujada';
        $solicitud->firmante_nombre = $nombre;
        $solicitud->firmante_nif = $nif;
        $solicitud->firmado = Tools::dateTime();
        $solicitud->ip = self::ip($ip);
        $solicitud->user_agent = $userAgent;
        $solicitud->estado = SolicitudFirma::ESTADO_FIRMADA;
        self::ubicar($solicitud, $ubicacion);
        if (false === $solicitud->save()) {
            return false;
        }

        $solicitud->registrar('firmada', 'SHA-256 ' . $solicitud->hash_original, $solicitud->ip, $userAgent, $nick);

        if (Sellador::sellar($solicitud, $documento)) {
            $solicitud->registrar('sellada', 'SHA-256 ' . $solicitud->hash_sellado, '', '', $nick);
        }

        if (Ajustes::copiaFirmante() && false === empty($solicitud->email)) {
            Correo::copiaFirmada($solicitud);
        }
        if (Ajustes::avisarEmisor() && false === $solicitud->presencial) {
            Correo::avisoEmisor($solicitud);
        }

        self::avanzarPresupuesto($solicitud, $documento, $nick);
        return true;
    }

    /**
     * Primera ip válida de la cabecera (detrás de un proxy llega "cliente, proxy1...").
     *
     * @param string $ip
     *
     * @return string
     */
    public static function ip(string $ip): string
    {
        foreach (explode(',', $ip) as $candidata) {
            $candidata = trim($candidata);
            if (filter_var($candidata, FILTER_VALIDATE_IP)) {
                return $candidata;
            }
        }

        return '';
    }

    /**
     * El firmante rechaza firmar, indicando el motivo.
     *
     * @param SolicitudFirma $solicitud
     * @param string $motivo
     * @param string $ip
     * @param string $userAgent
     *
     * @return bool
     */
    public static function rechazar(SolicitudFirma $solicitud, string $motivo, string $ip, string $userAgent): bool
    {
        if (false === $solicitud->estaAbierta()) {
            Tools::log()->warning('fa-request-closed');
            return false;
        }

        if (trim($motivo) === '') {
            Tools::log()->warning('fa-reason-required');
            return false;
        }

        $solicitud->estado = SolicitudFirma::ESTADO_RECHAZADA;
        $solicitud->motivo_rechazo = $motivo;
        $solicitud->ip = self::ip($ip);
        $solicitud->user_agent = $userAgent;
        if (false === $solicitud->save()) {
            return false;
        }

        $solicitud->registrar('rechazada', $motivo, $solicitud->ip, $userAgent);
        if (Ajustes::avisarEmisor()) {
            Correo::avisoEmisor($solicitud);
        }

        return true;
    }

    /**
     * Cuando ya han firmado todos los firmantes de un presupuesto abierto, lo pasa al estado
     * que genera el pedido o la factura, según los ajustes. El documento nuevo recibe una
     * copia de las firmas al generarse.
     *
     * @param SolicitudFirma $solicitud
     * @param object $documento
     * @param ?string $nick
     */
    private static function avanzarPresupuesto(SolicitudFirma $solicitud, $documento, ?string $nick): void
    {
        $destino = ['pedido' => 'PedidoCliente', 'factura' => 'FacturaCliente'][Ajustes::presupuestoFirmado()] ?? '';
        if ($destino === '' || false === $documento instanceof PresupuestoCliente || false === (bool)$documento->editable) {
            return;
        }

        $firmadas = 0;
        foreach (SolicitudFirma::delDocumento($solicitud->doc_model, $solicitud->doc_code) as $otra) {
            if ($otra->estado === SolicitudFirma::ESTADO_FIRMADA) {
                $firmadas++;
            } elseif ($otra->estado !== SolicitudFirma::ESTADO_ANULADA) {
                return;
            }
        }

        $estado = new EstadoDocumento();
        if ($firmadas === 0 || false === $estado->loadWhere([Where::eq('tipodoc', 'PresupuestoCliente'), Where::eq('generadoc', $destino)])) {
            return;
        }

        $documento->idestado = $estado->idestado;
        if ($documento->save()) {
            $solicitud->registrar('presupuesto', $estado->nombre, '', '', $nick);
        }
    }

    /**
     * Guarda la ubicación que envía el navegador del firmante, si se pidió. Las coordenadas
     * fuera de rango se tratan como no disponibles.
     *
     * @param SolicitudFirma $solicitud
     * @param array $ubicacion
     */
    private static function ubicar(SolicitudFirma $solicitud, array $ubicacion): void
    {
        if (empty($ubicacion)) {
            return;
        }

        $estado = (string)($ubicacion['estado'] ?? '');
        $lat = filter_var($ubicacion['lat'] ?? null, FILTER_VALIDATE_FLOAT);
        $lon = filter_var($ubicacion['lon'] ?? null, FILTER_VALIDATE_FLOAT);
        $precision = filter_var($ubicacion['precision'] ?? null, FILTER_VALIDATE_FLOAT);

        if ($estado === SolicitudFirma::GEO_CONCEDIDA && false !== $lat && false !== $lon
            && abs($lat) <= 90 && abs($lon) <= 180) {
            $solicitud->geo_estado = SolicitudFirma::GEO_CONCEDIDA;
            $solicitud->geo_lat = round($lat, 6);
            $solicitud->geo_lon = round($lon, 6);
            $solicitud->geo_precision = false === $precision ? null : (int)min(max(0, round($precision)), 1000000);
            return;
        }

        $solicitud->geo_estado = $estado === SolicitudFirma::GEO_DENEGADA ? SolicitudFirma::GEO_DENEGADA : SolicitudFirma::GEO_NO_DISPONIBLE;
    }

    /**
     * Extrae el png de una data url y comprueba que de verdad es un png.
     *
     * @param string $dataUrl
     *
     * @return ?string Bytes del png, o null con aviso en el log.
     */
    private static function decodificarPng(string $dataUrl): ?string
    {
        $base64 = preg_replace('#^data:image/png;base64,#', '', trim($dataUrl));
        $png = base64_decode($base64, true);
        if (empty($png)) {
            Tools::log()->warning('fa-signature-empty');
            return null;
        }

        if (strlen($png) > self::MAX_BYTES || 0 !== strpos($png, "\x89PNG\r\n\x1a\n") || false === @getimagesizefromstring($png)) {
            Tools::log()->warning('fa-signature-invalid');
            return null;
        }

        return $png;
    }
}
