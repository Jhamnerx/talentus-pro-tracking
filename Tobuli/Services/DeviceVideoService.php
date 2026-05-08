<?php

namespace Tobuli\Services;

use Tobuli\Entities\Device;
use Tobuli\Helpers\Tracker;

class DeviceVideoService
{
    private Tracker $tracker;

    public function __construct()
    {
        $this->tracker = new Tracker();
    }

    public function isSupported(Device $device): bool
    {
        return in_array(strtolower($device->protocol ?? ''), ['howen', 'jt808', 'jt1078']);
    }

    /**
     * Sends 0x4010 live-view start to the device via commands/send.
     * Uses uniqueId (IMEI) — CommandResource resuelve el deviceId internamente.
     *
     * Returns:
     *   hls_url       — URL del stream HLS
     *   connected     — si el device tenía conexión activa al momento del envío
     *   initial_delay — ms que debe esperar el cliente antes del primer intento HLS
     */
    public function startLive(Device $device, int $channel = 1): array
    {
        $isConnected = $device->isConnected();

        $result = $this->tracker->api('api/commands/send', [
            'deviceId'   => 0,
            'uniqueId'   => (string) $device->imei,
            'type'       => 'videoStart',
            'attributes' => ['index' => $channel],
        ]);

        return [
            'hls_url'       => url("device_video/{$device->id}/stream/live.m3u8") . '?channel=' . $channel,
            'connected'     => $isConnected,
            // Si el device está conectado el stream arranca en ~6 s;
            // si está offline el comando queda en cola y puede tardar hasta 60 s.
            'initial_delay' => $isConnected ? 6000 : 20000,
            'cmd_status'    => $result['status'] ?? 0,
            'cmd_message'   => $result['message'] ?? null,
        ];
    }

    /**
     * Sends 0x4010 live-view stop to the device via commands/send.
     */
    public function stopLive(Device $device, int $channel = 1): void
    {
        $this->tracker->api('api/commands/send', [
            'deviceId'   => 0,
            'uniqueId'   => (string) $device->imei,
            'type'       => 'videoStop',
            'attributes' => ['index' => $channel],
        ]);
    }

    /**
     * Sends audioListen command to the device.
     * mode: 0 = listen only, 1 = intercom (two-way), 2 = broadcast
     */
    public function startAudio(Device $device, int $channel = 1, int $mode = 0): void
    {
        $this->tracker->api('api/commands/send', [
            'deviceId'   => 0,
            'uniqueId'   => (string) $device->imei,
            'type'       => 'audioListen',
            'attributes' => ['index' => $channel, 'mode' => $mode],
        ]);
    }

    /**
     * Sends audioStop command to the device.
     */
    public function stopAudio(Device $device, int $channel = 1): void
    {
        $this->tracker->api('api/commands/send', [
            'deviceId'   => 0,
            'uniqueId'   => (string) $device->imei,
            'type'       => 'audioStop',
            'attributes' => ['index' => $channel],
        ]);
    }

    public function getUrl(): string
    {
        return $this->tracker->getUrl();
    }

    /**
     * Hace una petición HTTP al API de Traccar (GET o POST).
     * Devuelve el body decodificado como array o null en caso de error.
     */
    private function traccarRequest(string $method, string $endpoint, array $body = []): ?array
    {
        $config = config('tracker');
        $url    = $this->tracker->getUrl() . ':' . $config['web.port'] . '/' . ltrim($endpoint, '/');
        $auth   = base64_encode('admin:' . config('app.admin_user'));

        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Basic ' . $auth,
                'Accept: application/json',
                'Content-Type: application/json',
            ],
        ];

        if (strtoupper($method) === 'POST') {
            $opts[CURLOPT_POST]       = true;
            $opts[CURLOPT_POSTFIELDS] = json_encode($body);
        }

        curl_setopt_array($ch, $opts);
        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code < 200 || $code >= 300 || $raw === false) {
            return null;
        }

        return json_decode($raw, true) ?? [];
    }

    /**
     * Inicia la búsqueda de archivos en el dispositivo (0x4060).
     * Devuelve ['session' => '...'] o null si el dispositivo no está conectado.
     */
    public function searchFiles(Device $device, array $params): ?array
    {
        return $this->traccarRequest('POST', 'api/howen/0/files/search?uniqueId=' . urlencode($device->imei), $params);
    }

    /**
     * Polling de resultados de búsqueda de archivos (0x1060).
     */
    public function pollFileResults(Device $device, string $session): array
    {
        $result = $this->traccarRequest('GET', 'api/howen/0/files/' . urlencode($session) . '?uniqueId=' . urlencode($device->imei));
        return $result['files'] ?? [];
    }

    /**
     * Inicia la reproducción remota de un archivo grabado (0x4070).
     */
    public function startPlayback(Device $device, array $params): ?array
    {
        return $this->traccarRequest('POST', 'api/howen/0/playback/start?uniqueId=' . urlencode($device->imei), $params);
    }

    /**
     * Detiene el stream de reproducción (reutiliza videoStop por canal).
     */
    public function stopPlayback(Device $device, int $channel = 1): void
    {
        $this->stopLive($device, $channel);
    }

    /**
     * Devuelve las posiciones GPS del dispositivo para el rango de tiempo dado.
     * $from y $to son cadenas en formato 'Y-m-d H:i:s' en la zona del usuario.
     */
    public function getRoute(Device $device, string $from, string $to): array
    {
        return $device->positions()
            ->whereBetween('time', [$from, $to])
            ->orderBy('time', 'asc')
            ->get(['latitude', 'longitude', 'time', 'speed'])
            ->map(fn($p) => [
                'lat'   => (float) $p->latitude,
                'lng'   => (float) $p->longitude,
                'time'  => $p->time,
                'speed' => (float) ($p->speed ?? 0),
            ])
            ->toArray();
    }
}
