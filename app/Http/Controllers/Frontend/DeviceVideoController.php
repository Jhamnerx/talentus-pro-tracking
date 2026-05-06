<?php

namespace App\Http\Controllers\Frontend;

use App\Http\Controllers\Controller;
use CustomFacades\Repositories\DeviceRepo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Tobuli\Services\DeviceVideoService;

class DeviceVideoController extends Controller
{
    private DeviceVideoService $videoService;

    public function __construct()
    {
        parent::__construct();
        $this->videoService = new DeviceVideoService();
    }

    private function findDevice(int $deviceId)
    {
        $device = DeviceRepo::find($deviceId);
        if (! $device) {
            abort(404);
        }
        if (! $this->user->can('show', $device)) {
            abort(403);
        }
        return $device;
    }

    public function liveStart(int $deviceId): JsonResponse
    {
        $device  = $this->findDevice($deviceId);
        $channel = (int) ($this->data['channel'] ?? 1);

        if (! $this->videoService->isSupported($device)) {
            return response()->json(['error' => 'Video not supported for this device protocol'], 422);
        }

        return response()->json($this->videoService->startLive($device, $channel));
    }

    public function liveStop(int $deviceId): JsonResponse
    {
        $device  = $this->findDevice($deviceId);
        $channel = (int) ($this->data['channel'] ?? 1);

        $this->videoService->stopLive($device, $channel);

        return response()->json(['ok' => true]);
    }

    public function liveModal(int $deviceId)
    {
        $device = $this->findDevice($deviceId);

        if (! $this->videoService->isSupported($device)) {
            abort(404, 'El protocolo del dispositivo no soporta video en vivo.');
        }

        return view('front::DeviceVideo.live_modal', compact('device'));
    }

    public function streamProxy(int $deviceId, string $file): Response
    {
        $device = $this->findDevice($deviceId);

        $config     = config('tracker');
        $traccarUrl = rtrim($this->videoService->getUrl(), '/') . ':' . $config['web.port'];

        if (str_ends_with($file, '.m3u8')) {
            $channel   = (int) (request()->query('channel', 1));
            $streamUrl = "{$traccarUrl}/api/stream/0/live.m3u8?uniqueId={$device->imei}&channel={$channel}";
        } elseif (preg_match('/^(\d+)\.ts$/', $file, $m)) {
            $channel   = (int) (request()->query('channel', 1));
            $streamUrl = "{$traccarUrl}/api/stream/0/{$m[1]}.ts?uniqueId={$device->imei}&channel={$channel}";
        } else {
            abort(400, 'Archivo no valido');
        }

        $ch = curl_init($streamUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Basic ' . base64_encode('admin:' . config('app.admin_user')),
            ],
        ]);

        $body     = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $mimeType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: 'application/octet-stream';
        curl_close($ch);

        if ($httpCode !== 200) {
            abort($httpCode ?: 502, 'Error al obtener stream de Traccar');
        }

        if (str_ends_with($file, '.m3u8')) {
            $proxyBase = url("device_video/{$deviceId}/stream/");
            $ch2       = (int) (request()->query('channel', 1));
            $body      = preg_replace('/^(\d+\.ts)$/m', $proxyBase . '$1?channel=' . $ch2, $body);
            $mimeType  = 'application/vnd.apple.mpegurl';
        }

        return response($body, 200, [
            'Content-Type'                => $mimeType,
            'Access-Control-Allow-Origin' => '*',
            'Cache-Control'               => 'no-cache, no-store, must-revalidate',
        ]);
    }
}
