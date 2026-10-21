<?php

namespace Tobuli\Services\Unigis;

use DateTimeInterface;
use SoapClient;
use SoapFault;
use Throwable;

/**
 * Cliente SOAP 1.1 para la interfaz de prestadores AVL de UNIGIS.
 *
 * Solo implementa el metodo acordado con UNIGIS (21-oct-2026):
 * LoginYInsertarEvento (sin sesion, NroSerie = -1). Segun el WSDL:
 *   SystemUser, Password, Dominio, NroSerie, Codigo  -> string
 *   Latitud, Longitud, Altitud, Velocidad             -> double
 *   FechaHoraEvento, FechaHoraRecepcion               -> dateTime (UTC, sin offset)
 *   retorno LoginYInsertarEventoResult                -> int
 *
 * Retorno: > 0 evento ingresado, 0 vehiculo deshabilitado en UNIGIS (descartado),
 * < 0 error (protocolo o codigo de evento no mapeado).
 */
class UnigisSoapClient
{
    public const NAMESPACE   = 'http://unisolutions.com.ar/';
    public const NRO_SERIE   = '-1';
    public const DATE_FORMAT = 'Y-m-d\TH:i:s';

    private string $wsdlUrl;
    private string $user;
    private string $password;
    private int $timeout;

    private ?SoapClient $client = null;

    public function __construct(string $wsdlUrl, string $user, string $password, int $timeout = 15)
    {
        $this->wsdlUrl  = $wsdlUrl;
        $this->user     = $user;
        $this->password = $password;
        $this->timeout  = $timeout;
    }

    /**
     * Envia una posicion con LoginYInsertarEvento.
     *
     * @param array $row  dominio, codigo, latitud, longitud, altitud, velocidad,
     *                    fecha_hora_evento, fecha_hora_recepcion (DateTime o string UTC)
     * @return int        LoginYInsertarEventoResult
     * @throws UnigisTransportException si la llamada no llega o UNIGIS devuelve SoapFault
     */
    public function loginYInsertarEvento(array $row): int
    {
        // El orden importa: SOAP document/literal con <s:sequence>.
        $params = [
            'SystemUser'         => $this->user,
            'Password'           => $this->password,
            'Dominio'            => UnigisEventMapper::normalizePlate($row['dominio'] ?? ''),
            'NroSerie'           => self::NRO_SERIE,
            'Codigo'             => (string) ($row['codigo'] ?? UnigisEventMapper::POS),
            'Latitud'            => (float) ($row['latitud'] ?? 0),
            'Longitud'           => (float) ($row['longitud'] ?? 0),
            'Altitud'            => (float) ($row['altitud'] ?? 0),
            'Velocidad'          => (float) ($row['velocidad'] ?? 0),
            'FechaHoraEvento'    => $this->formatDate($row['fecha_hora_evento'] ?? null),
            'FechaHoraRecepcion' => $this->formatDate($row['fecha_hora_recepcion'] ?? null),
        ];

        try {
            $response = $this->client()->__soapCall('LoginYInsertarEvento', [$params]);
        } catch (UnigisTransportException $e) {
            throw $e;
        } catch (SoapFault $e) {
            throw new UnigisTransportException('SoapFault UNIGIS: ' . $e->getMessage(), 0, $e);
        } catch (Throwable $e) {
            throw new UnigisTransportException('Error de transporte UNIGIS: ' . $e->getMessage(), 0, $e);
        }

        if (!isset($response->LoginYInsertarEventoResult)) {
            throw new UnigisTransportException('Respuesta UNIGIS sin LoginYInsertarEventoResult');
        }

        return (int) $response->LoginYInsertarEventoResult;
    }

    /** XML del ultimo request (para logs de error). */
    public function lastRequest(): ?string
    {
        return $this->client ? $this->client->__getLastRequest() : null;
    }

    /** XML de la ultima respuesta (para logs de error). */
    public function lastResponse(): ?string
    {
        return $this->client ? $this->client->__getLastResponse() : null;
    }

    /**
     * Texto legible de un retorno del servicio.
     */
    public static function describeResult(int $result): string
    {
        if ($result > 0) {
            return 'aceptado';
        }
        if ($result === 0) {
            return 'vehiculo deshabilitado en UNIGIS (descartado)';
        }

        return 'rechazado (error de protocolo o codigo de evento no mapeado)';
    }

    private function client(): SoapClient
    {
        if ($this->client) {
            return $this->client;
        }

        $context = stream_context_create([
            'http' => ['timeout' => $this->timeout],
            'ssl'  => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);

        try {
            $this->client = new SoapClient($this->wsdlUrl, [
                'soap_version'       => SOAP_1_1,
                'trace'              => true,
                'exceptions'         => true,
                'cache_wsdl'         => WSDL_CACHE_DISK,
                'connection_timeout' => $this->timeout,
                'stream_context'     => $context,
                'user_agent'         => 'TalentusTracking-UNIGIS/1.0',
            ]);
        } catch (SoapFault $e) {
            throw new UnigisTransportException('No se pudo cargar el WSDL de UNIGIS: ' . $e->getMessage(), 0, $e);
        }

        return $this->client;
    }

    /**
     * Fecha en formato xs:dateTime sin offset. Se asume que el valor ya esta en UTC
     * (asi guarda Traccar device_time / server_time).
     */
    private function formatDate($value): string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format(self::DATE_FORMAT);
        }

        try {
            // Cadena sin zona: se interpreta como UTC (no como la zona de la app).
            $dt = new \DateTime($value ? (string) $value : 'now', new \DateTimeZone('UTC'));
        } catch (\Exception $e) {
            $dt = new \DateTime('now', new \DateTimeZone('UTC'));
        }

        return $dt->format(self::DATE_FORMAT);
    }
}
