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
     * Sends a videoStart command via Traccar's standard commands/send endpoint.
     * Returns the HLS proxy URL so the browser can load the stream.
     */
    public function startLive(Device $device, int $channel = 1): array
    {
        $traccarId = $this->resolveTraccarId($device);

        if ($traccarId) {
            $this->tracker->api('api/commands/send', [
                'deviceId'   => $traccarId,
                'type'       => 'videoStart',
                'attributes' => ['index' => $channel],
            ]);
        }

        return [
            'hls_url' => url("device_video/{$device->id}/stream/live.m3u8") . '?channel=' . $channel,
        ];
    }

    /**
     * Sends a videoStop command to the device.
     */
    public function stopLive(Device $device, int $channel = 1): void
    {
        $traccarId = $this->resolveTraccarId($device);

        if ($traccarId) {
            $this->tracker->api('api/commands/send', [
                'deviceId'   => $traccarId,
                'type'       => 'videoStop',
                'attributes' => ['index' => $channel],
            ]);
        }
    }

    public function getUrl(): string
    {
        return $this->tracker->getUrl();
    }

    /**
     * Resolves the real Traccar device ID from the IMEI (uniqueId).
     * Required because devices.traccar_device_id in talentus may not match tc_devices.id.
     */
    private function resolveTraccarId(Device $device): int
    {
        $curl = new \Curl;
        $curl->headers = [
            'Authorization' => 'Basic ' . base64_encode('admin:' . config('app.admin_user')),
        ];
        $curl->options['CURLOPT_RETURNTRANSFER'] = true;
        $curl->options['CURLOPT_TIMEOUT']        = 5;

        $config = config('tracker');
        $url    = $this->tracker->getUrl() . ':' . $config['web.port']
                . '/api/devices?uniqueId=' . urlencode((string) $device->imei);

        try {
            $response = $curl->get($url);
            $body     = is_object($response) ? ($response->body ?? '') : (string) $response;
            $data     = json_decode($body, true);
            return (int) ($data[0]['id'] ?? 0);
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
