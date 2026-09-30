<?php
/**
 * This file is part of FirmarAhora plugin for FacturaScripts.
 * Copyright (C) 2026 Antonio Jesús González Domingo <antonio.gonzalez.domingo@proton.me>
 */

namespace FacturaScripts\Plugins\FirmarAhora\Lib\FirmarAhora;

use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Model\EstadoDocumento;
use FacturaScripts\Dinamic\Model\PresupuestoCliente;
use FacturaScripts\Dinamic\Model\SolicitudFirma;
use Throwable;

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

    /** @var int Ancho y alto máximos de la imagen de firma, en píxeles. */
    const MAX_ANCHO = 2400;
    const MAX_ALTO = 1600;

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
     * El firmante no vio el documento nuevo, así que cada copia guarda en "origen" el
     * documento que sí firmó, y así se muestra en el pdf, en el panel y al verificarla. La
     * copia sellada y la huella del pdf no se copian: pertenecen al documento original.
     *
     * @param object $origen
     * @param object $destino
     */
    public static function copiarFirmas($origen, $destino): void
    {
        $codigoDestino = (string)$destino->primaryColumnValue();
        if (false === Ajustes::copiarAlConvertir() || empty($codigoDestino) || false === in_array($destino->modelClassName(), \FacturaScripts\Plugins\FirmarAhora\Init::DOCUMENTOS, true) || false === empty(SolicitudFirma::delDocumento($destino->modelClassName(), $codigoDestino))) {
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
                         'firma_tipo', 'firmado', 'firmante_nombre', 'firmante_nif', 'ip', 'ip_proxy', 'user_agent'] as $field) {
                $copia->{$field} = $original->{$field};
            }
            $copia->origen = mb_substr($original->origen ?: $original->doc_titulo . ' (' . $original->codigo . ')', 0, 200);
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
        if (null !== $nick) {
            Ajustes::recordarUrl();
        }

        return $solicitud;
    }

    /**
     * Firma una solicitud: guarda la imagen y las evidencias, y genera la copia sellada.
     *
     * Todo se hace en una transacción, con las solicitudes del documento bloqueadas: si la
     * copia sellada no se puede generar, la firma no queda guardada, y dos firmas que lleguen
     * a la vez se procesan una detrás de otra.
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
        if (trim($nombre) === '') {
            Tools::log()->warning('fa-signer-name-required');
            return false;
        }

        if (false === $solicitud->presencial && false === self::nifEsperado($solicitud, $nif)) {
            $solicitud->registrar('nif_error', '', self::ip($ip), $userAgent);
            Tools::log()->warning('fa-nif-mismatch');
            return false;
        }

        $png = self::decodificarPng($dataUrl);
        $documento = $solicitud->getDocumento();
        if (null === $png || null === $documento) {
            return false;
        }

        // huellas del documento tal y como lo ve el firmante, antes de añadir su firma. Se
        // calculan fuera de la transacción: generar el pdf puede crear tablas que el núcleo
        // aún no ha usado, y eso no se puede hacer dentro de una
        $datos = [
            'png' => $png, 'tipo' => $tipo, 'nombre' => $nombre, 'nif' => $nif, 'ip' => self::ip($ip),
            'agente' => $userAgent, 'nick' => $nick, 'ubicacion' => $ubicacion,
            'hash_original' => hash('sha256', Sellador::pdf($documento, false)),
            'hash_contenido' => Huella::contenido($documento),
        ];

        $db = new DataBase();
        $propia = false === $db->inTransaction() && $db->beginTransaction();
        $archivos = [];
        $idestado = null;
        $ok = false;
        try {
            $ok = self::guardarFirma($db, $solicitud, $datos, $archivos, $idestado);
        } catch (Throwable $exc) {
            Tools::log()->error('fa-sign-error', ['%error%' => $exc->getMessage()]);
        }

        if (false === $ok) {
            if ($propia) {
                $db->rollback();
            }
            foreach ($archivos as $archivo) {
                if (is_file($archivo)) {
                    unlink($archivo);
                }
            }
            $solicitud->reload();
            return false;
        }

        if ($propia) {
            $db->commit();
        }

        if (null !== $idestado) {
            self::avanzarPresupuesto($solicitud, $idestado);
        }

        if (Ajustes::copiaFirmante() && false === empty($solicitud->email)) {
            Correo::copiaFirmada($solicitud);
        }
        if (Ajustes::avisarEmisor() && false === $solicitud->presencial) {
            Correo::avisoEmisor($solicitud);
        }

        return true;
    }

    /**
     * Primera ip válida de una lista separada por comas.
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
        if (trim($motivo) === '') {
            Tools::log()->warning('fa-reason-required');
            return false;
        }

        $db = new DataBase();
        $propia = false === $db->inTransaction() && $db->beginTransaction();
        self::bloquear($db, $solicitud);

        $ok = false;
        if (false === $solicitud->estaAbierta()) {
            Tools::log()->warning('fa-request-closed');
        } elseif ($solicitud->requiere_otp && false === Otp::sesionValida($solicitud)) {
            Tools::log()->warning('fa-otp-required');
        } else {
            $solicitud->estado = SolicitudFirma::ESTADO_RECHAZADA;
            $solicitud->motivo_rechazo = $motivo;
            $solicitud->ip = self::ip($ip);
            $solicitud->ip_proxy = Conexion::proxy();
            $solicitud->user_agent = $userAgent;
            $ok = $solicitud->save();
        }

        if (false === $ok) {
            if ($propia) {
                $db->rollback();
            }
            $solicitud->reload();
            return false;
        }

        $solicitud->registrar('rechazada', $motivo, $solicitud->ip, $userAgent);
        if ($propia) {
            $db->commit();
        }

        if (Ajustes::avisarEmisor()) {
            Correo::avisoEmisor($solicitud);
        }

        return true;
    }

    /**
     * Pasa el presupuesto al estado que genera el pedido o la factura. El documento nuevo
     * recibe una copia de las firmas al generarse. Se hace fuera de la transacción de la
     * firma porque el núcleo puede tener que crear tablas.
     *
     * @param SolicitudFirma $solicitud
     * @param int $idestado
     */
    private static function avanzarPresupuesto(SolicitudFirma $solicitud, int $idestado): void
    {
        $documento = $solicitud->getDocumento();
        if (null === $documento || false === (bool)$documento->editable) {
            return;
        }

        $documento->idestado = $idestado;
        if (false === $documento->save()) {
            Tools::log()->warning('record-save-error');
        }
    }

    /**
     * Cuando ya han firmado todos los firmantes de un presupuesto abierto, decide el estado
     * al que hay que pasarlo según los ajustes y lo deja apuntado en el registro. Se llama
     * con las solicitudes del documento bloqueadas, así que sólo una petición lo consigue
     * aunque lleguen dos firmas a la vez.
     *
     * @param SolicitudFirma $solicitud
     * @param object $documento
     * @param ?string $nick
     *
     * @return ?int Estado al que pasar el presupuesto, o null si no toca.
     */
    private static function reservarAvance(SolicitudFirma $solicitud, $documento, ?string $nick): ?int
    {
        $destino = ['pedido' => 'PedidoCliente', 'factura' => 'FacturaCliente'][Ajustes::presupuestoFirmado()] ?? '';
        if ($destino === '' || false === $documento instanceof PresupuestoCliente || false === (bool)$documento->editable) {
            return null;
        }

        $firmadas = 0;
        foreach (SolicitudFirma::delDocumento($solicitud->doc_model, $solicitud->doc_code) as $otra) {
            if ($otra->estado === SolicitudFirma::ESTADO_FIRMADA) {
                $firmadas++;
            } elseif ($otra->estado !== SolicitudFirma::ESTADO_ANULADA) {
                return null;
            }

            foreach ($otra->getEventos() as $evento) {
                if ($evento->tipo === 'presupuesto') {
                    return null;
                }
            }
        }

        $estado = new EstadoDocumento();
        if ($firmadas === 0 || false === $estado->loadWhere([Where::eq('tipodoc', 'PresupuestoCliente'), Where::eq('generadoc', $destino)])) {
            return null;
        }

        $solicitud->registrar('presupuesto', $estado->nombre, '', '', $nick);
        return (int)$estado->idestado;
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
     * Bloquea las solicitudes del documento hasta que termine la transacción y vuelve a
     * leer la solicitud, por si otra petición la cambió mientras esperaba.
     *
     * @param DataBase $db
     * @param SolicitudFirma $solicitud
     */
    private static function bloquear(DataBase $db, SolicitudFirma $solicitud): void
    {
        $db->select('SELECT id FROM ' . SolicitudFirma::tableName()
            . ' WHERE doc_model = ' . $db->var2str($solicitud->doc_model)
            . ' AND doc_code = ' . $db->var2str($solicitud->doc_code)
            . ' FOR UPDATE');
        $solicitud->reload();
    }

    /**
     * Parte de firmar() que va dentro de la transacción.
     *
     * @param DataBase $db
     * @param SolicitudFirma $solicitud
     * @param array $datos png, tipo, nombre, nif, ip, agente, nick, ubicacion y las dos huellas.
     * @param array $archivos Se rellena con los archivos escritos, para borrarlos si algo falla.
     * @param ?int $idestado Se rellena con el estado al que hay que pasar el presupuesto, si toca.
     *
     * @return bool
     */
    private static function guardarFirma(DataBase $db, SolicitudFirma $solicitud, array $datos, array &$archivos, ?int &$idestado): bool
    {
        self::bloquear($db, $solicitud);
        if (false === $solicitud->estaAbierta()) {
            Tools::log()->warning('fa-request-closed');
            return false;
        }

        if ($solicitud->requiere_otp && false === $solicitud->presencial && false === Otp::sesionValida($solicitud)) {
            Tools::log()->warning('fa-otp-required');
            return false;
        }

        $documento = $solicitud->getDocumento();
        if (null === $documento || false === Tools::folderCheckOrCreate(FS_FOLDER . '/' . self::CARPETA_FIRMAS)) {
            Tools::log()->error('fa-file-error');
            return false;
        }

        $solicitud->hash_original = $datos['hash_original'];
        $solicitud->hash_contenido = $datos['hash_contenido'];

        $ruta = self::CARPETA_FIRMAS . $solicitud->token . '.png';
        $archivos[] = FS_FOLDER . '/' . $ruta;
        $archivos[] = FS_FOLDER . '/' . Sellador::CARPETA_SELLADOS . $solicitud->token . '.pdf';
        if (false === file_put_contents(FS_FOLDER . '/' . $ruta, $datos['png'])) {
            Tools::log()->error('fa-file-error');
            return false;
        }

        $solicitud->firma_path = $ruta;
        $solicitud->firma_tipo = in_array($datos['tipo'], self::TIPOS, true) ? $datos['tipo'] : 'dibujada';
        $solicitud->firmante_nombre = $datos['nombre'];
        $solicitud->firmante_nif = $datos['nif'];
        $solicitud->firmado = Tools::dateTime();
        $solicitud->ip = $datos['ip'];
        $solicitud->ip_proxy = $solicitud->presencial ? '' : Conexion::proxy();
        $solicitud->user_agent = $datos['agente'];
        $solicitud->estado = SolicitudFirma::ESTADO_FIRMADA;
        self::ubicar($solicitud, $datos['ubicacion']);
        if (false === $solicitud->save()) {
            return false;
        }

        $solicitud->registrar('firmada', 'SHA-256 ' . $solicitud->hash_original, $solicitud->ip, $datos['agente'], $datos['nick']);

        if (false === Sellador::sellar($solicitud, $documento)) {
            Tools::log()->error('fa-seal-error');
            return false;
        }

        $solicitud->registrar('sellada', 'SHA-256 ' . $solicitud->hash_sellado, '', '', $datos['nick']);
        $idestado = self::reservarAvance($solicitud, $documento, $datos['nick']);
        return true;
    }

    /**
     * Si la solicitud indica el documento de identidad de quien debe firmar, el que escribe
     * el firmante tiene que ser el mismo.
     *
     * @param SolicitudFirma $solicitud
     * @param string $nif
     *
     * @return bool
     */
    private static function nifEsperado(SolicitudFirma $solicitud, string $nif): bool
    {
        $esperado = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)$solicitud->nif));
        return $esperado === '' || $esperado === strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $nif));
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

        $medidas = strlen($png) <= self::MAX_BYTES && 0 === strpos($png, "\x89PNG\r\n\x1a\n") ? @getimagesizefromstring($png) : false;
        if (false === $medidas || $medidas[0] < 1 || $medidas[1] < 1 || $medidas[0] > self::MAX_ANCHO || $medidas[1] > self::MAX_ALTO) {
            Tools::log()->warning('fa-signature-invalid');
            return null;
        }

        return $png;
    }
}
