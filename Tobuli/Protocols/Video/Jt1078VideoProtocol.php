<?php

namespace Tobuli\Protocols\Video;

use Illuminate\Support\Facades\DB;
use Tobuli\Entities\Device;

/**
 * Implementación VideoProtocol para dispositivos JT/T 1078.
 *
 * Usa los endpoints REST de Jt1078Resource.java (Traccar) que envían comandos
 * JT808-enmarcados al dispositivo a través del puerto de señalización dedicado
 * (jt1078.signaling.port).
 *
 * Capacidades:
 *   - Live view          ✔  (0x9101 + 0x9102)
 *   - Status             ✔  (GET /jt1078/{id}/status)
 *   - Audio bidireccional ✔ (0x9101 dataType=4 intercom)
 *   - File search        ✔  (0x9205 → 0x1205)
 *   - File poll          ✔  (GET /jt1078/{id}/files/{session})
 *   - Playback           ✔  (0x9201 + 0x9202)
 *   - Snapshot           ✘  (no existe en JT1078)
 *   - Recording start/stop ✘ (no hay comando equivalente)
 */
class Jt1078VideoProtocol extends BaseVideoProtocol
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
     * El campo traccar_device_id almacena el ID de la tabla traccar_devices
     * de talentus, que NO necesariamente coincide con el ID de tc_devices
     * en Traccar. La resolución por IMEI en BD es siempre correcta.
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

    private function hlsUrl(Device $device, int $channel): string
    {
        $config  = config('tracker');
        $baseUrl = rtrim(
            str_replace('https://', 'http://', config('app.url')),
            '/'
        ) . ':' . $config['web.port'];

        return "{$baseUrl}/api/stream/{$this->tid($device)}/live.m3u8?channel={$channel}";
    }

    // ── Implementaciones ─────────────────────────────────────────────────────

    /**
     * Consulta si el dispositivo tiene una conexión de señalización activa.
     */
    public function status(Device $device): array
    {
        $result = ($this->get)("api/jt1078/{$this->tid($device)}/status");

        if (! isset($result['error'])) {
            $result['hls_url'] = $this->hlsUrl($device, 1);
        }

        return $result;
    }

    /**
     * Inicia stream en tiempo real (0x9101).
     *
     * $extra opcionales:
     *   dataType    int  0=A+V, 1=audio, 2=video, 3=video/A+V (default 0)
     *   streamType  int  0=main, 1=sub (default 0)
     *   mediaServer string  IP del servidor de media (default: autodetect)
     */
    public function liveStart(Device $device, int $channel, int $stream, array $extra = []): array
    {
        $qs = $this->query([
            'channel'     => $channel,
            'dataType'    => $extra['dataType']    ?? 0,
            'streamType'  => $stream,
            'mediaServer' => $extra['mediaServer'] ?? null,
        ]);

        $result = ($this->post)("api/jt1078/{$this->tid($device)}/live/start{$qs}", []);

        if (! isset($result['error'])) {
            $result['hls_url'] = $this->hlsUrl($device, $channel);
        }

        return $result;
    }

    /**
     * Detiene stream en tiempo real (0x9102 control=0 cancel).
     */
    public function liveStop(Device $device, int $channel, int $stream = 1): array
    {
        $qs = $this->query([
            'channel'    => $channel,
            'control'    => 0,       // CONTROL_CANCEL
            'cancelType' => 0,       // A+V
        ]);

        return ($this->post)("api/jt1078/{$this->tid($device)}/live/control{$qs}", []);
    }

    /**
     * Snapshot: no soportado por JT1078.
     */
    public function snapshot(Device $device, string $channels, ?string $session = null): array
    {
        return $this->unsupported();
    }

    /**
     * Inicia audio bidireccional (intercom) como dataType=4 (0x9101).
     *
     * $mode: 0=escucha, 1=intercom, 2=broadcast
     */
    public function audioStart(Device $device, int $channel, int $mode, ?string $mediaServer = null): array
    {
        // dataType 4 = intercom en JT1078; para escucha unidireccional usar 1=audio
        $dataType = $mode >= 1 ? 4 : 1;

        $qs = $this->query([
            'channel'     => $channel,
            'dataType'    => $dataType,
            'streamType'  => 0,
            'mediaServer' => $mediaServer,
        ]);

        return ($this->post)("api/jt1078/{$this->tid($device)}/live/start{$qs}", []);
    }

    /**
     * Detiene audio (0x9102 control=4 cancel-intercom, o control=0 para canal).
     */
    public function audioStop(Device $device, int $channel): array
    {
        $qs = $this->query([
            'channel'    => $channel,
            'control'    => 4,   // CONTROL_CANCEL_INTERCOM
        ]);

        return ($this->post)("api/jt1078/{$this->tid($device)}/live/control{$qs}", []);
    }

    /**
     * Busca archivos en el dispositivo (0x9205).
     *
     * $params:
     *   channel      int     (default 0 = todos)
     *   startTime    string  "yyyy-MM-dd HH:mm:ss"
     *   endTime      string  "yyyy-MM-dd HH:mm:ss"
     *   resourceType int     0=A+V, 1=audio, 2=video, 3=video/A+V
     *   streamType   int     0=todos, 1=main, 2=sub
     *   storageType  int     0=todos, 1=main, 2=backup
     */
    public function filesSearch(Device $device, array $params): array
    {
        $qs = $this->query([
            'channel'      => $params['channel']      ?? 0,
            'startTime'    => $params['startTime']    ?? null,
            'endTime'      => $params['endTime']      ?? null,
            'resourceType' => $params['resourceType'] ?? 0,
            'streamType'   => $params['streamType']   ?? 0,
            'storageType'  => $params['storageType']  ?? 0,
        ]);

        return ($this->post)("api/jt1078/{$this->tid($device)}/files/search{$qs}", []);
    }

    /**
     * Consulta resultados asíncronos de búsqueda de archivos (0x1205).
     */
    public function filesPoll(Device $device, string $session): array
    {
        return ($this->get)("api/jt1078/{$this->tid($device)}/files/{$session}");
    }

    /**
     * Inicia reproducción remota (0x9201).
     *
     * $params:
     *   channel      int
     *   startTime    string  "yyyy-MM-dd HH:mm:ss"
     *   endTime      string  "yyyy-MM-dd HH:mm:ss"
     *   avType       int     0=A+V, 1=audio, 2=video, 3=video/A+V
     *   streamType   int     0=main/sub, 1=main, 2=sub
     *   storageType  int     0=todos, 1=main, 2=backup
     *   playbackMode int     0=normal, 1=FF, 2=key-frame rewind, 3=key-frame, 4=single-frame
     *   multiplier   int     velocidad FF/rewind (1-5)
     *   mediaServer  string  IP del servidor de media
     */
    public function playbackStart(Device $device, array $params): array
    {
        $qs = $this->query([
            'channel'      => $params['channel']      ?? 1,
            'avType'       => $params['avType']       ?? 0,
            'streamType'   => $params['streamType']   ?? 0,
            'storageType'  => $params['storageType']  ?? 0,
            'playbackMode' => $params['playbackMode'] ?? 0,
            'multiplier'   => $params['multiplier']   ?? 0,
            'startTime'    => $params['startTime']    ?? null,
            'endTime'      => $params['endTime']      ?? null,
            'mediaServer'  => $params['mediaServer']  ?? null,
        ]);

        $result = ($this->post)("api/jt1078/{$this->tid($device)}/playback/start{$qs}", []);

        if (! isset($result['error'])) {
            $channel = $params['channel'] ?? 1;
            $result['hls_url'] = $this->hlsUrl($device, $channel);
        }

        return $result;
    }

    /**
     * Control de reproducción remota (0x9202).
     *
     * $params:
     *   channel    int   (default 1)
     *   control    int   0=start, 1=pause, 2=end, 3=FF, 4=rewind, 5=seek, 6=key-frame
     *   multiplier int   velocidad FF/rewind (default 0)
     *   seekTime   string "yyyy-MM-dd HH:mm:ss" (solo para control=5)
     */
    public function playbackControl(Device $device, array $params): array
    {
        $qs = $this->query([
            'channel'    => $params['channel']    ?? 1,
            'control'    => $params['control']    ?? 2,
            'multiplier' => $params['multiplier'] ?? 0,
            'seekTime'   => $params['seekTime']   ?? null,
        ]);

        return ($this->post)("api/jt1078/{$this->tid($device)}/playback/control{$qs}", []);
    }

    /**
     * Grabación local: no soportado en JT1078 (no existe comando equivalente al 0x4040 de Howen).
     */
    public function recordingStart(Device $device, int $channel): array
    {
        return $this->unsupported();
    }

    public function recordingStop(Device $device, int $channel): array
    {
        return $this->unsupported();
    }
}
