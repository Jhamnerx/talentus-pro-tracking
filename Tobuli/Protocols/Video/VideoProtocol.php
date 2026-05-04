<?php

namespace Tobuli\Protocols\Video;

use Tobuli\Entities\Device;

/**
 * Contrato para protocolos con soporte de video.
 *
 * Implementar esta interfaz en una clase {Nombre}VideoProtocol dentro de
 * este mismo namespace para añadir soporte a un nuevo protocolo.
 *
 * El VideoManager resuelve automáticamente la clase por nombre de protocolo
 * (igual que Tobuli\Protocols\Manager lo hace para comandos GPRS).
 */
interface VideoProtocol
{
    /**
     * Indica si el protocolo soporta video.
     * BaseVideoProtocol retorna false; las implementaciones concretas retornan true.
     */
    public function isSupported(): bool;

    /** Estado de la sesión del dispositivo en el servidor de media */
    public function status(Device $device): array;

    /** Inicia transmisión en vivo (channel 1-8, stream 1=main 2=sub, extra=params opcionales del protocolo) */
    public function liveStart(Device $device, int $channel, int $stream, array $extra = []): array;

    /** Detiene transmisión en vivo */
    public function liveStop(Device $device, int $channel, int $stream = 1): array;

    /** Solicita captura de imagen (channels = IDs separados por coma o semicolon) */
    public function snapshot(Device $device, string $channels, ?string $session = null): array;

    /** Inicia audio bidireccional (mode: 0=escucha, 1=intercomunicador, 2=difusión) */
    public function audioStart(Device $device, int $channel, int $mode, ?string $mediaServer = null): array;

    /** Detiene audio bidireccional */
    public function audioStop(Device $device, int $channel): array;

    /** Inicia búsqueda de grabaciones; retorna session_id para polling */
    public function filesSearch(Device $device, array $params): array;

    /** Consulta (y consume) resultados de una búsqueda previa */
    public function filesPoll(Device $device, string $session): array;

    /** Inicia reproducción remota de un archivo grabado */
    public function playbackStart(Device $device, array $params): array;

    /** Activa grabación manual en el almacenamiento interno del dispositivo (canal) */
    public function recordingStart(Device $device, int $channel): array;

    /** Detiene grabación manual */
    public function recordingStop(Device $device, int $channel): array;

    /** Control de reproducción remota en curso (JT1078: 0x9202) */
    public function playbackControl(Device $device, array $params): array;
}
