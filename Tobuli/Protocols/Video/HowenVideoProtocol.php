<?php

namespace Tobuli\Protocols\Video;

use Illuminate\Support\Facades\DB;
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
    /** ID real de Traccar resuelto por IMEI (caché de instancia). */
    private ?int $resolvedTid = null;

    public function isSupported(): bool
    {
        return true;
    }

    // ── Helper ────────────────────────────────────────────────────────────────

    /**
     * Resuelve el ID interno de Traccar (tc_devices.id) consultando
     * directamente la BD de Traccar por uniqueId (IMEI).
     *
     * traccar_device_id almacena el ID de la tabla traccar_devices de talentus,
     * que NO necesariamente coincide con tc_devices.id en Traccar.
     */
    private function tid(Device $device): int
    {
        if ($this->resolvedTid === null) {
            $imei = (string) $device->imei;

            if ($imei !== '') {
                $row = DB::connection('traccar_mysql')
                    ->table('tc_devices')
                    ->where('uniqueId', $imei)
                    ->value('id');

                $this->resolvedTid = $row ? (int) $row : (int) $device->traccar_device_id;
            } else {
                $this->resolvedTid = (int) $device->traccar_device_id;
            }
        }

        return $this->resolvedTid;
    }

    /**
     * Construye query string desde array, omitiendo valores nulos.
     */
    private function query(array $params): string
    {
        $pairs = [];
        foreach ($params as $k => $v) {
            if ($v !== null && $v !== '') {
                $pairs[] = urlencode($k) . '=' . urlencode((string) $v);
            }
        }
        return $pairs ? '?' . implode('&', $pairs) : '';
    }

    // ── Implementaciones ─────────────────────────────────────────────────────

    public function status(Device $device): array
    {
        return ($this->get)("api/howen/{$this->tid($device)}/status");
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
        $qs = $this->query(array_merge([
            'channel'     => $channel,
            'stream'      => $stream,
            'mediaServer' => $extra['mediaServer'] ?? null,
            'frameList'   => $extra['frameList']   ?? null,
        ]));

        $result = ($this->post)("api/howen/{$this->tid($device)}/live/start{$qs}", []);

        if (! empty($result['session'])) {
            $config  = config('tracker');
            $baseUrl = ($this->getBaseUrl)() . ':' . $config['web.port'];
            $result['hls_url'] = "{$baseUrl}/api/stream/{$this->tid($device)}/live.m3u8?channel={$channel}";
        }

        return $result;
    }

    public function liveStop(Device $device, int $channel, int $stream = 1): array
    {
        $qs = $this->query(['channel' => $channel, 'stream' => $stream]);
        return ($this->post)("api/howen/{$this->tid($device)}/live/stop{$qs}", []);
    }

    /**
     * Solicita snapshot.
     *
     * $channels: canal único "1" o lista semicolon "1;2;3"
     * $session:  ID de sesión opcional (auto-generado en Traccar si null)
     */
    public function snapshot(Device $device, string $channels, ?string $session = null): array
    {
        $qs = $this->query(['channel' => $channels, 'session' => $session]);
        return ($this->post)("api/howen/{$this->tid($device)}/snapshot{$qs}", []);
    }

    /**
     * Inicia audio bidireccional.
     *
     * mode: 0=escucha, 1=intercomunicador, 2=difusión
     */
    public function audioStart(Device $device, int $channel, int $mode, ?string $mediaServer = null): array
    {
        $qs = $this->query([
            'channel'     => $channel,
            'mode'        => $mode,
            'mediaServer' => $mediaServer,
        ]);
        return ($this->post)("api/howen/{$this->tid($device)}/audio/start{$qs}", []);
    }

    public function audioStop(Device $device, int $channel): array
    {
        $qs = $this->query(['channel' => $channel]);
        return ($this->post)("api/howen/{$this->tid($device)}/audio/stop{$qs}", []);
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
        return ($this->post)("api/howen/{$this->tid($device)}/files/search", $params);
    }

    public function filesPoll(Device $device, string $session): array
    {
        return ($this->get)("api/howen/{$this->tid($device)}/files/{$session}");
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
        return ($this->post)("api/howen/{$this->tid($device)}/playback/start", $params);
    }

    /**
     * Activa grabación manual en la SD del canal especificado.
     * El dispositivo comienza a escribir un archivo nuevo inmediatamente.
     */
    public function recordingStart(Device $device, int $channel): array
    {
        $qs = $this->query(['channel' => $channel]);
        return ($this->post)("api/howen/{$this->tid($device)}/recording/start{$qs}", []);
    }

    /**
     * Detiene la grabación manual en curso del canal especificado.
     * El archivo queda cerrado en la SD y puede buscarse con filesSearch.
     */
    public function recordingStop(Device $device, int $channel): array
    {
        $qs = $this->query(['channel' => $channel]);
        return ($this->post)("api/howen/{$this->tid($device)}/recording/stop{$qs}", []);
    }
}
