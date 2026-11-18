<?php

namespace App\Services\Ai;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use Psr\Http\Message\ResponseInterface;

/**
 * Cliente minimo de la Messages API de Claude sobre Guzzle 6.
 *
 * El proyecto corre en PHP 7.4/8.0 y el SDK oficial requiere 8.1+, asi que
 * aqui se implementa lo necesario: peticion con streaming (SSE), reintentos
 * ante 429/5xx antes de empezar a recibir, y reconstruccion del mensaje final
 * conservando cada bloque tal como llega (thinking, firma, tool_use, etc.).
 *
 * Los bloques se manejan como objetos (stdClass) para que un input vacio
 * se reenvie como {} y no como [].
 */
class ClaudeClient
{
    const FALLBACK_BETA = 'server-side-fallback-2026-07-01';

    /** @var Client */
    private $http;

    private $config;

    public function __construct(array $config = null)
    {
        if ($config === null) {
            AiSettings::apply(); // clave y modelos guardados en Admin
            $config = config('ai');
        }

        $this->config = $config;

        $this->http = new Client([
            'base_uri'        => rtrim($this->config['base_url'], '/') . '/',
            'connect_timeout' => $this->config['connect_timeout'] ?? 10,
            'timeout'         => $this->config['timeout'] ?? 180,
            'read_timeout'    => $this->config['timeout'] ?? 180,
            'http_errors'     => false,
        ]);
    }

    public static function isConfigured(): bool
    {
        return AiSettings::isConfigured();
    }

    /**
     * Envia una peticion con stream=true.
     *
     * @param array    $body    cuerpo de la peticion (sin "stream")
     * @param callable $onText  fn(string $delta) por cada fragmento de texto
     * @return object  mensaje final: id, model, stop_reason, stop_details, content[], usage
     * @throws ClaudeException
     */
    public function stream(array $body, callable $onText = null)
    {
        $body['stream'] = true;

        $response = $this->send($body);

        return $this->readStream($response, $onText);
    }

    private function headers(array $body): array
    {
        $headers = [
            'x-api-key'         => $this->config['api_key'],
            'anthropic-version' => $this->config['api_version'],
            'content-type'      => 'application/json',
            'accept'            => 'text/event-stream',
        ];

        // La beta solo acompana a las peticiones que piden el fallback
        // (Haiku 5.5 no lo tiene).
        if (isset($body['fallbacks'])) {
            $headers['anthropic-beta'] = self::FALLBACK_BETA;
        }

        return $headers;
    }

    private function send(array $body): ResponseInterface
    {
        $attempts = 0;
        $maxRetries = (int) ($this->config['max_retries'] ?? 2);
        $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        while (true) {
            $attempts++;

            try {
                $response = $this->http->request('POST', 'v1/messages', [
                    'headers' => $this->headers($body),
                    'body'    => $json,
                    'stream'  => true,
                ]);
            } catch (ConnectException $e) {
                if ($attempts <= $maxRetries) {
                    sleep($attempts);
                    continue;
                }

                throw new ClaudeException('connection', $e->getMessage(), 0);
            }

            $status = $response->getStatusCode();

            if ($status === 200) {
                return $response;
            }

            $error = json_decode((string) $response->getBody(), true);
            $type = $error['error']['type'] ?? 'http_error';
            $message = $error['error']['message'] ?? ('HTTP ' . $status);

            $retryable = in_array($status, [408, 409, 429, 500, 502, 503, 504, 529]);

            if ($retryable && $attempts <= $maxRetries) {
                $wait = (int) ($response->getHeaderLine('retry-after') ?: $attempts * 2);
                sleep(min(max($wait, 1), 20));
                continue;
            }

            throw new ClaudeException($type, $message, $status);
        }
    }

