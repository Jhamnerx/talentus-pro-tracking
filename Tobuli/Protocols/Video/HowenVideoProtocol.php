<?php

namespace Tobuli\Protocols\Video;

use Tobuli\Entities\Device;

/**
 * Implementación VideoProtocol para dispositivos Howen MDVR (H-Protocol V3.9.5).
 *
 * Los nombres de parámetros en cada método coinciden exactamente con los
 * @QueryParam / campos JSON que espera HowenResource.java en Traccar.
 *
 * Capacidades disponibles:
 *   - Live view (0x4010)        ✔  channel, stream, mediaServer, frameList
 *   - Snapshot  (0x4020)        ✔  channel, session
 *   - Audio     (0x4030)        ✔  channel, mode, mediaServer
 *   - File search (0x4060)      ✔  channels, startTime, endTime, fileType, storage, stream
 *   - File poll  (0x1060)       ✔  session
 *   - Playback  (0x4070)        ✔  channels, startTime, endTime, mediaServer, fileName, act, frameList, stream
 */
class HowenVideoProtocol extends BaseVideoProtocol
{
    public function isSupported(): bool
    {
        return true;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Construye query string desde array, omitiendo valores nulos.
     * Siempre incluye uniqueId del dispositivo para que HowenResource.java
     * pueda resolver el ID interno de Traccar sin depender del traccar_device_id
     * almacenado en talentus.
     */
    private function query(Device $device, array $params): string
    {
        $base = ['uniqueId' => (string) $device->imei];
        $merged = array_merge($base, $params);
        $pairs = [];
        foreach ($merged as $k => $v) {
            if ($v !== null && $v !== '') {
                $pairs[] = urlencode($k) . '=' . urlencode((string) $v);
            }
        }
        return $pairs ? '?' . implode('&', $pairs) : '';
    }

    // ── Implementaciones ─────────────────────────────────────────────────────

    public function status(Device $device): array
    {
        $qs = $this->query($device, []);
        return ($this->get)("api/howen/0/status{$qs}");
    }

    /**
     * Inicia live view.
     *
     * $params extras opcionales (pasan como query string a Traccar):
     *   mediaServer  string  IP:port del servidor de media (default: auto)
     *   frameList    string  tipos de frame, p.ej. "1;2;3"   (default: "1;2;3")
     */
    public function liveStart(Device $device, int $channel, int $stream, array $extra = []): array
    {
        $qs = $this->query($device, [
            'channel'     => $channel,
            'stream'      => $stream,
            'mediaServer' => $extra['mediaServer'] ?? null,
            'frameList'   => $extra['frameList']   ?? null,
        ]);

        $result = ($this->post)("api/howen/0/live/start{$qs}", []);

        if (! empty($result['session'])) {
            $config  = config('tracker');
            $baseUrl = ($this->getBaseUrl)() . ':' . $config['web.port'];
            $result['hls_url'] = "{$baseUrl}/api/stream/0/live.m3u8?uniqueId={$device->imei}&channel={$channel}";
        }

        return $result;
    }

    public function liveStop(Device $device, int $channel, int $stream = 1): array
    {
        $qs = $this->query($device, ['channel' => $channel, 'stream' => $stream]);
        return ($this->post)("api/howen/0/live/stop{$qs}", []);
    }

    /**
     * Solicita snapshot.
     *
     * $channels: canal único "1" o lista semicolon "1;2;3"
     * $session:  ID de sesión opcional (auto-generado en Traccar si null)
     */
    public function snapshot(Device $device, string $channels, ?string $session = null): array
    {
        $qs = $this->query($device, ['channel' => $channels, 'session' => $session]);
        return ($this->post)("api/howen/0/snapshot{$qs}", []);
    }

    /**
     * Inicia audio bidireccional.
     *
     * mode: 0=escucha, 1=intercomunicador, 2=difusión
     */
    public function audioStart(Device $device, int $channel, int $mode, ?string $mediaServer = null): array
    {
        $qs = $this->query($device, [
            'channel'     => $channel,
            'mode'        => $mode,
            'mediaServer' => $mediaServer,
        ]);
        return ($this->post)("api/howen/0/audio/start{$qs}", []);
    }

    public function audioStop(Device $device, int $channel): array
    {
        $qs = $this->query($device, ['channel' => $channel]);
        return ($this->post)("api/howen/0/audio/stop{$qs}", []);
    }

    /**
     * Búsqueda de archivos grabados.
     *
     * $params esperados (nombres exactos del HowenResource.java):
     *   channels   string  "1" o "1;2"           (requerido)
     *   startTime  string  "2024-01-01 00:00:00"  (requerido)
     *   endTime    string  "2024-01-02 00:00:00"  (requerido)
     *   fileType   string  "1" = normal            (requerido)
     *   storage    string  "sd1;hd1"               (opcional)
     *   stream     string  "0"=sub / "1"=main      (opcional)
     */
    public function filesSearch(Device $device, array $params): array
    {
        $params['uniqueId'] = (string) $device->imei;
        return ($this->post)("api/howen/0/files/search", $params);
    }

    public function filesPoll(Device $device, string $session): array
    {
        $qs = $this->query($device, []);
        return ($this->get)("api/howen/0/files/{$session}{$qs}");
    }

    /**
     * Inicia reproducción remota.
     *
     * $params esperados:
     *   channels    string  (requerido)
     *   startTime   string  (requerido, o fileName)
     *   endTime     string  (requerido, o fileName)
     *   mediaServer string  IP:port (requerido)
     *   fileName    string  ruta del archivo de alarma (opcional)
     *   act         string  "0"=velocidad máx, "1"=normal (opcional)
     *   frameList   string  "1;2;3" (opcional)
     *   stream      string  "0"=sub / "1"=main (opcional)
     */
    public function playbackStart(Device $device, array $params): array
    {
        $params['uniqueId'] = (string) $device->imei;
        return ($this->post)("api/howen/0/playback/start", $params);
    }

    /**
     * Activa grabación manual en la SD del canal especificado.
     * El dispositivo comienza a escribir un archivo nuevo inmediatamente.
     */
    public function recordingStart(Device $device, int $channel): array
    {
        $qs = $this->query($device, ['channel' => $channel]);
        return ($this->post)("api/howen/0/recording/start{$qs}", []);
    }

    /**
     * Detiene la grabación manual en curso del canal especificado.
     * El archivo queda cerrado en la SD y puede buscarse con filesSearch.
     */
    public function recordingStop(Device $device, int $channel): array
    {
        $qs = $this->query($device, ['channel' => $channel]);
        return ($this->post)("api/howen/0/recording/stop{$qs}", []);
    }
}
