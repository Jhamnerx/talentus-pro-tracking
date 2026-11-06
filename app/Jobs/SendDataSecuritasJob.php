<?php

namespace App\Jobs;

use App\Services\LogService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Tobuli\Entities\Securitas;
use Tobuli\Services\Securitas\SecuritasClient;
use Tobuli\Services\Securitas\SecuritasTransportException;

/**
 * Envia a Securitas GlobalAVL un rango de filas de la cola `securitas` en una
 * sola peticion (hasta 300 eventos).
 *
 *   200          -> se borran las filas en el acto (GlobalAVL no deduplica).
 *   401 / 403    -> credenciales: se conservan las filas, error en log.
 *   500 / otro   -> se conservan las filas y el job se reintenta.
 *   Sin respuesta (red, timeout) -> igual que 500.
 *
 * En el ultimo intento, si el lote sigue devolviendo error, se parte en mitades
 * para aislar los eventos que GlobalAVL rechaza: solo se descartan si en esta
 * misma ejecucion otra parte del lote fue aceptada (si nada pasa, el servicio
 * esta caido y no se borra nada).
 */
class SendDataSecuritasJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $timeout = 170;
    public $failOnTimeout = true;
    public $backoff = 30;

    /** Tope de peticiones durante el aislamiento (rate limit y timeout del job). */
    private const MAX_ISOLATION_REQUESTS = 40;

    protected int $fromId;
    protected int $toId;
    protected array $service;
    protected array $deviceIds;
    protected ?int $userId;

    private SecuritasClient $client;
    private bool $sawSuccess = false;
    private int $isolationRequests = 0;
    /** @var Securitas[] */
    private array $suspects = [];
    private array $stats = ['sent' => 0, 'discarded' => 0];

    public function __construct(int $fromId, int $toId, array $service, array $deviceIds, ?int $userId = null)
    {
        $this->fromId    = $fromId;
        $this->toId      = $toId;
        $this->service   = $service;
        $this->deviceIds = $deviceIds;
        $this->userId    = $userId;
        $this->onQueue(DispatchSecuritasJobs::QUEUE_NAME);
    }

    public function failed(\Throwable $exception)
    {
        $logService = app(LogService::class);
        $logService->setUserId($this->userId);
        $logService->logError('Securitas', 'Job fallido definitivamente', [
            'from_id' => $this->fromId,
            'to_id'   => $this->toId,
            'message' => $exception->getMessage(),
        ]);
    }

    public function handle()
    {
        $logService = app(LogService::class);
        $logService->setUserId($this->userId);

        $rows = Securitas::whereIn('device_id', $this->deviceIds)
            ->whereBetween('id', [$this->fromId, $this->toId])
            ->orderBy('event_time')
            ->orderBy('id')
            ->get();

        if ($rows->isEmpty()) {
            return;
        }

        $logService->logStart('Securitas', 'Enviando lote a GlobalAVL', [
            'from_id' => $this->fromId,
            'to_id'   => $this->toId,
            'count'   => $rows->count(),
            'url'     => $this->service['url'] ?? null,
        ]);

        $this->client = new SecuritasClient($this->service['url'], $this->service['api_key']);

        $status = $this->post($rows);

        if ($status === 200) {
            $this->logDone($rows->count());
            return;
        }

        if (in_array($status, [401, 403], true)) {
            Log::channel('securitas')->error('GlobalAVL rechazo la API key; se conservan las filas', [
                'status'  => $status,
                'api_key' => $this->client->maskedKey(),
                'url'     => $this->service['url'],
                'body'    => $this->client->lastResponse(),
            ]);
            return;
        }

        if ($this->attempts() < $this->tries) {
            throw new \RuntimeException("GlobalAVL respondio {$status}; se reintentara el lote.");
        }

        // Ultimo intento: aislar los eventos rechazados.
        $this->isolate($rows);

        if (!$this->sawSuccess) {
            throw new \RuntimeException("GlobalAVL sigue respondiendo {$status} y no acepto ninguna parte del lote; se conservan las filas.");
        }

        foreach ($this->suspects as $row) {
            Log::channel('securitas')->error('Evento rechazado por GlobalAVL; descartado', [
                'row_id'    => $row->id,
                'device_id' => $row->device_id,
                'payload'   => $row->payload,
            ]);
            $row->delete();
            $this->stats['discarded']++;
        }

        $this->logDone($rows->count());
    }

    /**
     * POST de las filas. 200 -> se borran en el acto. Devuelve el codigo HTTP.
     *
     * @throws SecuritasTransportException
     */
    private function post(Collection $rows): int
    {
        try {
            $status = $this->client->send($rows->map(fn (Securitas $row) => $row->event())->all());
        } catch (SecuritasTransportException $e) {
            Log::channel('securitas')->error('Sin respuesta de GlobalAVL; se reintentara', [
                'count'   => $rows->count(),
                'message' => $e->getMessage(),
            ]);
            throw $e;
        }

        if ($status === 200) {
            Securitas::whereIn('id', $rows->pluck('id')->all())->delete();
            $this->sawSuccess = true;
            $this->stats['sent'] += $rows->count();

            if (!empty($this->service['logs'])) {
                Log::channel('securitas')->info('Lote aceptado por GlobalAVL', [
                    'count'    => $rows->count(),
                    'response' => $this->client->lastResponse(),
                ]);
            }
        } else {
            Log::channel('securitas')->warning('GlobalAVL respondio error', [
                'status'   => $status,
                'count'    => $rows->count(),
                'response' => $this->client->lastResponse(),
            ]);
        }

        return $status;
    }

    /**
     * Biseccion: envia mitades; un evento suelto que falla queda como sospechoso.
     * Si aun no hubo ningun exito y se pasa el tope de peticiones, se aborta
     * (lo mas probable es que GlobalAVL este caido).
     */
    private function isolate(Collection $rows): void
    {
        if ($rows->count() === 1) {
            $this->suspects[] = $rows->first();
            return;
        }

        $half  = intdiv($rows->count(), 2);
        $parts = [$rows->slice(0, $half)->values(), $rows->slice($half)->values()];

        foreach ($parts as $part) {
            if (++$this->isolationRequests > self::MAX_ISOLATION_REQUESTS) {
                throw new \RuntimeException('Tope de peticiones de aislamiento alcanzado; se conservan las filas pendientes.');
            }

            if ($this->post($part) !== 200) {
                $this->isolate($part);
            }
        }
    }

    private function logDone(int $count): void
    {
        Log::channel('securitas')->info('Lote Securitas procesado', [
            'from_id' => $this->fromId,
            'to_id'   => $this->toId,
            'count'   => $count,
        ] + $this->stats);
    }
}
