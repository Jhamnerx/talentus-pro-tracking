<?php

namespace Tobuli\Services;

use Tobuli\Entities\Device;
use Tobuli\Exceptions\ValidationException;
use Tobuli\Helpers\Tracker;
use Tobuli\Protocols\Video\VideoManager;
use Tobuli\Protocols\Video\BaseVideoProtocol;

/**
 * Capa de servicio para operaciones de video en dispositivos GPS.
 *
 * Responsabilidades de esta clase:
 *  - Validar que el Device tenga traccar_device_id
 *  - Proveer el transporte HTTP hacia Traccar (vía Tracker inyectado)
 *  - Delegar cada operación al VideoProtocol correspondiente vía VideoManager
 *
 * Para añadir soporte a un nuevo protocolo basta con crear:
 *   Tobuli/Protocols/Video/{NombreProtocolo}VideoProtocol.php
 * sin modificar esta clase ni el controlador.
 */
class DeviceVideoService
{
    private Tracker $tracker;

    public function __construct()
    {
        $this->tracker = new Tracker();
    }

    // =========================================================================
    // Resolución de protocolo
    // =========================================================================

    /**
     * Retorna la implementación VideoProtocol adecuada para el dispositivo,
     * inyectando las dos callables de transporte HTTP.
     */
    private function protocol(Device $device): BaseVideoProtocol
    {
        return (new VideoManager)->protocol(
            $device->protocol ?? '',
            fn($endpoint, $data) => $this->tracker->api($endpoint, $data),
            fn($endpoint)        => $this->apiGet($endpoint)
        );
    }

    /**
     * Valida que el Device tenga traccar_device_id asignado.
     */
    private function assertHasTraccarId(Device $device): void
    {
        if (! $device->traccar_device_id) {
            throw new ValidationException(['device' => 'Device has no traccar_device_id']);
        }
    }

    /**
     * HTTP GET a Traccar con las mismas credenciales que Tracker::api().
     */
    private function apiGet(string $endpoint): array
    {
        $curl = new \Curl;

        $curl->headers = [
            'Authorization' => 'Basic ' . base64_encode('admin:' . config('app.admin_user')),
            'Accept'        => 'application/json',
        ];

        $curl->options['CURLOPT_HEADER']         = false;
        $curl->options['CURLOPT_RETURNTRANSFER'] = true;

        $config = config('tracker');
        $url    = $this->tracker->getUrl() . ':' . $config['web.port'] . '/' . $endpoint;

        try {
            $response = $curl->get($url);
            $decoded  = json_decode($response, true);

            return is_array($decoded) ? $decoded : [];
        } catch (\CurlException $e) {
            return ['status' => 0, 'message' => $e->getMessage()];
        }
    }

    // =========================================================================
    // API pública — delega al VideoProtocol del dispositivo
    // =========================================================================

    /**
     * Indica si el protocolo del dispositivo soporta video.
     * Úsalo en el frontend para ocultar/mostrar controles de video.
     */
    public function isSupported(Device $device): bool
    {
        if (! $device->traccar_device_id) {
            return false;
        }
        return $this->protocol($device)->isSupported();
    }

    public function status(Device $device): array
    {
        $this->assertHasTraccarId($device);
        return $this->protocol($device)->status($device);
    }

    /**
     * @param  array  $extra  Parámetros opcionales del protocolo:
     *                        Howen: mediaServer, frameList
     */
    public function startLive(Device $device, int $channel = 1, int $stream = 1, array $extra = []): array
    {
        $this->assertHasTraccarId($device);
        return $this->protocol($device)->liveStart($device, $channel, $stream, $extra);
    }

    public function stopLive(Device $device, int $channel = 1, int $stream = 1): array
    {
        $this->assertHasTraccarId($device);
        return $this->protocol($device)->liveStop($device, $channel, $stream);
    }

    /**
     * @param  string       $channels  Canal único "1" o lista "1;2;3"
     * @param  string|null  $session   ID de sesión (auto si null)
     */
    public function snapshot(Device $device, string $channels = '1', ?string $session = null): array
    {
        $this->assertHasTraccarId($device);
        return $this->protocol($device)->snapshot($device, $channels, $session);
    }

