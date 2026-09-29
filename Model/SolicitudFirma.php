<?php
/**
 * This file is part of FirmarAhora plugin for FacturaScripts.
 * Copyright (C) 2026 Antonio Jesús González Domingo <antonio.gonzalez.domingo@proton.me>
 */

namespace FacturaScripts\Plugins\FirmarAhora\Model;

use FacturaScripts\Core\Session;
use FacturaScripts\Core\Template\ModelClass;
use FacturaScripts\Core\Template\ModelTrait;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Model\ContratoFirma;
use FacturaScripts\Dinamic\Model\EventoFirma;
use FacturaScripts\Dinamic\Model\User;
use FacturaScripts\Plugins\FirmarAhora\Init;

/**
 * Solicitud de firma: un firmante de un documento.
 *
 * Un documento puede tener varias solicitudes (cliente, técnico, testigo...), cada una
 * con su propio enlace secreto (token) y su código público de verificación. Mientras
 * está abierta guarda la invitación (email, OTP, caducidad); al firmarse guarda las
 * evidencias: imagen, datos que escribió el firmante, ip, navegador, fecha y las huellas
 * SHA-256 del documento que vio y de la copia sellada.
 */
class SolicitudFirma extends ModelClass
{
    use ModelTrait;

    const ESTADO_ANULADA = 'anulada';
    const ESTADO_CADUCADA = 'caducada';
    const ESTADO_FIRMADA = 'firmada';
    const ESTADO_PENDIENTE = 'pendiente';
    const ESTADO_RECHAZADA = 'rechazada';
    const ESTADO_VISTA = 'vista';
    const GEO_CONCEDIDA = 'concedida';
    const GEO_DENEGADA = 'denegada';
    const GEO_NO_DISPONIBLE = 'no-disponible';

    /** @var string Letras del código de verificación: sin 0/O ni 1/I para no confundirlas. */
    const ALFABETO = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';

    /** @var string */
    public $caduca;

    /** @var string Código público de verificación, por ejemplo FA-7K2Q-9XMA. */
    public $codigo;

    /** @var string */
    public $creado;

    /** @var string Valor de la clave primaria del documento. */
    public $doc_code;

    /** @var string Clase del documento: PresupuestoCliente, FacturaCliente, ContratoFirma... */
    public $doc_model;

    /** @var string Descripción del documento en el momento de crear la solicitud. */
    public $doc_titulo;

    /** @var string */
    public $email;

    /** @var string Fecha y hora del último envío del enlace por email. */
    public $enviado;

    /** @var string */
    public $estado;

    /** @var string Imagen de la firma, relativa a la raíz de la instalación. */
    public $firma_path;

    /** @var string dibujada, escrita o imagen. */
    public $firma_tipo;

    /** @var string */
    public $firmado;

    /** @var string */
    public $firmante_nif;

    /** @var string */
    public $firmante_nombre;

    /** @var string Resultado de pedir la ubicación: concedida, denegada o no-disponible. Vacío: no se pidió. */
    public $geo_estado;

    /** @var float */
    public $geo_lat;

    /** @var float */
    public $geo_lon;

    /** @var int Precisión de la ubicación, en metros. */
    public $geo_precision;

    /** @var string SHA-256 del pdf del documento tal y como lo vio el firmante. */
    public $hash_original;

    /** @var string SHA-256 de la copia sellada que se guardó al firmar. */
    public $hash_sellado;

    /** @var int */
    public $id;

    /** @var string */
    public $ip;

    /** @var string */
    public $motivo_rechazo;

    /** @var string Usuario que creó la solicitud. */
    public $nick;

    /** @var string NIF que se espera del firmante (opcional). */
    public $nif;

    /** @var string */
    public $nombre;

    /** @var int Orden de los firmantes en el documento. */
    public $orden;

    /** @var string */
    public $otp_expira;

    /** @var string */
    public $otp_hash;

    /** @var int */
    public $otp_intentos;

    /** @var bool */
    public $otp_verificado;

    /** @var bool True si se firmó delante del usuario, en el propio ERP. */
    public $presencial;

    /** @var int Recordatorios enviados. */
    public $recordatorios;

    /** @var bool */
    public $requiere_otp;

    /** @var string Papel del firmante: Cliente, Técnico, Testigo... */
    public $rol;

    /** @var string Copia sellada en pdf, relativa a la raíz de la instalación. */
    public $sellado_path;

    /** @var string Parte secreta del enlace de firma. */
    public $token;

    /** @var string */
    public $user_agent;

