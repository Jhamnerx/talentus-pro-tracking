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
     */
    public function startLive(Device $device, int $channel = 1): array
    {
        $this->tracker->api('api/commands/send', [
            'deviceId'   => 0,
            'uniqueId'   => (string) $device->imei,
            'type'       => 'videoStart',
            'attributes' => ['index' => $channel],
        ]);

        return [
            'hls_url' => url("device_video/{$device->id}/stream/live.m3u8") . '?channel=' . $channel,
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

    public function getUrl(): string
    {
        return $this->tracker->getUrl();
    }
}