    /**
     * @param  string|null  $mediaServer  IP:port del servidor receptor (auto si null)
     */
    public function startAudio(Device $device, int $channel = 1, int $mode = 0, ?string $mediaServer = null): array
    {
        $this->assertHasTraccarId($device);
        return $this->protocol($device)->audioStart($device, $channel, $mode, $mediaServer);
    }

    public function stopAudio(Device $device, int $channel = 1): array
    {
        $this->assertHasTraccarId($device);
        return $this->protocol($device)->audioStop($device, $channel);
    }

    /**
     * Howen: keys esperadas en $params:
     *   channels, startTime, endTime, fileType, storage (opt), stream (opt)
     */
    public function searchFiles(Device $device, array $params): array
    {
        $this->assertHasTraccarId($device);
        return $this->protocol($device)->filesSearch($device, $params);
    }

    public function pollFileSearch(Device $device, string $session): array
    {
        $this->assertHasTraccarId($device);
        return $this->protocol($device)->filesPoll($device, $session);
    }

    public function startPlayback(Device $device, array $params): array
    {
        $this->assertHasTraccarId($device);
        return $this->protocol($device)->playbackStart($device, $params);
    }

    /**
     * Control de reproducción JT1078 (0x9202).
     * $data keys: channel, control, multiplier, seekTime
     */
    public function controlPlayback(Device $device, array $data): array
    {
        $this->assertHasTraccarId($device);
        return $this->protocol($device)->playbackControl($device, $data);
    }

    /**
     * Activa grabación manual en la SD del canal especificado.
     * El dispositivo escribe el archivo inmediatamente.
     */
    public function startRecording(Device $device, int $channel = 1): array
    {
        $this->assertHasTraccarId($device);
        return $this->protocol($device)->recordingStart($device, $channel);
    }

    /**
     * Detiene la grabación manual en curso.
     * El archivo queda cerrado y disponible para buscar con searchFiles().
     */
    public function stopRecording(Device $device, int $channel = 1): array
    {
        $this->assertHasTraccarId($device);
        return $this->protocol($device)->recordingStop($device, $channel);
    }

    // =========================================================================
    // Video de evidencia de eventos
    // =========================================================================

    /**
     * Inicia búsqueda de archivos grabados en torno a un instante de tiempo.
     *
     * Uso típico: cuando se genera una alerta/evento, llamar con el timestamp
     * del evento y los segundos de margen deseados. El resultado contiene un
     * `session` que debes pollear con pollFileSearch() hasta obtener la lista
     * de archivos.  Luego usa startPlayback() con el fileName del clip deseado.
     *
     * @param  Device   $device
     * @param  int      $eventTimestamp   Unix timestamp del evento (en segundos)
     * @param  string   $channels         Canal(es) de video, ej. "1" o "1;2"
     * @param  int      $secondsBefore    Segundos de video antes del evento (default 30)
     * @param  int      $secondsAfter     Segundos de video después del evento (default 30)
     * @param  string   $fileType         Tipo de archivo Howen ("1"=normal, "2"=alarma)
     * @param  array    $extra            Parámetros adicionales: storage, stream
     * @return array    { session: "search_<id>" }
     */
    public function eventClip(
        Device $device,
        int    $eventTimestamp,
        string $channels      = '1',
        int    $secondsBefore = 30,
        int    $secondsAfter  = 30,
        string $fileType      = '2',
        array  $extra         = []
    ): array {
        $this->assertHasTraccarId($device);

        $params = array_merge([
            'channels'  => $channels,
            'startTime' => date('Y-m-d H:i:s', $eventTimestamp - $secondsBefore),
            'endTime'   => date('Y-m-d H:i:s', $eventTimestamp + $secondsAfter),
            'fileType'  => $fileType,
        ], $extra);

        return $this->protocol($device)->filesSearch($device, $params);
    }
}
