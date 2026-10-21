<?php

namespace App\Jobs;

use App\Services\LogService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Tobuli\Entities\Unigis;
use Tobuli\Services\Unigis\UnigisSoapClient;
use Tobuli\Services\Unigis\UnigisTransportException;

/**
 * Envia a UNIGIS un rango de filas de la cola `unigis`, una llamada SOAP por fila.
 *
 * Retorno de UNIGIS:
 *   > 0  aceptado                        -> se borra la fila
 *   = 0  vehiculo deshabilitado en UNIGIS -> se borra la fila, aviso en log
 *   < 0  rechazado (protocolo / codigo)  -> se borra la fila, error en log con el XML
 * Fallo de transporte (red, timeout, SoapFault) -> se conservan las filas restantes
 * y el job se relanza para que la cola reintente.
 */
class SendDataUnigisJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $timeout = 170;
    public $failOnTimeout = true;
    public $backoff = 30;

    protected int $fromId;
    protected int $toId;
    protected array $service;
    protected array $deviceIds;
    protected ?int $userId;

    public function __construct(int $fromId, int $toId, array $service, array $deviceIds, ?int $userId = null)
    {
        $this->fromId    = $fromId;
        $this->toId      = $toId;
        $this->service   = $service;
        $this->deviceIds = $deviceIds;
        $this->userId    = $userId;
        $this->onQueue(DispatchUnigisJobs::QUEUE_NAME);
    }

    public function failed(\Throwable $exception)
    {
        $logService = app(LogService::class);
        $logService->setUserId($this->userId);
        $logService->logError('Unigis', 'Job fallido definitivamente', [
            'from_id' => $this->fromId,
            'to_id'   => $this->toId,
            'message' => $exception->getMessage(),
        ]);
    }

    public function handle()
    {
        $logService = app(LogService::class);
        $logService->setUserId($this->userId);

        $rows = Unigis::whereIn('device_id', $this->deviceIds)
            ->whereBetween('id', [$this->fromId, $this->toId])
            ->orderBy('fecha_hora_evento')
            ->orderBy('id')
            ->get();

        if ($rows->isEmpty()) {
            return;
        }

        $logService->logStart('Unigis', 'Enviando lote a UNIGIS', [
            'from_id' => $this->fromId,
            'to_id'   => $this->toId,
            'count'   => $rows->count(),
            'url'     => $this->service['url'] ?? null,
        ]);

        $client = new UnigisSoapClient(
            $this->service['url'],
            $this->service['user'],
            $this->service['password']
        );

        $stats = ['ok' => 0, 'disabled' => 0, 'rejected' => 0];

        foreach ($rows as $row) {
            $payload = [
                'dominio'              => $row->dominio,
                'codigo'               => $row->codigo,
                'latitud'              => $row->latitud,
                'longitud'             => $row->longitud,
                'altitud'              => $row->altitud,
                'velocidad'            => $row->velocidad,
                'fecha_hora_evento'    => $row->fecha_hora_evento,
                'fecha_hora_recepcion' => $row->fecha_hora_recepcion,
            ];

            try {
                $result = $client->loginYInsertarEvento($payload);
            } catch (UnigisTransportException $e) {
                // La posicion no llego: se conserva esta fila y las siguientes.
                Log::channel('unigis')->error('Fallo de transporte con UNIGIS; se reintentara el lote', [
                    'row_id'  => $row->id,
                    'dominio' => $row->dominio,
                    'message' => $e->getMessage(),
                    'stats'   => $stats,
                ]);
                throw $e;
            }

            $context = [
                'row_id'  => $row->id,
                'dominio' => $row->dominio,
                'codigo'  => $row->codigo,
                'evento'  => $row->fecha_hora_evento instanceof \DateTimeInterface
                    ? $row->fecha_hora_evento->format('Y-m-d H:i:s')
                    : (string) $row->fecha_hora_evento,
                'retorno' => $result,
            ];

            if ($result > 0) {
                $stats['ok']++;
                if (!empty($this->service['logs'])) {
                    Log::channel('unigis')->info('Posicion aceptada por UNIGIS', $context);
                }
            } elseif ($result === 0) {
                $stats['disabled']++;
                Log::channel('unigis')->warning('Vehiculo deshabilitado en UNIGIS; posicion descartada', $context);
            } else {
                $stats['rejected']++;
                Log::channel('unigis')->error('Posicion rechazada por UNIGIS; descartada', $context + [
                    'crudo'    => $row->crudo,
                    'request'  => $client->lastRequest(),
                    'response' => $client->lastResponse(),
                ]);
            }

            // En los tres casos UNIGIS ya respondio: la fila sale de la cola.
            $row->delete();
        }

        Log::channel('unigis')->info('Lote UNIGIS procesado', [
            'from_id' => $this->fromId,
            'to_id'   => $this->toId,
        ] + $stats);
    }
}
