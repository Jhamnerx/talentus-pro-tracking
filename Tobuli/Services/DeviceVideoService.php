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
}
