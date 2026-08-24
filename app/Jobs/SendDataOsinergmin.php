<?php

namespace App\Jobs;

use Carbon\Carbon;
use GuzzleHttp\Client;
use App\Services\LogService;
use Illuminate\Bus\Queueable;
use Ramsey\Uuid\Uuid;
use Tobuli\Entities\Osinergmin;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Retransmision de tramas GPS a PMGO (Osinergmin).
 *
 * Contrato: "Manual tecnico de integracion PMGO - EMV", microservicio
 * api-gps-ingesta-batch v1. Campos obligatorios: event, plate, speed, gpsDate,
 * tokenTrama, odometer (km) y position{latitude,longitude,altitude}; uuid es
 * opcional pero es lo que permite casar la respuesta con la fila local.
 */
class SendDataOsinergmin extends Job implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    const URL = 'https://pmgo.osinergmin.gob.pe/api-gps-ingesta-batch/api/v1/trama-batch';

    /** Tramas por request. */
    const CHUNK_SIZE = 250;

    /** Margen antes del $timeout: al agotarse el job corta y las tramas restantes
     *  quedan en la tabla para el siguiente dispatch (solo se borran al enviarse). */
    const DEADLINE_MARGIN = 40;

    /** Estados OK por trama. ACCEPTED es el del manual PMGO vigente; CREATED el del
     *  endpoint anterior (prod.osinergmin-agent-2021.com). Se aceptan los dos para
     *  no perder tramas si conviven las dos versiones. */
    const OK_STATUSES = ['ACCEPTED', 'CREATED'];

    /** Tope de tramas descartadas de a una tras un 422: pasado ese numero se asume
     *  problema global (token, IP, contrato) y se deja de borrar. */
    const MAX_POISON = 5;

    /** Tope de logs de error en BD por corrida (evita inundar la tabla `logs`). */
    const MAX_ERROR_LOGS = 50;

    protected $offset;
    protected $batchSize;
    protected $service;
    protected $plates;
    protected $logService;
    protected $userId;

    /** uuid enviado => datos de la fila local (id, imei) para log y borrado. */
    protected $meta = [];
    protected $poison = 0;
    protected $errorLogs = 0;
    protected $deadline = PHP_INT_MAX;

    public $tries = 3;
    public $timeout = 300;

    public function __construct($offset, $batchSize, $service, $plates, $userId = null)
    {
        $this->offset = $offset;
        $this->batchSize = $batchSize;
        $this->service = $service;
        $this->plates = $plates;
        $this->userId = $userId;

        $this->onQueue('web-services-osinergmin');
    }

    public function handle()
    {
        $this->logService = app(LogService::class);
        $this->logService->setUserId($this->userId);
        $this->deadline = microtime(true) + ($this->timeout - self::DEADLINE_MARGIN);

        // Sin token PMGO rechaza el lote entero con 422: mejor no gastar la corrida.
        if (empty($this->service['token'])) {
            $this->logService->logError('Osinergmin', 'Usuario sin token Osinergmin configurado, envio omitido', [
                'user_id' => $this->userId,
            ]);
            return;
        }

        $this->getTramas();
    }

    public function getTramas()
    {
        $positions = Osinergmin::offset($this->offset)
            ->limit($this->batchSize)->whereIn('plate', $this->plates)
            ->with('device')->orderBy('created_at')->get();

        $this->logService->logStart('Osinergmin', 'Iniciando procesamiento de tramas Osinergmin', [
            'count'     => $positions->count(),
            'offset'    => $this->offset,
            'batchSize' => $this->batchSize,
            'plates'    => $positions->pluck('plate')->unique()->values()->toArray(),
        ]);

        if ($positions->isEmpty()) {
            return;
        }

        $tramas = [];

        foreach ($positions as $position) {
            $other = is_array($position->other) ? $position->other : [];

            // uuid determinista: la misma fila lleva siempre el mismo uuid, asi PMGO
            // puede descartar duplicados si una retransmision se repite. El manual lo
            // pide String con formato uuid; antes se mandaba el id numerico de la fila,
            // que ademas rompia el match con la respuesta (comparacion estricta int/string).
            $uuid = Uuid::uuid5(Uuid::NAMESPACE_URL, 'pmgo:osinergmin:' . $position->id)->toString();

            $tramas[] = [
                'event'      => $this->resolveEvent($other),
                'plate'      => $position->plate,
                'speed'      => doubleval($position->speed),
                'gpsDate'    => gmdate('Y-m-d\TH:i:s.v\Z', $position->timestamp), // ISO 8601 en UTC
                'tokenTrama' => $this->service['token'],
                // El manual pide el odometro en KM; traccar guarda totaldistance en metros.
                'odometer'   => (string) round(($other['totaldistance'] ?? 0) / 1000),
                'position'   => [
                    'latitude'  => doubleval($position->latitud),
                    'longitude' => doubleval($position->longitud),
                    'altitude'  => doubleval($position->altitude),
                ],
                'uuid'       => $uuid,
            ];

            // El imei ya no viaja en la trama (no esta en el contrato del manual), pero
            // se guarda aparte porque la tabla de logs lo pide.
            $this->meta[$uuid] = [
                'id'   => $position->id,
                'imei' => optional($position->device)->imei,
            ];
        }

        $this->sendDataOsinergmin($tramas);
    }

    /**
     * Traduce la posicion al catalogo de eventos del manual (seccion 5). Lo que no
     * encaje va como 'none': cualquier otro valor lo rechaza PMGO. `other` viene del
     * XML de traccar, por eso todo llega como texto.
     */
    protected function resolveEvent(array $other): string
    {
        $alarm = strtolower((string) ($other['alarm'] ?? ''));

        if (in_array($alarm, ['sos', 'panic'], true)) {
            return 'sos';
        }

        if (in_array($alarm, ['powercut', 'powerdown', 'lowpower'], true)) {
            return 'battery_dc';
        }

        if ($alarm === 'powerrestored') {
            return 'battery_ct';
        }

        // 239 = evento de ignicion (mismo criterio que saveDataComsatel en PositionsWriter)
        if ((string) ($other['event'] ?? '') === '239') {
            return filter_var($other['ignition'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 'acc_on' : 'acc_off';
        }

        return 'none';
    }

    public function sendDataOsinergmin($tramas)
    {
        // Un solo cliente para todo el lote. Sin timeout explicito Guzzle usa 0 = infinito:
        // si PMGO deja el socket colgado el worker se bloquea para siempre y no lo saca ni
        // queue:restart (hay que matarlo con SIGKILL).
        $client = new Client([
            'verify'          => false,
            'connect_timeout' => 10,
            'timeout'         => 30,
        ]);

        foreach (array_chunk($tramas, self::CHUNK_SIZE) as $grupo) {
            if (microtime(true) >= $this->deadline) {
                $this->logService->logError('Osinergmin', 'Lote cortado por deadline del job', [
                    'user_id'    => $this->userId,
                    'pendientes' => count($grupo),
                ]);
                break;
            }

            $this->enviarTramas($client, $grupo);
        }
    }

    public function enviarTramas(Client $client, array $tramas): void
    {
        try {
            $response = $client->request('POST', self::URL, [
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
                'json' => $tramas,
            ]);

            $this->actionAfterSend(
                $response->getStatusCode(),
                json_decode((string) $response->getBody(), true),
                $tramas
            );
        } catch (RequestException $e) {
            $this->handleRequestException($client, $e, $tramas);
        }
    }

    protected function actionAfterSend(int $httpStatus, $body, array $tramas): void
    {
        if ($httpStatus < 200 || $httpStatus >= 300) {
            $this->logService->logError('Osinergmin', "Respuesta inesperada de PMGO (HTTP {$httpStatus})", [
                'tramas' => count($tramas),
            ]);
            return;
        }

        $body = is_array($body) ? $body : [];

        // El batch responde 202 con data[] (un item por trama, casado por uuid) y
        // metadata{success,failure,total}. Si no llega data[] -respuesta simple o cambio
        // de formato- el 2xx ya garantiza que el lote quedo encolado (manual 3.1), asi
        // que se dan por aceptadas todas: es lo que evita que la tabla crezca sin fin
        // cuando cambia el envoltorio de la respuesta.
        $porUuid = [];

        foreach ((array) ($body['data'] ?? []) as $item) {
            if (isset($item['uuid'])) {
                $porUuid[(string) $item['uuid']] = $item;
            }
        }

        $ok = [];
        $err = [];

        foreach ($tramas as $trama) {
            $item   = $porUuid[$trama['uuid']] ?? null;
            $estado = strtoupper((string) ($item['status'] ?? ''));

            if (is_null($item) || in_array($estado, self::OK_STATUSES, true)) {
                $ok[] = $trama['uuid'];
                $this->handleSuccess($trama, $item ?: ['status' => 'ACCEPTED', 'http' => $httpStatus]);
            } else {
                $err[] = $trama['uuid'];
                $this->handleError($trama, $item);
            }
        }

        $this->logService->logStart('Osinergmin', 'Respuesta PMGO procesada', [
            'http'       => $httpStatus,
            'enviadas'   => count($tramas),
            'aceptadas'  => count($ok),
            'rechazadas' => count($err),
            'metadata'   => $body['metadata'] ?? null,
        ]);

        // Las aceptadas no se retransmiten (manual 4.1) y las rechazadas por contenido
        // fallarian igual en cada reintento: en ambos casos la fila sale de la tabla.
        $ids = $this->rowIds(array_merge($ok, $err));

        if (!empty($ids)) {
            Osinergmin::destroy($ids);
        }
    }

    protected function handleRequestException(Client $client, RequestException $e, array $tramas): void
    {
        if (!$e->hasResponse()) {
            // Timeout, DNS o corte de red: transitorio, las filas se quedan para el
            // siguiente dispatch.
            $this->logService->logError('Osinergmin', 'Sin respuesta de PMGO: ' . $e->getMessage(), [
                'tramas' => count($tramas),
                'placas' => array_values(array_unique(array_column($tramas, 'plate'))),
            ]);
            return;
        }

        $status = $e->getResponse()->getStatusCode();
        $body   = (string) $e->getResponse()->getBody();

        $this->logService->logError('Osinergmin', "PMGO respondio HTTP {$status}", [
            'tramas' => count($tramas),
            'body'   => mb_substr($body, 0, 1000),
        ]);

        if ($status == 422) {
            $this->aislarTramaRechazada($client, $tramas, $body);
            return;
        }

        // 401/403 (token o IP fuera de la lista blanca) y 5xx no son culpa de la trama:
        // no se borra nada y se reintenta en el siguiente dispatch.
    }

    /**
     * 422 = el lote entero fue rechazado por contenido (manual 3.2) y el cuerpo no dice
     * cual trama fallo. Se parte el lote a la mitad hasta aislar a la culpable: esa se
     * registra y se borra, y el resto se envia normal. Sin esto una sola fila mala
     * bloquea la cola para siempre (se reintenta cada minuto y nunca se borra).
     */
    protected function aislarTramaRechazada(Client $client, array $tramas, string $body): void
    {
        if ($this->poison >= self::MAX_POISON) {
            // Ya no parece una fila puntual sino el token, la IP o el contrato:
            // se corta para no borrar tramas buenas.
            $this->logService->logError('Osinergmin', 'Demasiados 422 seguidos, se aborta el aislamiento de tramas', [
                'descartadas' => $this->poison,
                'pendientes'  => count($tramas),
            ]);
            return;
        }

        if (count($tramas) === 1) {
            $this->poison++;
            $trama = $tramas[0];

            $this->handleError($trama, [
                'status' => 'ERROR',
                'http'   => 422,
                'body'   => mb_substr($body, 0, 500),
            ]);

            $ids = $this->rowIds([$trama['uuid']]);

            if (!empty($ids)) {
                Osinergmin::destroy($ids);
            }

            return;
        }

        if (microtime(true) >= $this->deadline) {
            return;
        }

        $mitad = intdiv(count($tramas), 2);
        $this->enviarTramas($client, array_slice($tramas, 0, $mitad));
        $this->enviarTramas($client, array_slice($tramas, $mitad));
    }

    /** uuids enviados => ids de las filas locales. */
    protected function rowIds(array $uuids): array
    {
        $ids = [];

        foreach ($uuids as $uuid) {
            if (isset($this->meta[$uuid]['id'])) {
                $ids[] = $this->meta[$uuid]['id'];
            }
        }

        return $ids;
    }

    protected function handleSuccess(array $trama, $response): void
    {
        if (empty($this->service['logs'])) {
            return;
        }

        $this->logRow($trama, 'success', $response);
    }

    protected function handleError(array $trama, $response): void
    {
        // Los errores se guardan siempre, aunque el cliente tenga los logs apagados:
        // es la unica pista de por que una trama no llego. Con tope por corrida.
        if ($this->errorLogs >= self::MAX_ERROR_LOGS) {
            return;
        }

        $this->errorLogs++;
        $this->logRow($trama, 'error', $response ?: ['status' => 'ERROR']);
    }

    protected function logRow(array $trama, string $status, $response): void
    {
        try {
            $this->logService->logToDatabase(
                'Osinergmin',
                $trama['plate'],
                $status,
                $trama,
                ['message' => $response],
                [],
                Carbon::parse($trama['gpsDate'])->setTimezone('America/Lima')->format('Y-m-d H:i:s'),
                $this->meta[$trama['uuid']]['imei'] ?? null
            );
        } catch (\Throwable $th) {
            $this->logService->logError('Osinergmin', 'Error al registrar log en BD: ' . $th->getMessage(), [
                'trama'    => $trama,
                'response' => $response,
            ]);
        }
    }
}