    public function clear(): void
    {
        parent::clear();
        $this->creado = Tools::dateTime();
        $this->estado = self::ESTADO_PENDIENTE;
        $this->nick = Session::user()->nick ?? null;
        $this->orden = 1;
        $this->otp_intentos = 0;
        $this->otp_verificado = false;
        $this->presencial = false;
        $this->recordatorios = 0;
        $this->requiere_otp = false;
    }

    /**
     * Elimina la solicitud con sus archivos. Los eventos se borran en cascada.
     *
     * @return bool
     */
    public function delete(): bool
    {
        $files = [$this->firma_path, $this->sellado_path];
        if (false === parent::delete()) {
            return false;
        }

        foreach ($files as $file) {
            if (false === empty($file) && file_exists(FS_FOLDER . '/' . $file)) {
                unlink(FS_FOLDER . '/' . $file);
            }
        }

        $this->actualizarContrato();
        return true;
    }

    /**
     * Solicitudes de un documento, en el orden de firma.
     *
     * @param string $docModel
     * @param string $docCode
     *
     * @return static[]
     */
    public static function delDocumento(string $docModel, string $docCode): array
    {
        $where = [Where::eq('doc_model', $docModel), Where::eq('doc_code', $docCode)];
        return static::all($where, ['orden' => 'ASC', 'id' => 'ASC']);
    }

    /**
     * Email con parte del nombre y del dominio ocultos, para mostrarlo en páginas públicas.
     *
     * @return string
     */
    public function emailOculto(): string
    {
        if (empty($this->email) || false === strpos($this->email, '@')) {
            return '';
        }

        [$user, $domain] = explode('@', $this->email, 2);
        return mb_substr($user, 0, 2) . str_repeat('*', max(1, mb_strlen($user) - 2)) . '@' . $domain;
    }

    /**
     * Admite la firma: no está firmada, rechazada, anulada ni caducada.
     *
     * @return bool
     */
    public function estaAbierta(): bool
    {
        return in_array($this->estado, [self::ESTADO_PENDIENTE, self::ESTADO_VISTA], true) && false === $this->estaVencida();
    }

    /**
     * Ha pasado la fecha de caducidad.
     *
     * @return bool
     */
    public function estaVencida(): bool
    {
        return false === empty($this->caduca) && strtotime($this->caduca) < strtotime(Tools::date());
    }

    /**
     * Documento al que pertenece la solicitud, ya cargado.
     *
     * @return ?object El modelo, o null si ya no existe o el tipo no está permitido.
     */
    public function getDocumento()
    {
        $allowed = array_merge(Init::DOCUMENTOS, ['ContratoFirma']);
        if (false === in_array($this->doc_model, $allowed, true)) {
            return null;
        }

        $className = '\\FacturaScripts\\Dinamic\\Model\\' . $this->doc_model;
        $model = new $className();
        return $model->load($this->doc_code) ? $model : null;
    }

    /**
     * Registro de auditoría, del más antiguo al más reciente.
     *
     * @return EventoFirma[]
     */
    public function getEventos(): array
    {
        return EventoFirma::all([Where::eq('idsolicitud', $this->id)], ['fecha' => 'ASC', 'id' => 'ASC']);
    }

    public function install(): string
    {
        new User();
        return parent::install();
    }

    /**
     * NIF con los dígitos centrales ocultos, para páginas públicas.
     *
     * @return string
     */
    public function nifOculto(): string
    {
        $nif = (string)$this->firmante_nif;
        $len = mb_strlen($nif);
        return $len < 5 ? $nif : mb_substr($nif, 0, 2) . str_repeat('*', $len - 4) . mb_substr($nif, -2);
    }

    public function primaryDescriptionColumn(): string
    {
        return 'codigo';
    }

    public static function primaryColumn(): string
    {
        return 'id';
    }

    /**
     * Apunta un evento en el registro de auditoría.
     *
     * @param string $tipo
     * @param string $detalle
     * @param string $ip
     * @param string $userAgent
     * @param ?string $nick
     */
    public function registrar(string $tipo, string $detalle = '', string $ip = '', string $userAgent = '', ?string $nick = null): void
    {
        $evento = new EventoFirma();
        $evento->idsolicitud = $this->id;
        $evento->tipo = $tipo;
        $evento->detalle = $detalle;
        $evento->ip = $ip;
        $evento->user_agent = $userAgent;
        $evento->nick = $nick;
        $evento->save();
    }

    public function save(): bool
    {
        if (false === parent::save()) {
            return false;
        }

        $this->actualizarContrato();
        return true;
    }

    public static function tableName(): string
    {
        return 'fa_solicitudes';
    }

