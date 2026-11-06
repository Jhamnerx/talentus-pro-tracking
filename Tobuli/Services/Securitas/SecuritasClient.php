<?php

namespace Tobuli\Services\Securitas;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;

/**
 * Cliente HTTP de Securitas GlobalAVL: POST {"version": "2.0", "events": [...]}
 * con cabecera X-API-Key.
 *
 * GlobalAVL responde 200 "OK" o 500 sin detalle. Rate limit: 10 peticiones/s
 * (individuales o en bloque) -> se espera al menos MIN_INTERVAL_MS entre POST
 * dentro del mismo proceso; el worker corre con numprocs=1.
 */
class SecuritasClient
{
    public const MAX_EVENTS_PER_REQUEST = 300;
    public const MIN_INTERVAL_MS = 120;

    private static float $lastRequestAt = 0.0;

    private string $url;
    private string $apiKey;
    private Client $http;

    private ?string $lastBody = null;
    private ?string $lastRequest = null;

    public function __construct(string $url, string $apiKey, ?Client $http = null)
    {
        $this->url    = trim($url);
        $this->apiKey = trim($apiKey);
        $this->http   = $http ?: new Client([
            RequestOptions::TIMEOUT         => 20,
            RequestOptions::CONNECT_TIMEOUT => 10,
            RequestOptions::HTTP_ERRORS     => false,
        ]);
    }

    /**
     * Envia los eventos y devuelve el codigo HTTP.
     *
     * @throws SecuritasTransportException si no hubo respuesta (red, DNS, timeout).
     */
    public function send(array $events): int
    {
        if (count($events) > self::MAX_EVENTS_PER_REQUEST) {
            throw new \InvalidArgumentException('GlobalAVL admite como maximo ' . self::MAX_EVENTS_PER_REQUEST . ' eventos por peticion.');
        }

        $this->lastRequest = json_encode([
            'events'  => array_values($events),
            'version' => SecuritasEventMapper::VERSION,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        $this->lastBody = null;

        $this->throttle();

        try {
            $response = $this->http->request('POST', $this->url, [
                RequestOptions::HEADERS => [
                    'Content-Type' => 'application/json',
                    'Accept'       => 'application/json, text/plain, */*',
                    'X-API-Key'    => $this->apiKey,
                ],
                RequestOptions::BODY => $this->lastRequest,
            ]);
        } catch (ConnectException $e) {
            throw new SecuritasTransportException($e->getMessage(), 0, $e);
        } catch (GuzzleException $e) {
            throw new SecuritasTransportException($e->getMessage(), 0, $e);
        } finally {
            self::$lastRequestAt = microtime(true);
        }

        $this->lastBody = (string) $response->getBody();

        return $response->getStatusCode();
    }

    public function lastRequest(): ?string
    {
        return $this->lastRequest;
    }

    public function lastResponse(): ?string
    {
        return $this->lastBody;
    }

    public function maskedKey(): string
    {
        return strlen($this->apiKey) > 10
            ? substr($this->apiKey, 0, 4) . '...' . substr($this->apiKey, -4)
            : '***';
    }

    private function throttle(): void
    {
        $elapsedMs = (microtime(true) - self::$lastRequestAt) * 1000;
        if (self::$lastRequestAt > 0 && $elapsedMs < self::MIN_INTERVAL_MS) {
            usleep((int) ((self::MIN_INTERVAL_MS - $elapsedMs) * 1000));
        }
    }
}