    private function readStream(ResponseInterface $response, callable $onText = null)
    {
        $stream = $response->getBody();
        $buffer = '';

        $message = (object) [
            'id' => null, 'model' => null, 'stop_reason' => null,
            'stop_details' => null, 'content' => [], 'usage' => new \stdClass(),
        ];
        $blocks = [];
        $partialJson = [];

        while (!$stream->eof()) {
            $buffer .= $stream->read(8192);
            $buffer = str_replace("\r\n", "\n", $buffer);

            while (($pos = strpos($buffer, "\n\n")) !== false) {
                $rawEvent = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 2);

                $data = null;
                foreach (explode("\n", $rawEvent) as $line) {
                    if (strpos($line, 'data:') === 0) {
                        $data = ($data ?? '') . ltrim(substr($line, 5));
                    }
                }

                if ($data === null) {
                    continue;
                }

                $event = json_decode($data);

                if (!$event || !isset($event->type)) {
                    continue;
                }

                switch ($event->type) {
                    case 'message_start':
                        $message->id = $event->message->id ?? null;
                        $message->model = $event->message->model ?? null;
                        $this->mergeUsage($message->usage, $event->message->usage ?? null);
                        break;

                    case 'content_block_start':
                        $blocks[$event->index] = $event->content_block;
                        if (($event->content_block->type ?? null) === 'tool_use') {
                            $partialJson[$event->index] = '';
                        }
                        break;

                    case 'content_block_delta':
                        $this->applyDelta($blocks, $partialJson, $event, $onText);
                        break;

                    case 'content_block_stop':
                        $i = $event->index;
                        if (isset($partialJson[$i]) && isset($blocks[$i])) {
                            $raw = $partialJson[$i];
                            $input = $raw === '' ? new \stdClass() : json_decode($raw);

                            if ($raw !== '' && (json_last_error() !== JSON_ERROR_NONE || !is_object($input))) {
                                // JSON invalido: el agente responde con un error a Claude.
                                $blocks[$i]->input = new \stdClass();
                                $blocks[$i]->_invalid_json = $raw;
                            } else {
                                $blocks[$i]->input = $input;
                            }
                        }
                        break;

                    case 'message_delta':
                        if (isset($event->delta->stop_reason)) {
                            $message->stop_reason = $event->delta->stop_reason;
                        }
                        if (isset($event->delta->stop_details)) {
                            $message->stop_details = $event->delta->stop_details;
                        }
                        $this->mergeUsage($message->usage, $event->usage ?? null);
                        break;

                    case 'error':
                        throw new ClaudeException(
                            $event->error->type ?? 'stream_error',
                            $event->error->message ?? 'Error en el stream',
                            0
                        );
                }
            }
        }

        ksort($blocks);
        $message->content = array_values($blocks);

        return $message;
    }

    private function applyDelta(array &$blocks, array &$partialJson, $event, callable $onText = null)
    {
        $i = $event->index;
        $delta = $event->delta ?? null;

        if (!$delta || !isset($blocks[$i])) {
            return;
        }

        switch ($delta->type ?? null) {
            case 'text_delta':
                $blocks[$i]->text = ($blocks[$i]->text ?? '') . $delta->text;
                if ($onText && ($blocks[$i]->type ?? null) === 'text') {
                    $onText($delta->text);
                }
                break;
            case 'thinking_delta':
                $blocks[$i]->thinking = ($blocks[$i]->thinking ?? '') . $delta->thinking;
                break;
            case 'signature_delta':
                $blocks[$i]->signature = $delta->signature;
                break;
            case 'input_json_delta':
                $partialJson[$i] = ($partialJson[$i] ?? '') . $delta->partial_json;
                break;
            case 'citations_delta':
                $blocks[$i]->citations = array_merge($blocks[$i]->citations ?? [], [$delta->citation]);
                break;
        }
    }

    private function mergeUsage($target, $usage)
    {
        if (!$usage) {
            return;
        }

        foreach ((array) $usage as $key => $value) {
            // Numeros y el desglose anidado cache_creation (ephemeral_5m/1h).
            if (is_numeric($value) || ($key === 'cache_creation' && is_object($value))) {
                $target->{$key} = $value;
            }
        }
    }
}