    public function test(): bool
    {
        foreach (['rol', 'nombre', 'nif', 'firmante_nombre', 'firmante_nif', 'motivo_rechazo', 'doc_titulo'] as $field) {
            $this->{$field} = Tools::noHtml(trim((string)$this->{$field}));
        }
        $this->nif = strtoupper(str_replace([' ', '-', '.'], '', $this->nif));
        $this->firmante_nif = strtoupper(str_replace([' ', '-', '.'], '', $this->firmante_nif));
        $this->email = strtolower(trim((string)$this->email));
        $this->user_agent = mb_substr((string)$this->user_agent, 0, 255);

        if (false === empty($this->email) && false === filter_var($this->email, FILTER_VALIDATE_EMAIL)) {
            Tools::log()->warning('not-valid-email', ['%email%' => $this->email]);
            return false;
        }

        // una solicitud cerrada (firmada, rechazada, anulada, caducada) no cambia sus datos:
        // el estado y las evidencias sólo los modifican Firmador, Otp y Correo
        if ($this->exists() && false === in_array($this->getOriginal('estado'), [self::ESTADO_PENDIENTE, self::ESTADO_VISTA], true)) {
            foreach (['rol', 'nombre', 'email', 'nif', 'caduca', 'doc_model', 'doc_code', 'token', 'codigo'] as $field) {
                if ($this->hasChanged($field)) {
                    Tools::log()->warning('fa-request-closed');
                    return false;
                }
            }
        }

        $estados = [self::ESTADO_PENDIENTE, self::ESTADO_VISTA, self::ESTADO_FIRMADA, self::ESTADO_RECHAZADA,
            self::ESTADO_ANULADA, self::ESTADO_CADUCADA];
        if (false === in_array($this->estado, $estados, true)) {
            $this->estado = self::ESTADO_PENDIENTE;
        }

        if (empty($this->caduca)) {
            $this->caduca = null;
        }

        // enlace secreto y código público, al crear la solicitud
        if (empty($this->token)) {
            $this->token = bin2hex(random_bytes(24));
        }
        while (empty($this->codigo) || (false === $this->exists() && static::table()->whereEq('codigo', $this->codigo)->count() > 0)) {
            $this->codigo = 'FA-' . self::aleatorio(4) . '-' . self::aleatorio(4);
        }

        return parent::test();
    }

    /**
     * Ubicación del firmante, legible. Vacío si no se pidió.
     *
     * @return string
     */
    public function ubicacion(): string
    {
        if ($this->geo_estado === self::GEO_CONCEDIDA && null !== $this->geo_lat && null !== $this->geo_lon) {
            return sprintf('%.5F, %.5F', $this->geo_lat, $this->geo_lon)
                . ($this->geo_precision ? ' (±' . (int)$this->geo_precision . ' m)' : '');
        }

        if ($this->geo_estado === self::GEO_DENEGADA) {
            return Tools::trans('fa-location-denied');
        }

        return $this->geo_estado === self::GEO_NO_DISPONIBLE ? Tools::trans('fa-location-unavailable') : '';
    }

    /**
     * Url pública para firmar.
     *
     * @return string
     */
    public function urlFirma(): string
    {
        return Tools::siteUrl() . '/FirmarAhora?t=' . $this->token;
    }

    /**
     * Enlace a la ubicación del firmante en OpenStreetMap. Vacío si no la dio.
     *
     * @return string
     */
    public function urlMapa(): string
    {
        if ($this->geo_estado !== self::GEO_CONCEDIDA || null === $this->geo_lat || null === $this->geo_lon) {
            return '';
        }

        $lat = sprintf('%.5F', $this->geo_lat);
        $lon = sprintf('%.5F', $this->geo_lon);
        return 'https://www.openstreetmap.org/?mlat=' . $lat . '&mlon=' . $lon . '#map=17/' . $lat . '/' . $lon;
    }

    /**
     * Url pública de verificación.
     *
     * @return string
     */
    public function urlVerificacion(): string
    {
        return Tools::siteUrl() . '/VerificarFirma?c=' . $this->codigo;
    }

    public function url(string $type = 'auto', string $list = 'List'): string
    {
        return $type === 'list' ? 'ListSolicitudFirma' : parent::url($type, $list);
    }

    /**
     * Si la solicitud es de un contrato, recalcula el estado del contrato.
     */
    protected function actualizarContrato(): void
    {
        if ($this->doc_model !== 'ContratoFirma') {
            return;
        }

        $contrato = new ContratoFirma();
        if ($contrato->load($this->doc_code)) {
            $contrato->actualizarEstado();
        }
    }


    /**
     * Cadena aleatoria con las letras de ALFABETO.
     *
     * @param int $length
     *
     * @return string
     */
    private static function aleatorio(int $length): string
    {
        $text = '';
        for ($i = 0; $i < $length; $i++) {
            $text .= self::ALFABETO[random_int(0, strlen(self::ALFABETO) - 1)];
        }

        return $text;
    }
}
