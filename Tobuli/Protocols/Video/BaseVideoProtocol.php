<?php

namespace Tobuli\Protocols\Video;

use Tobuli\Entities\Device;

/**
 * Implementación base que retorna "no soportado" para todos los métodos.
 * Se usa como fallback cuando el protocolo del dispositivo no tiene
 * una clase VideoProtocol registrada.
 *
 * Las dos callables recibidas en el constructor encapsulan el transporte
 * HTTP hacia Traccar, separando el protocolo de la infraestructura de red.
 */
class BaseVideoProtocol implements VideoProtocol
{
    /** @var callable fn(string $endpoint, array $data): array — POST autenticado a Traccar */
    protected $post;

    /** @var callable fn(string $endpoint): array — GET autenticado a Traccar */
    protected $get;

    /** @var callable fn(): string — Retorna la URL base interna de Traccar (sin puerto) */
    protected $getBaseUrl;

    public function __construct(callable $post, callable $get, callable $getBaseUrl = null)
    {
        $this->post       = $post;
        $this->get        = $get;
        $this->getBaseUrl = $getBaseUrl ?? fn() => 'http://localhost';
    }

    public function isSupported(): bool
    {
        return false;
    }

    protected function unsupported(): array
    {
        return ['status' => 0, 'message' => 'Video not supported by this protocol'];
    }

    public function status(Device $device): array
    {
        return $this->unsupported();
    }
    public function liveStart(Device $device, int $channel, int $stream, array $extra = []): array
    {
        return $this->unsupported();
    }
    public function liveStop(Device $device, int $channel, int $stream = 1): array
    {
        return $this->unsupported();
    }
    public function snapshot(Device $device, string $channels, ?string $session = null): array
    {
        return $this->unsupported();
    }
    public function audioStart(Device $device, int $channel, int $mode, ?string $mediaServer = null): array
    {
        return $this->unsupported();
    }
    public function audioStop(Device $device, int $channel): array
    {
        return $this->unsupported();
    }
    public function filesSearch(Device $device, array $params): array
    {
        return $this->unsupported();
    }
    public function filesPoll(Device $device, string $session): array
    {
        return $this->unsupported();
    }
    public function playbackStart(Device $device, array $params): array
    {
        return $this->unsupported();
    }
    public function recordingStart(Device $device, int $channel): array
    {
        return $this->unsupported();
    }
    public function recordingStop(Device $device, int $channel): array
    {
        return $this->unsupported();
    }
    public function playbackControl(Device $device, array $params): array
    {
        return $this->unsupported();
    }
}
